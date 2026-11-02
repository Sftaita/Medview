<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\DemandRules;
use App\Demand\DemandTriggerEvaluator;
use App\Demand\Weekday;
use App\Entity\AllocationFamily;
use App\Entity\Duty;
use App\Entity\DutyDemandType;
use App\Entity\DutyGroupInstance;
use App\Entity\DutyPattern;
use App\Entity\DutyType;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Repository\AllocationFamilyRepository;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyPatternRepository;
use App\Repository\DutyRepository;
use App\Repository\DutyTypeRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningLineRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The duties of a reinforcement line that inherits its blocks from its
 * source line (docs/decisions.md D224).
 *
 * For each duty of the source line's period in the same cycle, the current
 * holder (or, in a launch that generates the source too, its planned
 * holder — D191) is read against the line's triggers. Only a triggered
 * source duty gets a reinforcement duty: same instants, same day, linked to
 * it for good (coverageSource). The triggered days of ONE source block form
 * one block of the reinforcement line when there are two or more of them
 * (assigned to one person, atomically), a single one is a standalone duty;
 * a day that triggers nothing never gets a duty — never "to complete" a
 * block.
 *
 * A source duty already reinforced is never touched. A day that starts
 * triggering later joins the reinforcement block of its source block when
 * one exists (that block is then incomplete: never completed automatically,
 * reported, its holder offered first — D224), otherwise it is a new
 * standalone duty (or a new block with the other new days of that source
 * block).
 *
 * plan() reads only; materialize() writes the plan, and never in a
 * validated, published or archived period (docs/planning-cycles.md I8).
 * The patterns and duty types the reinforcement line needs are mirrors of
 * its source's (DutyPattern/DutyType::$inheritedFrom), created on first
 * use, never recurring: the weekly calendar never materializes them.
 */
final class InheritedReinforcementService
{
    private const LOCKED_STATUSES = [PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];
    private const MIRROR_PREFIX = 'INH-';

    public function __construct(
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningLineDemandPolicyRepository $policyRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly DutyRepository $dutyRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly DutyTypeRepository $dutyTypeRepository,
        private readonly DutyPatternRepository $patternRepository,
        private readonly AllocationFamilyRepository $familyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly DemandTriggerEvaluator $evaluator = new DemandTriggerEvaluator(),
    ) {
    }

    /**
     * The rules and source period $target is read with when its line
     * inherits its blocks — null otherwise (an independent line, an OWN
     * conditional line, or a source line without period in that cycle).
     *
     * @return array{0: DemandRules, 1: PlanningPeriod, 2: PlanningLine}|null
     */
    public function contextOf(PlanningPeriod $target): ?array
    {
        $line = $this->lineRepository->findOneByPlanningPeriod($target);
        $policy = null !== $line ? $this->policyRepository->findActiveForLine($line) : null;
        if (null === $policy || !$policy->toRules()->inheritsStructure()) {
            return null;
        }
        $source = $policy->getSourceLine();
        $cycle = $target->getCycle();
        $sourcePeriod = null !== $source && null !== $cycle ? $source->periodIn($cycle) : null;

        return null !== $sourcePeriod ? [$policy->toRules(), $sourcePeriod, $source] : null;
    }

    public function inherits(PlanningLine $line): bool
    {
        return true === $this->policyRepository->findActiveForLine($line)?->toRules()->inheritsStructure();
    }

