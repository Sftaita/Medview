<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\DemandMode;
use App\Demand\DemandRules;
use App\Demand\StructureSource;
use App\Demand\Weekday;
use App\Dto\DemandPolicyUpdateRequest;
use App\Entity\DemandTrigger;
use App\Entity\PlanningLine;
use App\Entity\PlanningLineDemandPolicy;
use App\Entity\User;
use App\Exception\DemandPolicyConflictException;
use App\Exception\InvalidDemandPolicyException;
use App\Repository\DutyRepository;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and replaces a PlanningLine's demand policy (docs/decisions.md
 * D162): INDEPENDENT (its own weekly structure — also what "no policy at
 * all" means) or CONDITIONAL_ON_SOURCE_ASSIGNMENT (a duty is only needed
 * when the person holding the source line's duty triggers it that
 * weekday).
 *
 * Versioned, never edited: a real change creates the next version ACTIVE
 * and RETIRES the previous one, in one transaction; resubmitting what is
 * already in force creates nothing.
 *
 * V1 structure rules (422 `invalid_demand_policy`, with a stable code):
 * the source is another active line of the same Planning, itself
 * INDEPENDENT; the target is an active SECONDARY line that is nobody's
 * source — i.e. depth 1, no chain, no cycle. A trigger names a person
 * (User), at least one weekday, and increment 1.
 *
 * State rule (409, docs/decisions.md D224 — replaces the V1 rules): no
 * change of mode, of source or of structure while a period of the line
 * that is NOT generated yet already holds duties (their kind and their
 * blocks are fixed at materialization). One configuration per line,
 * applying to every period not generated yet; a generated period keeps the
 * rules its generation froze (snapshot). A line of the planning being
 * published no longer forbids anything: since D196/D222 a line is
 * generated and published on its own, after the others.
 *
 * Every conditional policy written now INHERITS its blocks from its source
 * line (StructureSource::INHERITED): the reinforcement line has no weekly
 * structure of its own. An OWN policy recorded before D224 is read as it
 * was, never rewritten unless the configuration is changed.
 *
 * Warnings are computed, never refusals (DemandPolicyWarning).
 */
final class PlanningLineDemandPolicyService
{
    public const SCHEMA_VERSION = 1;
    public const SUPPORTED_INCREMENT = 1;

    public function __construct(
        private readonly PlanningLineDemandPolicyRepository $policyRepository,
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly UserRepository $userRepository,
        private readonly DutyRepository $dutyRepository,
        private readonly WeekStructureService $weekStructureService,
        private readonly EntityManagerInterface $entityManager,
        private readonly \App\Repository\PlanningGenerationRepository $generationRepository,
        private readonly InheritedReinforcementService $inheritedReinforcements,
    ) {
    }

    /** The rules in force for $line — INDEPENDENT when it has no policy. */
    public function rulesFor(PlanningLine $line): DemandRules
    {
        return $this->policyRepository->findActiveForLine($line)?->toRules() ?? DemandRules::independent();
    }

    public function read(PlanningLine $line): DemandPolicyView
    {
        $policy = $this->policyRepository->findActiveForLine($line);
        $rules = $policy?->toRules() ?? DemandRules::independent();
        $triggerUsers = null !== $policy ? array_map(static fn (DemandTrigger $t): User => $t->getUser(), $policy->getTriggers()->toArray()) : [];
        $sourceLine = $policy?->getSourceLine();

        // docs/decisions.md D224: an inheriting line shows its source's structure — it has none of its own.
        $inheritsFrom = $rules->inheritsStructure() ? $sourceLine : null;
        $structure = $this->weekStructureService->read($inheritsFrom ?? $line);
        $hasStructure = [] !== $structure->blocks || [] !== $structure->solo;
        $targetBlocks = [];
        foreach ($structure->blocks as $block) {
            $targetBlocks[] = ['name' => $block->name, 'weekdays' => array_map(Weekday::fromWeekStructureCode(...), $block->days)];
        }
        $targetExcluded = $hasStructure ? array_map(Weekday::fromWeekStructureCode(...), $structure->excluded) : null;

        return new DemandPolicyView(
            $line,
            $policy,
            $rules,
            $sourceLine,
            array_values($triggerUsers),
            $this->sourceOptions($line),
            $targetBlocks,
            $targetExcluded,
            $this->warnings($rules, $sourceLine, $triggerUsers, $targetBlocks, $targetExcluded),
            $inheritsFrom,
        );
    }

