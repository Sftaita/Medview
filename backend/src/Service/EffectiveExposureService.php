<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\DutyUnit;
use App\Eligibility\EligibilityMatrix;
use App\Entity\Duty;
use App\Entity\PlanningSnapshotMember;
use App\Fairness\FairnessCandidate;
use App\Fairness\FairnessDimensionValues;

/**
 * effectiveExposure(user, dimension) = Σ [structuralOpportunity(user, duty)
 * × participationFactor(user, duty.localDate) × dimensionWeight(duty,
 * dimension)] (docs/allocation-algorithm.md §5, docs/fairness.md
 * §EffectiveExposure).
 *
 * Summed over **every** Duty of the PlanningPeriod, REQUIRED and OPTIONAL
 * alike (docs/decisions.md D084) — the formula in §5 carries no demandType
 * filter, unlike requiredDemand (RequiredDemandBuilder), and
 * structuralOpportunity's own definition is "candidat à duty", not
 * "candidat à une duty requise": exposure measures a person's structural
 * presence in the roster, not the current REQUIRED/OPTIONAL staffing
 * classification.
 *
 * `structuralOpportunity` is read from the already-built EligibilityMatrix
 * — never recomputed here — at the (DutyUnit, stint) granularity the
 * matrix actually uses (EligibilityService evaluates a whole DutyUnit at
 * once, docs/eligibility.md §6): every Duty inside a grouped unit inherits
 * that unit's single boolean, exactly as docs/allocation-algorithm.md §9
 * requires ("chaque dimension créditée par la date réelle de chaque garde
 * constituante" — atomic for eligibility, analytically distinct per Duty).
 *
 * `participationFactor` is read from the frozen snapshot alone
 * (PlanningSnapshotParticipationPeriod::covers()) — never live state
 * (docs/planning-generation.md).
 */
final class EffectiveExposureService
{
    public function __construct(private readonly DimensionMembershipCalculator $dimensionMembershipCalculator)
    {
    }

    /**
     * @param list<DutyUnit>          $dutyUnits  every DutyUnit (required + optional) of the PlanningPeriod
     * @param list<FairnessCandidate> $candidates
     *
     * @return array<string, FairnessDimensionValues> keyed by sourceUserStableId
     */
    public function build(EligibilityMatrix $matrix, array $dutyUnits, array $candidates): array
    {
        $unitByDutyStableId = $this->indexUnitsByDuty($dutyUnits);

        $result = [];
        foreach ($candidates as $candidate) {
            $result[$candidate->sourceUserStableId] = $this->buildForCandidate($matrix, $unitByDutyStableId, $candidate);
        }

        return $result;
    }

    /**
     * @param array<string, DutyUnit> $unitByDutyStableId
     */
    private function buildForCandidate(EligibilityMatrix $matrix, array $unitByDutyStableId, FairnessCandidate $candidate): FairnessDimensionValues
    {
        $total = FairnessDimensionValues::empty();

        foreach ($unitByDutyStableId as $dutyStableId => $unit) {
            $duty = $this->findDuty($unit, $dutyStableId);

            $stint = $candidate->stintCovering($duty->getLocalDate());
            if (null === $stint) {
                // No stint of this User covers this Duty's date: not a
                // member here at all — structurally equivalent to the
                // MEMBERSHIP_OUT_OF_RANGE any other stint would report for
                // this date, so structuralOpportunity is 0 without needing
                // a matrix lookup that has no stint to key on.
                continue;
            }

            $result = $matrix->get($unit, $stint);
            if (null === $result || !$result->structuralOpportunity) {
                continue;
            }

            $factor = $this->participationFactorAt($stint, $duty->getLocalDate());
            $total = $total->plus($this->dimensionMembershipCalculator->forDuty($duty)->scaledBy($factor));
        }

        return $total;
    }

    /**
     * @param list<DutyUnit> $dutyUnits
     *
     * @return array<string, DutyUnit> keyed by each constituent Duty's stableId
     */
    private function indexUnitsByDuty(array $dutyUnits): array
    {
        $index = [];
        foreach ($dutyUnits as $unit) {
            foreach ($unit->getDuties() as $duty) {
                $index[(string) $duty->getStableId()] = $unit;
            }
        }

        return $index;
    }

    private function findDuty(DutyUnit $unit, string $dutyStableId): Duty
    {
        foreach ($unit->getDuties() as $duty) {
            if ((string) $duty->getStableId() === $dutyStableId) {
                return $duty;
            }
        }

        throw new \LogicException(sprintf('Duty %s was indexed under a DutyUnit that no longer contains it.', $dutyStableId));
    }

    private function participationFactorAt(PlanningSnapshotMember $stint, \DateTimeImmutable $localDate): float
    {
        foreach ($stint->getParticipationPeriods() as $period) {
            if ($period->covers($localDate)) {
                return $period->toFloat();
            }
        }

        // PlanningTeamMembershipService::addMember() always opens an
        // initial participation period covering membershipStart onward
        // (docs/planning-domain.md §5) — a stint covering this date with no
        // participation period covering it would mean the snapshot itself
        // was built from an inconsistent live state, a bug elsewhere, never
        // silently treated as factor 0 here.
        throw new \LogicException('A PlanningSnapshotMember stint covering a Duty date must always have a PlanningSnapshotParticipationPeriod covering that same date.');
    }
}
