<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityCollection;
use App\Entity\AvailabilityCollectionResponse;
use App\Entity\AvailabilityResponseStatus;
use App\Entity\Planning;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use App\Entity\UserAvailabilityType;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\AvailabilityCollectionResponseRepository;
use App\Repository\PlanningAvailabilityReminderRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\TeamMemberNonParticipationPeriodRepository;
use App\Repository\TeamMemberParticipationPeriodRepository;
use App\Repository\UserAvailabilityPeriodRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * The OWNER/ADMIN view of a planning's availability collection
 * (docs/availability-collection.md §15, docs/decisions.md D127-D128).
 *
 * Pure read side: it aggregates what already exists — the
 * AvailabilityCollectionResponse rows for "who confirmed", the personal
 * UserAvailabilityPeriod calendar for "what they declared" — and never
 * writes. It introduces no state of its own: a member is CONFIRMED only if
 * an explicit answer exists (absence of unavailabilities proves nothing,
 * D120), and unavailabilities are read from the person's calendar limited to
 * the planning period, never copied into a planning-owned table.
 *
 * "Relevant" collections: the OPEN ones — those still waiting for answers.
 * When none is open (all closed) the closed ones are used, so a finished
 * collection keeps reading as it ended instead of showing nobody.
 */
final class PlanningCollectionStatusService
{
    public function __construct(
        private readonly AvailabilityCollectionRepository $collectionRepository,
        private readonly AvailabilityCollectionResponseRepository $responseRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly UserAvailabilityPeriodRepository $availabilityPeriodRepository,
        private readonly TeamMemberParticipationPeriodRepository $participationPeriodRepository,
        private readonly TeamMemberNonParticipationPeriodRepository $nonParticipationPeriodRepository,
        private readonly PlanningAvailabilityReminderRepository $reminderRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function status(Planning $planning): PlanningCollectionStatus
    {
        [$relevant, $open] = $this->relevantCollections($planning);

        $responsesByUser = $this->responsesByUser($relevant);
        $lastReminders = $this->reminderRepository->lastSentAtByRecipient($planning);

        $rows = [];
        foreach ($this->participants($planning) as $member) {
            $rows[] = $this->buildRow($planning, $member, $responsesByUser[$member->getUser()->getId()] ?? [], $lastReminders);
        }

        $deadline = $this->latestDeadline($open);

        return new PlanningCollectionStatus($planning, $deadline, $this->overdueDays($planning, $deadline), \count($open), $rows);
    }

    public function detail(Planning $planning, PlanningTeamMember $member): MemberAvailabilityDetail
    {
        [$relevant] = $this->relevantCollections($planning);

        $user = $member->getUser();
        $responses = $this->responsesByUser($relevant)[$user->getId()] ?? [];
        $lastReminders = $this->reminderRepository->lastSentAtByRecipient($planning);
        $row = $this->buildRow($planning, $member, $responses, $lastReminders);

        [$from, $to] = $this->periodInstants($planning);
        $unavailabilities = [];
        $preferences = [];
        foreach ($this->availabilityPeriodRepository->findIntersecting($user, $from, $to) as $period) {
            if (UserAvailabilityType::UNAVAILABLE === $period->getType()) {
                $unavailabilities[] = $period;
            } else {
                $preferences[] = $period;
            }
        }

        return new MemberAvailabilityDetail(
            $row,
            $responses,
            $unavailabilities,
            $preferences,
            $this->participationPeriodRepository->findIntersecting($member, $planning->getStartsAt(), $planning->getEndsAt()),
            $this->nonParticipationPeriodRepository->findIntersecting($member, $from, $to),
            $this->reminderRepository->findByRecipient($planning, $user),
        );
    }

    /**
     * Latest deadline among the open collections — the date by which *every*
     * open window is expected to be answered. Informative only.
     *
     * @param list<AvailabilityCollection> $open
     */
    private function latestDeadline(array $open): ?\DateTimeImmutable
    {
        $deadline = null;
        foreach ($open as $collection) {
            $candidate = $collection->getDeadline();
            if (null !== $candidate && (null === $deadline || $candidate > $deadline)) {
                $deadline = $candidate;
            }
        }

        return $deadline;
    }

    /**
     * Whole days since $deadline in the planning's timezone; null when there
     * is no deadline or it is today or later.
     */
    private function overdueDays(Planning $planning, ?\DateTimeImmutable $deadline): ?int
    {
        if (null === $deadline) {
            return null;
        }

        $today = new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($planning->getTimezone()))->format('Y-m-d'));
        $days = (int) $deadline->diff($today)->format('%r%a');