    /**
     * @throws InvalidDemandPolicyException  a V1 structure rule is broken (422)
     * @throws DemandPolicyConflictException the Planning's state forbids it (409)
     */
    public function replace(PlanningLine $line, DemandPolicyUpdateRequest $request, ?User $author): DemandPolicyView
    {
        [$mode, $sourceLine, $triggers] = $this->validate($line, $request);

        $current = $this->policyRepository->findActiveForLine($line);
        $currentRules = $current?->toRules() ?? DemandRules::independent();
        if ($this->sameContent($currentRules, $mode, $sourceLine, $triggers)) {
            // Nothing changes: never a new version for an identical submission.
            return $this->read($line);
        }

        $this->assertApplicable($line, $currentRules, $mode, $sourceLine);

        try {
            $this->entityManager->wrapInTransaction(function () use ($line, $current, $mode, $sourceLine, $triggers, $author): void {
                // Two flushes on purpose (same reason as PlanningRuleSetService::activate()): "one ACTIVE per line"
                // is a partial unique index, checked per statement — retire first, then insert.
                if (null !== $current) {
                    $current->retire();
                    $this->entityManager->flush();
                }

                // docs/decisions.md D224: a reinforcement inherits its blocks — except a historical OWN line whose duties
                // already exist in a period not generated yet: its new triggers apply to those same duties, never
                // mixed with inherited ones.
                $structure = $mode->isConditional() && null !== $current && StructureSource::OWN === $current->getStructureSource()
                    && $current->getMode() === $mode && $current->getSourceLine() === $sourceLine && $this->hasUngeneratedDuties($line)
                    ? StructureSource::OWN : null;
                $policy = new PlanningLineDemandPolicy($line, $this->policyRepository->findNextVersionNumber($line), $mode, $sourceLine, $author, $structure);
                $this->entityManager->persist($policy);
                foreach ($triggers as [$user, $weekdays, $increment]) {
                    $this->entityManager->persist(new DemandTrigger($policy, $user, $weekdays, $increment));
                }
                // docs/decisions.md D227: it inherits now — neither the general structure nor one of its own.
                if ($policy->toRules()->inheritsStructure()) {
                    $this->weekStructureService->deactivate($line);
                    $line->changeWeekStructureMode('CUSTOM');
                }
                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw new DemandPolicyConflictException('concurrent_update', 'The demand policy of this line was changed at the same time by someone else.');
        }

        return $this->read($line);
    }

    /**
     * docs/decisions.md D224 — the reinforcement duties a configuration
     * (saved or not) would ask for, period by period, from the source line's
     * CURRENT calendar. Validated exactly like replace(); reads only, writes
     * nothing, sends nothing.
     *
     * @return list<array{period: \App\Entity\PlanningPeriod, generated: bool, sourceGenerated: bool, units: list<list<\App\Entity\Duty>>}>
     *
     * @throws InvalidDemandPolicyException
     */
    public function preview(PlanningLine $line, DemandPolicyUpdateRequest $request): array
    {
        [$mode, $sourceLine, $triggers] = $this->validate($line, $request);
        if (!$mode->isConditional() || null === $sourceLine) {
            return [];
        }
        $rules = new DemandRules(
            $mode,
            (string) $sourceLine->getStableId(),
            array_map(static fn (array $t): \App\Demand\DemandTriggerRule => new \App\Demand\DemandTriggerRule((string) $t[0]->getStableId(), $t[1], $t[2]), $triggers),
            StructureSource::INHERITED,
        );

        $periods = [];
        foreach ($line->getPeriods() as $period) {
            $cycle = $period->getCycle();
            $sourcePeriod = null !== $cycle ? $sourceLine->periodIn($cycle) : null;
            if (null === $sourcePeriod) {
                continue;
            }
            $units = $this->inheritedReinforcements->preview($rules, $sourcePeriod);
            $periods[] = [
                'period' => $period,
                'generated' => null !== $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period),
                'sourceGenerated' => null !== $units,
                'units' => $units ?? [],
            ];
        }
        usort($periods, static fn (array $a, array $b): int => $a['period']->getStartsAt() <=> $b['period']->getStartsAt());

        return $periods;
    }

    /**
     * @return array{0: DemandMode, 1: ?PlanningLine, 2: list<array{0: User, 1: list<Weekday>, 2: int}>}
     *
     * @throws InvalidDemandPolicyException
     */
    private function validate(PlanningLine $line, DemandPolicyUpdateRequest $request): array
    {
        if (self::SCHEMA_VERSION !== $request->schemaVersion) {
            throw new InvalidDemandPolicyException('UNSUPPORTED_SCHEMA_VERSION', 'schemaVersion', \sprintf('Only schemaVersion %d is supported.', self::SCHEMA_VERSION));
        }

        $mode = DemandMode::tryFrom($request->mode) ?? throw new InvalidDemandPolicyException('UNKNOWN_MODE', 'mode', \sprintf('Unknown mode "%s".', $request->mode));

        if (!$mode->isConditional()) {
            if (null !== $request->sourceLineStableId) {
                throw new InvalidDemandPolicyException('INDEPENDENT_WITH_SOURCE', 'source', 'An independent line has no source.');
            }
            if ([] !== $request->triggers) {
                throw new InvalidDemandPolicyException('INDEPENDENT_WITH_TRIGGERS', 'triggers', 'An independent line has no trigger.');
            }

            return [$mode, null, []];
        }

        // A V1 constraint (docs/decisions.md D162), not an invariant of the domain: nothing else in the model assumes it.
        if ($line->isPrimary()) {
            throw new InvalidDemandPolicyException('PRIMARY_LINE_CANNOT_BE_CONDITIONAL', 'mode', 'The main line always has its own demand: only a secondary line can be conditional.');
        }
        if (!$line->isActive()) {
            throw new InvalidDemandPolicyException('TARGET_INACTIVE', 'mode', 'An inactive line cannot become conditional.');
        }
        if ([] !== $this->policyRepository->findActiveUsingSource($line)) {
            throw new InvalidDemandPolicyException('TARGET_IS_A_SOURCE', 'mode', 'This line is the source of another conditional line: a conditional line can never be a source (depth 1, no chain).');
        }

        $sourceLine = $this->resolveSource($line, $request->sourceLineStableId);

        $triggers = [];
        $seenUsers = [];
        foreach ($request->triggers as $index => $input) {
            $field = \sprintf('triggers[%d]', $index);
            $user = $this->userRepository->findOneByStableId($input->userStableId)
                ?? throw new InvalidDemandPolicyException('UNKNOWN_USER', $field.'.userStableId', 'Unknown person.');
            if (isset($seenUsers[(string) $user->getStableId()])) {
                throw new InvalidDemandPolicyException('DUPLICATE_TRIGGER_PERSON', $field.'.userStableId', 'One trigger per person: list all their weekdays in a single trigger.');
            }
            $seenUsers[(string) $user->getStableId()] = true;

            if ([] === $input->weekdays) {
                throw new InvalidDemandPolicyException('NO_WEEKDAY', $field.'.weekdays', 'A trigger needs at least one weekday.');
            }
            $weekdays = [];
            foreach ($input->weekdays as $code) {
                $weekday = Weekday::tryFrom($code) ?? throw new InvalidDemandPolicyException('UNKNOWN_WEEKDAY', $field.'.weekdays', \sprintf('Unknown weekday "%s".', $code));
                if (\in_array($weekday, $weekdays, true)) {
                    throw new InvalidDemandPolicyException('DUPLICATE_WEEKDAY', $field.'.weekdays', \sprintf('Weekday "%s" is listed twice.', $code));
                }
                $weekdays[] = $weekday;
            }

            if (self::SUPPORTED_INCREMENT !== $input->increment) {
                throw new InvalidDemandPolicyException('UNSUPPORTED_INCREMENT', $field.'.increment', 'Only an increment of 1 (one reinforcement) is supported.');
            }

            $triggers[] = [$user, Weekday::sorted($weekdays), $input->increment];
        }

        return [$mode, $sourceLine, $triggers];
    }

    /**
     * @throws InvalidDemandPolicyException
     */
    private function resolveSource(PlanningLine $line, ?string $sourceLineStableId): PlanningLine
    {
        if (null === $sourceLineStableId) {
            throw new InvalidDemandPolicyException('SOURCE_REQUIRED', 'source', 'A conditional line needs a source line.');
        }

        $source = $this->lineRepository->findOneByStableId($sourceLineStableId);
        // A line of another Planning is reported exactly like an unknown one: it is not a line of this Planning.
        if (null === $source || $source->getPlanning() !== $line->getPlanning()) {
            throw new InvalidDemandPolicyException('SOURCE_NOT_IN_PLANNING', 'source', 'The source must be another line of the same planning.');
        }
        if ($source === $line) {
            throw new InvalidDemandPolicyException('SOURCE_IS_TARGET', 'source', 'A line can never be its own source.');
        }
        if (!$source->isActive()) {
            throw new InvalidDemandPolicyException('SOURCE_INACTIVE', 'source', 'The source line is inactive.');
        }
        if ($this->rulesFor($source)->mode->isConditional()) {
            throw new InvalidDemandPolicyException('SOURCE_NOT_INDEPENDENT', 'source', 'The source line is itself conditional: a conditional line can only depend on an independent one (depth 1).');
        }

        return $source;
    }

    /**
     * @param list<array{0: User, 1: list<Weekday>, 2: int}> $triggers
     */
    private function sameContent(DemandRules $current, DemandMode $mode, ?PlanningLine $sourceLine, array $triggers): bool
    {
        if ($current->mode !== $mode || $current->sourceLineStableId !== (null !== $sourceLine ? (string) $sourceLine->getStableId() : null)) {
            return false;
        }

        $requested = [];
        foreach ($triggers as [$user, $weekdays, $increment]) {
            $requested[(string) $user->getStableId()] = [array_map(static fn (Weekday $w): string => $w->value, $weekdays), $increment];
        }
        $existing = [];
        foreach ($current->triggersByUser as $userStableId => $rule) {
            $existing[$userStableId] = [array_map(static fn (Weekday $w): string => $w->value, $rule->weekdays), $rule->increment];
        }
        ksort($requested);
        ksort($existing);

        return $requested === $existing;
    }

    /**
     * @throws DemandPolicyConflictException
     */
    private function assertApplicable(PlanningLine $line, DemandRules $current, DemandMode $mode, ?PlanningLine $sourceLine): void
    {
        // docs/decisions.md D224: one configuration per line, for every period not generated yet. Only a period
        // whose duties already exist without being generated would mix two configurations: then mode, source and
        // structure are fixed (the triggers stay editable). A generated period reads the rules its snapshot froze.
        $modeOrSourceChanges = $current->mode !== $mode
            || $current->sourceLineStableId !== (null !== $sourceLine ? (string) $sourceLine->getStableId() : null);
        if (!$modeOrSourceChanges) {
            return;
        }
        if ($this->hasUngeneratedDuties($line)) {
            throw new DemandPolicyConflictException('line_already_materialized', 'Duties of this line already exist in a period not generated yet: its mode and its source can no longer change for that period.');
        }
    }

    /** A period of $line not generated yet already holds duties (materialized under the configuration in force). */
    private function hasUngeneratedDuties(PlanningLine $line): bool
    {
        foreach ($line->getPeriods() as $period) {
            if ($this->dutyRepository->existsForPeriod($period) && null === $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<DemandSourceOption>
     */
    private function sourceOptions(PlanningLine $target): array
    {
        $options = [];
        foreach ($this->lineRepository->findByPlanning($target->getPlanning()) as $line) {
            if ($line === $target || !$line->isActive() || $this->rulesFor($line)->mode->isConditional()) {
                continue;
            }
            $structure = $this->weekStructureService->read($line);
            $codes = static fn (array $days): array => array_map(static fn (string $code): string => Weekday::fromWeekStructureCode($code)->value, $days);
            $options[] = new DemandSourceOption($line, $this->currentPeople($line), [
                'blocks' => array_map(static fn ($block): array => ['name' => $block->name, 'weekdays' => $codes($block->days)], $structure->blocks),
                'soloWeekdays' => $codes($structure->solo),
                'excludedWeekdays' => $codes($structure->excluded),
            ]);
        }

        return $options;
    }

    /**
     * The people currently in $line's team — one entry per person, however many stints (D160).
     *
     * @return list<User>
     */
    private function currentPeople(PlanningLine $line): array
    {
        $people = [];
        foreach ($this->teamMemberRepository->findByTeam($line->getPlanningTeam()) as $member) {
            if ($member->isCurrentlyOpen()) {
                $people[(string) $member->getUser()->getStableId()] = $member->getUser();
            }
        }

        $people = array_values($people);
        usort($people, static fn (User $a, User $b): int => [$a->getLastName(), $a->getFirstName(), (string) $a->getStableId()] <=> [$b->getLastName(), $b->getFirstName(), (string) $b->getStableId()]);

        return $people;
    }

    /**
     * @param list<User>                                         $triggerUsers
     * @param list<array{name: string, weekdays: list<Weekday>}> $targetBlocks
     * @param list<Weekday>|null                                 $targetExcluded
     *
     * @return list<DemandPolicyWarning>
     */
    private function warnings(DemandRules $rules, ?PlanningLine $sourceLine, array $triggerUsers, array $targetBlocks, ?array $targetExcluded): array
    {
        if (!$rules->mode->isConditional()) {
            return [];
        }

        $warnings = [];
        if ([] === $rules->triggersByUser) {
            $warnings[] = new DemandPolicyWarning('NO_TRIGGER', [], 'Aucune condition de renfort : aucune garde de renfort ne sera demandée tant qu’aucun chirurgien n’a de jour coché.');
        }
        if ($rules->inheritsStructure()) {
            // docs/decisions.md D224: the blocks are the source's, a reinforcement exists only on a triggered day —
            // no structure of its own to be missing, no day excluded by it, never a block completed for one day.
            $targetExcluded = [];
            $targetBlocks = [];
        }
        if (null === $targetExcluded) {
            $warnings[] = new DemandPolicyWarning('TARGET_HAS_NO_WEEK_STRUCTURE', [], 'Cette ligne n’a pas encore de semaine type : aucune garde de renfort ne peut exister tant qu’elle n’est pas configurée.');
        }

        $sourcePeople = [];
        if (null !== $sourceLine) {
            foreach ($this->currentPeople($sourceLine) as $person) {
                $sourcePeople[(string) $person->getStableId()] = true;
            }
        }
        $namesByUser = [];
        foreach ($triggerUsers as $user) {
            $namesByUser[(string) $user->getStableId()] = trim($user->getFirstName().' '.$user->getLastName());
        }

        foreach ($rules->triggersByUser as $userStableId => $trigger) {
            $name = $namesByUser[$userStableId] ?? $userStableId;

            if (!isset($sourcePeople[$userStableId])) {
                $warnings[] = new DemandPolicyWarning('TRIGGER_PERSON_NOT_IN_SOURCE_LINE', ['userStableId' => $userStableId], \sprintf('%s ne fait pas partie de la ligne source : ce déclencheur restera sans effet tant que cette personne n’y est pas de garde.', $name));
            }

            foreach ($trigger->weekdays as $weekday) {
                if (null !== $targetExcluded && \in_array($weekday, $targetExcluded, true)) {
                    $warnings[] = new DemandPolicyWarning('TRIGGER_DAY_EXCLUDED_FROM_TARGET', ['userStableId' => $userStableId, 'weekday' => $weekday->value], \sprintf('%s : la semaine type de cette ligne n’a pas de garde ce jour-là (%s), aucun renfort n’y sera possible.', $name, self::frenchDay($weekday)));
                }
            }

            foreach ($targetBlocks as ['name' => $blockName, 'weekdays' => $blockWeekdays]) {
                $covered = array_values(array_filter($blockWeekdays, $trigger->covers(...)));
                if ([] !== $covered && \count($covered) < \count($blockWeekdays)) {
                    $warnings[] = new DemandPolicyWarning(
                        'TRIGGER_PARTIALLY_COVERS_TARGET_BLOCK',
                        [
                            'userStableId' => $userStableId,
                            'blockName' => $blockName,
                            'blockWeekdays' => array_map(static fn (Weekday $w): string => $w->value, $blockWeekdays),
                            'triggeredWeekdays' => array_map(static fn (Weekday $w): string => $w->value, $covered),
                        ],
                        \sprintf('%s ne déclenche qu’une partie du bloc « %s » : un bloc est attribué d’un seul tenant, le renfort couvrira donc tout le bloc dès qu’un de ces jours est déclenché.', $name, $blockName),
                    );
                }
            }
        }

        return $warnings;
    }

    private static function frenchDay(Weekday $weekday): string
    {
        return ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'][$weekday->isoNumber() - 1];
    }
}
