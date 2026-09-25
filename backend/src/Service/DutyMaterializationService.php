<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyCriticality;
use App\Entity\DutyDemandType;
use App\Entity\DutyGroupInstance;
use App\Entity\DutyPattern;
use App\Entity\DutyType;
use App\Entity\PlanningPeriod;
use App\Exception\DutyPatternMismatchException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates Duty (and DutyGroupInstance) rows. Deliberately narrow in this
 * lot: it resolves wall-clock times into the correct absolute instants
 * (docs/allocation-algorithm.md §Duty) and enforces pattern/group
 * consistency, but does not yet decide *which* duties a PlanningPeriod
 * needs — that depends on RuleSet interpretation and calendar generation
 * logic that belongs to a later lot.
 *
 * A Duty, once created, keeps its $stableId forever — this service is
 * never called again for the same logical duty across regenerations of a
 * PlanningPeriod (docs/allocation-algorithm.md §13).
 */
final class DutyMaterializationService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param \DateTimeImmutable $localStartsAt wall-clock time, interpreted
     *                                          in the PlanningPeriod's Team timezone
     * @param \DateTimeImmutable $localEndsAt   wall-clock time, same timezone
     * @param ?DutyPattern       $pattern       the one-component DutyPattern this solo duty was
     *                                          materialized from (docs/decisions.md D136), so it can carry
     *                                          an ALLOCATION_FAMILY — optional and defaulting to null so
     *                                          every pre-D136 caller (and every test with no notion of a
     *                                          weekly structure) keeps working unchanged
     */
    public function createStandaloneDuty(
        PlanningPeriod $planningPeriod,
        DutyType $dutyType,
        \DateTimeImmutable $localStartsAt,
        \DateTimeImmutable $localEndsAt,
        DutyDemandType $demandType = DutyDemandType::REQUIRED,
        DutyCriticality $criticality = DutyCriticality::STANDARD,
        ?DutyPattern $pattern = null,
    ): Duty {
        $timezone = $planningPeriod->getTeam()->getTimezone();

        $duty = new Duty(
            $planningPeriod,
            $dutyType,
            $this->resolveInstant($localStartsAt, $timezone),
            $this->resolveInstant($localEndsAt, $timezone),
            $timezone,
            $demandType,
            $criticality,
            null,
            $pattern,
        );

        $this->entityManager->persist($duty);
        $this->entityManager->flush();

        return $duty;
    }

    /**
     * Materializes a DutyGroupInstance and exactly the Duty rows its
     * DutyPattern defines — never a partial subset (docs/allocation-algorithm.md
     * §9/§14: a group is an atomic unit).
     *
     * @param array<int, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $componentLocalTimes
     *                                                                                             keyed by the pattern's dayOffset, each a [localStartsAt, localEndsAt]
     *                                                                                             wall-clock pair; must cover exactly the pattern's own offsets
     *
     * @throws DutyPatternMismatchException if $componentLocalTimes does not
     *                                      cover exactly the pattern's offsets
     */
    public function materializeGroup(
        PlanningPeriod $planningPeriod,
        DutyPattern $pattern,
        \DateTimeImmutable $anchorDate,
        array $componentLocalTimes,
        DutyDemandType $demandType = DutyDemandType::REQUIRED,
        DutyCriticality $criticality = DutyCriticality::STANDARD,
    ): DutyGroupInstance {
        $components = $pattern->getComponents();

        if (0 === \count($components)) {
            throw new \LogicException('Cannot materialize a DutyGroupInstance from a DutyPattern with no components.');
        }

        $patternOffsets = [];
        foreach ($components as $component) {
            $patternOffsets[] = $component->getDayOffset();
        }
        sort($patternOffsets);

        $providedOffsets = array_keys($componentLocalTimes);
        sort($providedOffsets);

        if ($patternOffsets !== $providedOffsets) {
            throw new DutyPatternMismatchException(sprintf('componentLocalTimes must provide exactly the offsets defined by the pattern (expected [%s], got [%s]).', implode(',', $patternOffsets), implode(',', $providedOffsets)));
        }

        $timezone = $planningPeriod->getTeam()->getTimezone();
        $groupInstance = new DutyGroupInstance($planningPeriod, $pattern, $anchorDate);
        $this->entityManager->persist($groupInstance);

        foreach ($components as $component) {
            $offset = $component->getDayOffset();
            [$localStartsAt, $localEndsAt] = $componentLocalTimes[$offset];

            $duty = new Duty(
                $planningPeriod,
                $component->getDutyType(),
                $this->resolveInstant($localStartsAt, $timezone),
                $this->resolveInstant($localEndsAt, $timezone),
                $timezone,
                $demandType,
                $criticality,
                $groupInstance,
            );
            $this->entityManager->persist($duty);
        }

        $this->entityManager->flush();

        return $groupInstance;
    }

    /**
     * Resolves a wall-clock date+time into the correct absolute instant
     * for $timezone, DST included — constructing a new DateTimeImmutable
     * from the formatted wall-clock string with an explicit DateTimeZone
     * lets PHP apply that zone's own DST rules for that specific date.
     */
    private function resolveInstant(\DateTimeImmutable $wallClock, string $timezone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($wallClock->format('Y-m-d H:i:s'), new \DateTimeZone($timezone));
    }
}