    /**
     * What $target's reinforcement duties are, and which are still to
     * create — read only.
     *
     * @param array<int, string>|null $plannedHolders the source's planned holders by duty id, in a launch that also
     *                                                generates the source (D191); null = its current calendar
     */
    public function plan(PlanningPeriod $target, ?array $plannedHolders = null): ReinforcementPlan
    {
        $context = $this->contextOf($target);
        if (null === $context) {
            return new ReinforcementPlan(false, false, [], 0, 0);
        }
        [$rules, $sourcePeriod] = $context;

        $holders = $plannedHolders ?? $this->currentHolders($sourcePeriod);
        if (null === $holders) {
            // The source has no calendar yet: nothing can be decided — never guessed.
            return new ReinforcementPlan(true, false, [], 0, 0);
        }

        // The reinforcements that already exist, by source duty — and the reinforcement block of each source block,
        // whichever of its days still triggers.
        $mirrorOf = [];
        $existingBlockOf = [];
        foreach ($this->dutyRepository->findByPlanningPeriod($target) as $duty) {
            $source = $duty->getCoverageSource();
            if (null === $source) {
                continue;
            }
            $mirrorOf[(int) $source->getId()] = $duty;
            if (null !== $duty->getGroupInstance()) {
                $existingBlockOf[self::keyOf($source)] = $duty->getGroupInstance();
            }
        }

        // Triggered source days not reinforced yet, grouped by source block (or alone).
        $pending = [];
        $triggered = 0;
        foreach ($this->triggeredSources($rules, $sourcePeriod, $holders) as $source) {
            ++$triggered;
            if (!isset($mirrorOf[(int) $source->getId()])) {
                $pending[self::keyOf($source)][] = $source;
            }
        }

        $units = [];
        foreach ($pending as $key => $days) {
            $join = $existingBlockOf[$key] ?? null;
            if (null !== $join) {
                // Starts triggering later, in a source block already reinforced: joins that block, left to a person.
                $units[] = new PlannedReinforcement($days, $join);
                continue;
            }
            // Two days or more of one source block: one block. A single day: a standalone duty (create()).
            $units[] = new PlannedReinforcement($days);
        }

        return new ReinforcementPlan(true, true, $units, $triggered, \count($mirrorOf));
    }

    /**
     * The reinforcement duties $rules would ask for in $target — every one,
     * created or not — from the source's CURRENT calendar (docs/decisions.md
     * D224): the preview of a configuration before it is saved. Read only.
     * Null when the source has no calendar yet in that period.
     *
     * @return list<list<Duty>>|null the triggered source duties, one list per reinforcement unit (a block: two days
     *                               or more of one source block; otherwise one day), in date order
     */
    public function preview(DemandRules $rules, PlanningPeriod $sourcePeriod): ?array
    {
        $holders = $this->currentHolders($sourcePeriod);
        if (null === $holders) {
            return null;
        }
        $units = [];
        foreach ($this->triggeredSources($rules, $sourcePeriod, $holders) as $source) {
            $units[self::keyOf($source)][] = $source;
        }

        return array_values($units);
    }

    /**
     * @return array<int, string>|null the current holder (User stable id) of each source duty, by duty id; null when the
     *                                 source period has no generation
     */
    public function currentHolders(PlanningPeriod $sourcePeriod): ?array
    {
        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($sourcePeriod);
        if (null === $generation) {
            return null;
        }
        $holders = [];
        foreach ($this->assignmentRepository->findForGenerations([$generation]) as $assignment) {
            $holders[(int) $assignment->getDuty()->getId()] = (string) $assignment->getTeamMember()->getUser()->getStableId();
        }

        return $holders;
    }

    /**
     * @param array<int, string> $holders
     *
     * @return list<Duty> the source duties whose holder triggers a reinforcement, in date order
     */
    private function triggeredSources(DemandRules $rules, PlanningPeriod $sourcePeriod, array $holders): array
    {
        $sources = $this->dutyRepository->findByPlanningPeriod($sourcePeriod);
        usort($sources, static fn (Duty $a, Duty $b): int => [$a->getStartsAt(), $a->getId()] <=> [$b->getStartsAt(), $b->getId()]);

        return array_values(array_filter(
            $sources,
            fn (Duty $source): bool => $this->evaluator->isTriggered($rules, $holders[(int) $source->getId()] ?? null, Weekday::ofDate($source->getLocalDate())),
        ));
    }

    /** One source block (or one standalone source duty) = one key. */
    private static function keyOf(Duty $source): string
    {
        return null !== $source->getGroupInstance() ? 'g'.$source->getGroupInstance()->getId() : 'd'.$source->getId();
    }

