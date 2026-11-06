<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\ExclusionReason;
use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningTeamMember;
use App\Repository\PlanningTeamMemberRepository;

/**
 * Why a post is still empty (docs/decisions.md D232), with the very check
 * behind the candidate list and the save
 * (ReassignmentCandidateService::assignabilityError). Shared by the
 * publication preflight and the preview of « Compléter la période »
 * (docs/decisions.md D234), so the calendar, the dialogs and the main action
 * can never disagree on whether a post can be filled.
 */
final class VacancyAnalyzer
{
    public function __construct(
        private readonly ReassignmentCandidateService $candidateService,
        private readonly ExclusionReasonLabeler $reasonLabeler,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
    ) {
    }

    /**
     * @param non-empty-list<Duty>       $duties          the uncovered duties of ONE unit (a block once)
     * @param array<int, DutyAssignment> $currentByDutyId the current assignments of $generation, keyed by duty id
     */
    public function vacancyOf(PlanningGeneration $generation, array $duties, array $currentByDutyId): PublicationVacancy
    {
        $block = $this->candidateService->blockDuties($duties[0]);
        $members = $this->teamMemberRepository->findByTeam($duties[0]->getTeam());

        $onDates = array_values(array_filter($members, static function (PlanningTeamMember $member) use ($duties): bool {
            foreach ($duties as $duty) {
                if (!$member->isActiveAt($duty->getLocalDate())) {
                    return false;
                }
            }

            return true;
        }));

        if ([] === $onDates) {
            $lastDay = max(array_map(static fn (Duty $duty): \DateTimeImmutable => $duty->getLocalDate(), $duties));
            $later = array_values(array_filter(
                array_map(static fn (PlanningTeamMember $member): \DateTimeImmutable => $member->getMembershipStart(), $members),
                static fn (\DateTimeImmutable $start): bool => $start > $lastDay,
            ));

            return new PublicationVacancy(PublicationVacancy::NO_MEMBER_ON_DATE, \count($members), 0, [] !== $later ? min($later) : null);
        }

        $assignable = 0;
        $reasons = [];
        foreach ($onDates as $member) {
            $error = $this->candidateService->assignabilityError($generation, $block, $member, [], ReassignmentCandidateService::heldBy($block, $currentByDutyId, $member));
            if (null === $error) {
                ++$assignable;
                continue;
            }
            $reasons[$error] = ($reasons[$error] ?? 0) + 1;
        }

        return new PublicationVacancy(
            $assignable > 0 ? PublicationVacancy::ASSIGNABLE_MEMBERS : PublicationVacancy::NO_ELIGIBLE_MEMBER,
            \count($members),
            $assignable,
            null,
            array_map(fn (string $code, int $count): array => ['code' => $code, 'label' => $this->vacancyLabel($code), 'count' => $count], array_keys($reasons), array_values($reasons)),
        );
    }

    private function vacancyLabel(string $code): string
    {
        $reason = ExclusionReason::tryFrom($code);

        return null !== $reason ? $this->reasonLabeler->label($reason) : 'ne fait pas partie de cette génération';
    }
}
