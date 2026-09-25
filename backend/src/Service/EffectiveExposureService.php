<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\DutyUnit;
use App\Eligibility\EligibilityMatrix;
use App\Entity\Duty;
use App\Entity\PlanningSnapshotMember;
use App\Fairness\FairnessCandidate;
use App\Fairness\FairnessDimensionKey;
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
 *
 * ALLOCATION_FAMILY exposure (docs/decisions.md D136) is computed
 * separately, once per *unit* rather than folded into the per-Duty loop
 * above: `dimensionWeight(duty, ALLOCATION_FAMILY)` is not really a
 * per-Duty weight, it is "does this unit belong to this family", so a
 * candidate exposed to a 3-day WEEKEND block gets
 * `effectiveExposure(WEEKEND) += structuralOpportunity × participationFactor`
 * once, never three times (the same Scenario F guarantee
 * `RequiredDemandBuilder` upholds on the demand side). The unit's anchor
 * Duty (its first constituent, by construction always the earliest
 * dayOffset — DutyMaterializationService::materializeGroup() persists
 * components in pattern order) supplies the one participationFactor
 * evaluation date; a mid-block participation change is the same rare edge
 * case the calendar dimensions already accept evaluating per-Duty instead.
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
            $result[$candidate->sourceUserStableId] = $this->buildForCandidate($matrix, $unitByDutyStableId, $dutyUnits, $candidate);
        }

        return $result;
    }

    /**
     * @param array<string, DutyUnit> $unitByDutyStableId
     * @param list<DutyUnit>          $dutyUnits
     */
    private function buildForCandidate(EligibilityMatrix $matrix, array $unitByDutyStableId, array $dutyUnits, FairnessCandidate $candidate): FairnessDimensionValues
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

        foreach ($dutyUnits as $unit) {
            $family = $unit->getDuties()[0]->getAllocationFamily();
            if (null === $family) {
                continue;
            }

            $anchorDuty = $unit->getDuties()[0];
            $stint = $candidate->stintCovering($anchorDuty->getLocalDate());
            if (null === $stint) {
                continue;
            }

            $result = $matrix->get($unit, $stint);
            if (null === $result || !$result->structuralOpportunity) {
                continue;
            }

            $factor = $this->participationFactorAt($stint, $anchorDuty->getLocalDate());
            $total = $total->withAdded(FairnessDimensionKey::allocationFamily((string) $family->getStableId()), $factor);
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