        return $days > 0 ? $days : null;
    }

    /**
     * One participant per person: a user who left and came back appears once,
     * with their open stint if they have one, otherwise the latest.
     *
     * @return list<PlanningTeamMember>
     */
    private function participants(Planning $planning): array
    {
        $byUser = [];
        foreach ($this->teamMemberRepository->findIntersectingForPlanning($planning, $planning->getStartsAt(), $planning->getEndsAt()) as $member) {
            $key = $member->getUser()->getId();
            if (!isset($byUser[$key]) || $this->isPreferredStint($member, $byUser[$key])) {
                $byUser[$key] = $member;
            }
        }

        $members = array_values($byUser);
        usort($members, static fn (PlanningTeamMember $a, PlanningTeamMember $b): int => [$a->getUser()->getLastName(), $a->getUser()->getFirstName(), $a->getId()] <=> [$b->getUser()->getLastName(), $b->getUser()->getFirstName(), $b->getId()]);

        return $members;
    }

    /**
     * @return array{0: list<AvailabilityCollection>, 1: list<AvailabilityCollection>} [relevant, open]
     */
    private function relevantCollections(Planning $planning): array
    {
        $collections = $this->collectionRepository->findByPlanning($planning);
        $open = array_values(array_filter($collections, static fn (AvailabilityCollection $c): bool => $c->isOpen()));

        return [[] !== $open ? $open : $collections, $open];
    }

    /** An open stint beats an ended one; between two of the same kind, the later one wins. */
    private function isPreferredStint(PlanningTeamMember $candidate, PlanningTeamMember $current): bool
    {
        $candidateOpen = null === $candidate->getMembershipEnd();
        $currentOpen = null === $current->getMembershipEnd();
        if ($candidateOpen !== $currentOpen) {
            return $candidateOpen;
        }

        return $candidate->getMembershipStart() > $current->getMembershipStart();
    }

    /**
     * @param list<AvailabilityCollection> $collections
     *
     * @return array<int, list<AvailabilityCollectionResponse>> keyed by user id
     */
    private function responsesByUser(array $collections): array
    {
        $byUser = [];
        foreach ($collections as $collection) {
            foreach ($this->responseRepository->findByCollection($collection) as $response) {
                $byUser[$response->getUser()->getId()][] = $response;
            }
        }

        return $byUser;
    }

    /**
     * @param list<AvailabilityCollectionResponse> $responses
     * @param array<int, \DateTimeImmutable>       $lastReminders
     */
    private function buildRow(Planning $planning, PlanningTeamMember $member, array $responses, array $lastReminders): MemberCollectionRow
    {
        $user = $member->getUser();
        $expected = array_values(array_filter($responses, static fn (AvailabilityCollectionResponse $r): bool => AvailabilityResponseStatus::WITHDRAWN !== $r->getStatus()));
        $pending = array_filter($expected, static fn (AvailabilityCollectionResponse $r): bool => AvailabilityResponseStatus::PENDING === $r->getStatus());

        $state = match (true) {
            [] === $expected || !$user->isActive() => MemberCollectionState::NOT_EXPECTED,
            [] !== $pending => MemberCollectionState::PENDING,
            default => MemberCollectionState::ACKNOWLEDGED,
        };

        $latest = null;
        $lastChange = null;
        foreach ($expected as $response) {
            $acknowledgedAt = $response->getAcknowledgedAt();
            if (null !== $acknowledgedAt && (null === $latest || $acknowledgedAt > $latest->getAcknowledgedAt())) {
                $latest = $response;
            }
            $change = $response->getLastAvailabilityChangeAt();
            if (null !== $change && (null === $lastChange || $change > $lastChange)) {
                $lastChange = $change;
            }
        }

        return new MemberCollectionRow(
            $member,
            $state,
            $latest?->getAcknowledgedAt(),
            $latest?->getAcknowledgementKind(),
            $lastChange,
            $this->countUnavailabilities($planning, $user),
            $lastReminders[$user->getId()] ?? null,
            \count($pending),
        );
    }

    private function countUnavailabilities(Planning $planning, User $user): int
    {
        [$from, $to] = $this->periodInstants($planning);

        return \count(array_filter(
            $this->availabilityPeriodRepository->findIntersecting($user, $from, $to),
            static fn ($period): bool => UserAvailabilityType::UNAVAILABLE === $period->getType(),
        ));
    }

    /**
     * The planning's [startsAt, endsAt) dates as instants in its own timezone —
     * the same conversion PlanningSnapshotService uses to pick a member's
     * availability, so this view and a snapshot always agree.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function periodInstants(Planning $planning): array
    {
        $timezone = new \DateTimeZone($planning->getTimezone());

        return [
            new \DateTimeImmutable($planning->getStartsAt()->format('Y-m-d').' 00:00:00', $timezone),
            new \DateTimeImmutable($planning->getEndsAt()->format('Y-m-d').' 00:00:00', $timezone),
        ];
    }
}