    /**
     * Creates what plan() says is missing. Never in a locked period (I8).
     *
     * @param array<int, string>|null $plannedHolders see plan()
     *
     * @return int the number of duties created
     */
    public function materialize(PlanningPeriod $target, ?array $plannedHolders = null): int
    {
        if (\in_array($target->getStatus(), self::LOCKED_STATUSES, true)) {
            return 0;
        }
        $plan = $this->plan($target, $plannedHolders);
        $created = 0;
        foreach ($plan->units as $unit) {
            $created += \count($this->create($target, $unit));
        }
        if ($created > 0) {
            $this->entityManager->flush();
        }

        return $created;
    }

    /**
     * @return list<Duty>
     */
    private function create(PlanningPeriod $target, PlannedReinforcement $unit): array
    {
        $first = $unit->sourceDuties[0];
        $sourceGroup = $first->getGroupInstance();
        $mirrorPattern = null;
        if (null !== $sourceGroup) {
            $mirrorPattern = $this->mirrorPattern($target, $sourceGroup->getPattern());
        } elseif (null !== $first->getPattern()) {
            $mirrorPattern = $this->mirrorPattern($target, $first->getPattern());
        }

        $group = $unit->joinGroup;
        if (null === $group && \count($unit->sourceDuties) >= 2) {
            $group = new DutyGroupInstance($target, $mirrorPattern ?? throw new \LogicException('A source block always has a pattern.'), $sourceGroup?->getAnchorDate() ?? $first->getLocalDate());
            $this->entityManager->persist($group);
        }

        $duties = [];
        foreach ($unit->sourceDuties as $source) {
            $duty = new Duty(
                $target,
                $this->mirrorType($target, $source->getDutyType()),
                $source->getStartsAt(),
                $source->getEndsAt(),
                $source->getTimezone(),
                DutyDemandType::CONDITIONAL,
                $source->getCriticality(),
                $group,
                null === $group ? $mirrorPattern : null,
                $source,
            );
            $this->entityManager->persist($duty);
            $duties[] = $duty;
        }

        return $duties;
    }

    private function mirrorType(PlanningPeriod $target, DutyType $sourceType): DutyType
    {
        $team = $target->getTeam();
        $existing = $this->dutyTypeRepository->findOneByTeamAndCode($team, $sourceType->getCode());
        if (null !== $existing) {
            return $existing;
        }
        $type = new DutyType($team, $sourceType->getCode(), $sourceType->getName(), $sourceType->getWorkloadValue());
        $type->markInheritedFrom($sourceType);
        $this->entityManager->persist($type);
        $this->entityManager->flush();

        return $type;
    }

    private function mirrorPattern(PlanningPeriod $target, DutyPattern $sourcePattern): DutyPattern
    {
        $team = $target->getTeam();
        foreach ($this->patternRepository->findActiveByTeam($team) as $pattern) {
            if ($pattern->getInheritedFrom() === $sourcePattern) {
                return $pattern;
            }
        }

        $pattern = new DutyPattern($team, self::MIRROR_PREFIX.$sourcePattern->getCode(), $sourcePattern->getName(), $this->mirrorFamily($team, $sourcePattern->getFamily()), recurring: false);
        $pattern->markInheritedFrom($sourcePattern);
        foreach ($sourcePattern->getComponents() as $component) {
            $pattern->addComponent($component->getDayOffset(), $this->mirrorType($target, $component->getDutyType()));
        }
        $this->entityManager->persist($pattern);
        $this->entityManager->flush();

        return $pattern;
    }

    private function mirrorFamily(\App\Entity\PlanningTeam $team, ?AllocationFamily $sourceFamily): ?AllocationFamily
    {
        if (null === $sourceFamily) {
            return null;
        }
        $existing = $this->familyRepository->findOneByTeamAndCode($team, $sourceFamily->getCode());
        if (null !== $existing) {
            return $existing;
        }
        $family = new AllocationFamily($team, $sourceFamily->getCode(), $sourceFamily->getName());
        $this->entityManager->persist($family);

        return $family;
    }
}
