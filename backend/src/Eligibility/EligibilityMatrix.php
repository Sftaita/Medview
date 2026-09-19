<?php

declare(strict_types=1);

namespace App\Eligibility;

use App\Entity\PlanningSnapshotMember;

/**
 * The full DutyUnit × PlanningSnapshotMember result for one
 * PlanningSnapshot (docs/eligibility.md §Matrice). Built fresh from the
 * immutable snapshot every time — never persisted (no demonstrated need
 * yet, see docs/decisions.md).
 *
 * Entries are keyed by (dutyUnit stable key, member's
 * sourceTeamMemberStableId) — both stable business identifiers, never an
 * auto-increment id — so two matrices built from the same snapshot are
 * value-equal regardless of the order rows came back from the database
 * (docs/eligibility.md §Déterminisme).
 */
final readonly class EligibilityMatrix
{
    /**
     * @param list<DutyUnit>                                  $dutyUnits
     * @param list<PlanningSnapshotMember>                    $candidates
     * @param array<string, array<string, EligibilityResult>> $entries    [dutyUnitStableKey][memberStableId] => result
     */
    public function __construct(
        private array $dutyUnits,
        private array $candidates,
        private array $entries,
    ) {
    }

    /**
     * @return list<DutyUnit>
     */
    public function getDutyUnits(): array
    {
        return $this->dutyUnits;
    }

    /**
     * @return list<PlanningSnapshotMember>
     */
    public function getCandidates(): array
    {
        return $this->candidates;
    }

    public function get(DutyUnit $dutyUnit, PlanningSnapshotMember $member): ?EligibilityResult
    {
        return $this->entries[$dutyUnit->getStableKey()][(string) $member->getSourceTeamMemberStableId()] ?? null;
    }

    /**
     * @return array<string, EligibilityResult> keyed by member's sourceTeamMemberStableId
     */
    public function getForDutyUnit(DutyUnit $dutyUnit): array
    {
        return $this->entries[$dutyUnit->getStableKey()] ?? [];
    }
}
