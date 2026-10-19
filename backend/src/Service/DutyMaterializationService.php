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
use App\Exception\InvalidConditionalDutyException;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningLineRepository;
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
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningLineDemandPolicyRepository $policyRepository,
    ) {
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
        ?Duty $coverageSource = null,
    ): Duty {
        $this->assertMatchesDemandPolicy($planningPeriod, $demandType, null === $coverageSource ? [] : [$coverageSource]);
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
            $coverageSource,
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
        array $coverageSourcesByOffset = [],
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

        if ([] !== $coverageSourcesByOffset) {
            $sourceOffsets = array_keys($coverageSourcesByOffset);
            sort($sourceOffsets);
            if ($sourceOffsets !== $patternOffsets) {
                throw new InvalidConditionalDutyException('A conditional block needs exactly one coverage source per day of the block.');
            }
        }

        return $this->buildGroup($planningPeriod, $pattern, $anchorDate, $componentLocalTimes, $demandType, $criticality, $coverageSourcesByOffset);
    }

    /**
     * The one exception to the atomicity of a block (docs/decisions.md D192):
     * the days of an occurrence that fall in a period whose start is a
     * HISTORICAL boundary — the end of a locked previous period, fixed before
     * lot L3, that cuts the occurrence, whose earlier days were never
     * materialized (PeriodBoundaryGuard::allowsRemainder(), checked by the
     * caller). Those days become one DutyGroupInstance of the same pattern
     * (same family, same duty types, one holder): a block identifiable as a
     * remainder by having fewer duties than its pattern has components
     * (DutyGroupInstance::isRemainder()). Never anything in the previous
     * period.
     *
     * @param array<int, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $componentLocalTimes     keyed by dayOffset: a
     *                                                                                                 strict, non-empty subset of the pattern's offsets
     * @param array<int, Duty>                                                $coverageSourcesByOffset one per day given, or none
     *
     * @throws DutyPatternMismatchException
     */
    public function materializeBlockRemainder(
        PlanningPeriod $planningPeriod,
        DutyPattern $pattern,
        \DateTimeImmutable $anchorDate,
        array $componentLocalTimes,
        DutyDemandType $demandType = DutyDemandType::REQUIRED,
        array $coverageSourcesByOffset = [],
    ): DutyGroupInstance {
        $patternOffsets = array_map(static fn ($component): int => $component->getDayOffset(), $pattern->getComponents()->toArray());
        $providedOffsets = array_keys($componentLocalTimes);
        if ([] === $providedOffsets || [] !== array_diff($providedOffsets, $patternOffsets) || \count($providedOffsets) >= \count($patternOffsets)) {
            throw new DutyPatternMismatchException(sprintf('A block remainder takes a strict, non-empty part of the offsets of its pattern (pattern [%s], got [%s]).', implode(',', $patternOffsets), implode(',', $providedOffsets)));
        }
        if ([] !== $coverageSourcesByOffset) {
            $sourceOffsets = array_keys($coverageSourcesByOffset);
            sort($sourceOffsets);
            sort($providedOffsets);
            if ($sourceOffsets !== $providedOffsets) {
                throw new InvalidConditionalDutyException('A conditional block needs exactly one coverage source per day of the block.');
            }
        }

        return $this->buildGroup($planningPeriod, $pattern, $anchorDate, $componentLocalTimes, $demandType, DutyCriticality::STANDARD, $coverageSourcesByOffset);
    }

    /**
     * @param array<int, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $componentLocalTimes     keyed by dayOffset
     * @param array<int, Duty>                                                $coverageSourcesByOffset
     */
    private function buildGroup(
        PlanningPeriod $planningPeriod,
        DutyPattern $pattern,
        \DateTimeImmutable $anchorDate,
        array $componentLocalTimes,
        DutyDemandType $demandType,
        DutyCriticality $criticality,
        array $coverageSourcesByOffset,
    ): DutyGroupInstance {
        $this->assertMatchesDemandPolicy($planningPeriod, $demandType, array_values($coverageSourcesByOffset));

        $timezone = $planningPeriod->getTeam()->getTimezone();
        $groupInstance = new DutyGroupInstance($planningPeriod, $pattern, $anchorDate);
        $this->entityManager->persist($groupInstance);

        foreach ($pattern->getComponents() as $component) {
            $offset = $component->getDayOffset();
            if (!isset($componentLocalTimes[$offset])) {
                continue; // a remainder: only its own days
            }
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
                null,
                $coverageSourcesByOffset[$offset] ?? null,
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
    /**
     * docs/decisions.md D163 — the rules only the line's demand policy
     * knows (the Duty constructor already guarantees same Planning, another
     * line, same day, source not conditional):
     *
     * - on a conditional line, every duty is CONDITIONAL and each coverage
     *   source is a duty of the policy's source line;
     * - on any other line (or a period with no line at all), never a
     *   CONDITIONAL duty.
     *
     * @param list<Duty> $coverageSources
     */
    private function assertMatchesDemandPolicy(PlanningPeriod $planningPeriod, DutyDemandType $demandType, array $coverageSources): void
    {
        $line = $this->lineRepository->findOneByPlanningPeriod($planningPeriod);
        $policy = null !== $line ? $this->policyRepository->findActiveForLine($line) : null;
        $sourceLine = null !== $policy && $policy->getMode()->isConditional() ? $policy->getSourceLine() : null;

        if (null === $sourceLine) {
            if (DutyDemandType::CONDITIONAL === $demandType) {
                throw new InvalidConditionalDutyException('A CONDITIONAL duty can only be created on a conditional line.');
            }

            return;
        }

        if (DutyDemandType::CONDITIONAL !== $demandType) {
            throw new InvalidConditionalDutyException('Every duty of a conditional line is CONDITIONAL: its demand depends on the source line.');
        }
        // docs/planning-cycles.md §5.6: a coverage source is a duty of the source line's period in the SAME cycle.
        $cycle = $planningPeriod->getCycle();
        $sourcePeriod = null !== $cycle ? $sourceLine->periodIn($cycle) : null;
        foreach ($coverageSources as $source) {
            if (null === $sourcePeriod || $source->getPlanningPeriod() !== $sourcePeriod) {
                throw new InvalidConditionalDutyException('A coverage source must be a duty of the source line of this line.');
            }
        }
    }

    private function resolveInstant(\DateTimeImmutable $wallClock, string $timezone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($wallClock->format('Y-m-d H:i:s'), new \DateTimeZone($timezone));
    }
}
