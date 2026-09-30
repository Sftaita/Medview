<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningPublication;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningPublicationEntryRepository;
use App\Repository\PlanningTeamMemberRepository;

/**
 * "What changed since the last diffusion, and who must be told"
 * (docs/decisions.md D143, D172).
 *
 * Changes: the current calendar compared duty by duty with the latest
 * PlanningPublication's frozen entries — by holder, never by
 * DutyAssignment row, so A → B → A is no change at all, and a block
 * changes on each of its days at once (its duties always move together).
 * The holder is compared by durable identity, the User — never the
 * PlanningTeamMember row, which changes when someone leaves and rejoins a
 * team while nothing changes for anyone.
 *
 * Audience of a republication (D172, supersedes D143's "everyone on duty
 * on an impacted date"): only the people whose OWN duties changed — the
 * previous holder of a changed duty (a removal) and its new holder (an
 * addition), grouped per person, one email each, with their changes only.
 * Deactivated accounts are never emailed.
 */
final class PublicationChangeService
{
    public const ADDED = 'added';
    public const REMOVED = 'removed';

    public function __construct(
        private readonly PlanningPublicationEntryRepository $entryRepository,
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
    ) {
    }

    /**
     * @param list<CalendarCell> $cells the current calendar (CurrentCalendarReader)
     *
     * @return list<PublicationChange> chronological
     */
    public function changesSince(PlanningPublication $reference, array $cells): array
    {
        $publishedByDutyId = [];
        foreach ($this->entryRepository->findByPublication($reference) as $entry) {
            $publishedByDutyId[(int) $entry->getDuty()->getId()] = $entry->getTeamMember();
        }

        $changes = [];
        foreach ($cells as $cell) {
            // A duty the reference never saw (a line added since) was never announced: it reads as "uncovered" before.
            // D166: except a conditional duty, which is absent from a publication only when it was not required then.
            $recorded = \array_key_exists((int) $cell->duty->getId(), $publishedByDutyId);
            $before = $publishedByDutyId[(int) $cell->duty->getId()] ?? null;
            if ($before?->getUser()->getId() !== $cell->member?->getUser()->getId()) {
                $changes[] = new PublicationChange($cell->line, $cell->duty, $before, $cell->member, $recorded || !$cell->duty->isConditional(), $cell->isShown());
            }
        }

        usort($changes, static fn (PublicationChange $a, PublicationChange $b): int => [$a->duty->getStartsAt(), $a->line->getPosition()] <=> [$b->duty->getStartsAt(), $b->line->getPosition()]);

        return $changes;
    }

    /**
     * Each concerned person's own changes (docs/decisions.md D172): the
     * previous holder of a changed duty loses it (REMOVED), its new holder
     * gains it (ADDED) — A → B tells A of the removal and B of the addition,
     * nothing else. A block is one unit carrying every one of its dates
     * (its days always move together); a solo duty is one unit. What this
     * returns is exactly what each email says — frozen by the caller with
     * the publication, never re-derived when an email is retried.
     *
     * Nothing about anybody else's duties is ever part of a person's
     * changes, and nothing personal beyond the duties themselves (no
     * unavailability) is ever read here.
     *
     * @param list<PublicationChange> $changes changesSince()
     *
     * @return list<array{user: User, changes: list<array{kind: string, line: string, dutyType: ?string, block: ?string, dates: list<string>}>}> sorted by name; units chronological
     */
    public function personalChanges(array $changes): array
    {
        /** @var array<int, User> $users */
        $users = [];
        /** @var array<int, array<string, array{kind: string, line: string, dutyType: ?string, block: ?string, dates: list<string>, sort: array{0: \DateTimeImmutable, 1: int, 2: int}}>> $units */
        $units = [];
        foreach ($changes as $change) {
            $before = $change->before?->getUser();
            $after = $change->after?->getUser();
            if ($before?->getId() === $after?->getId()) {
                continue; // the same person, through another membership: nothing changed for anyone
            }

            foreach ([self::REMOVED => $before, self::ADDED => $after] as $kind => $user) {
                if (null === $user || !$user->isActive()) {
                    continue;
                }
                $userId = (int) $user->getId();
                $users[$userId] = $user;

                $group = $change->duty->getGroupInstance();
                $key = $kind.'|'.(null !== $group ? 'g'.$group->getId() : 'd'.$change->duty->getId());
                $units[$userId][$key] ??= [
                    'kind' => $kind,
                    'line' => $change->line->getName(),
                    // A block reads as its name; a solo duty as its type (a line may hold a day and a night duty).
                    'dutyType' => null === $group ? $change->duty->getDutyType()->getName() : null,
                    'block' => $group?->getPattern()->getName(),
                    'dates' => [],
                    'sort' => [$change->duty->getStartsAt(), $change->line->getPosition(), self::REMOVED === $kind ? 0 : 1],
                ];
                $units[$userId][$key]['dates'][] = $change->duty->getLocalDate()->format('Y-m-d');
                if ($change->duty->getStartsAt() < $units[$userId][$key]['sort'][0]) {
                    $units[$userId][$key]['sort'][0] = $change->duty->getStartsAt();
                }
            }
        }

        $result = [];
        foreach ($this->sortedByName($users) as $user) {
            $userUnits = array_values($units[(int) $user->getId()]);
            usort($userUnits, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
            $result[] = [
                'user' => $user,
                'changes' => array_map(static function (array $unit): array {
                    $dates = array_values(array_unique($unit['dates']));
                    sort($dates);

                    return ['kind' => $unit['kind'], 'line' => $unit['line'], 'dutyType' => $unit['dutyType'], 'block' => $unit['block'], 'dates' => $dates];
                }, $userUnits),
            ];
        }

        return $result;
    }

    /**
     * First publication: every participant of the planning — anyone whose
     * membership in an active line's team intersects the planning's range —
     * whether or not they hold a duty (the planning concerns them all).
     *
     * @return list<User>
     */
    public function audienceForFirstPublication(Planning $planning): array
    {
        $activeTeamIds = [];
        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            if ($line->isActive()) {
                $activeTeamIds[(int) $line->getPlanningTeam()->getId()] = true;
            }
        }

        $members = array_filter(
            $this->teamMemberRepository->findIntersectingForPlanning($planning, $planning->getStartsAt(), $planning->getEndsAt()),
            static fn (PlanningTeamMember $member): bool => isset($activeTeamIds[(int) $member->getPlanningTeam()->getId()]),
        );

        $users = [];
        foreach ($members as $member) {
            $user = $member->getUser();
            if ($user->isActive()) {
                $users[(int) $user->getId()] = $user;
            }
        }

        return $this->sortedByName($users);
    }

    /**
     * @param array<int, User> $users
     *
     * @return list<User>
     */
    private function sortedByName(array $users): array
    {
        $users = array_values($users);
        usort($users, static fn (User $a, User $b): int => [$a->getLastName(), $a->getFirstName(), $a->getEmail()] <=> [$b->getLastName(), $b->getFirstName(), $b->getEmail()]);

        return $users;
    }
}
