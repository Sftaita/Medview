<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\DemandMode;
use App\Demand\DemandRules;
use App\Demand\Weekday;
use App\Dto\DemandPolicyUpdateRequest;
use App\Entity\DemandTrigger;
use App\Entity\PlanningLine;
use App\Entity\PlanningLineDemandPolicy;
use App\Entity\PlanningPeriodStatus;
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
 * State rules (409): no conditional configuration once a line of the
 * Planning is published (it could never be generated for this period —
 * D133, V1 limitation), and no change of mode or source once the line's
 * duties are materialized (their demand kind is fixed at materialization).
 * A trigger-only change of a conditional line stays possible: it applies
 * to the next generations.
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

        $structure = $this->weekStructureService->read($line);
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

                $policy = new PlanningLineDemandPolicy($line, $this->policyRepository->findNextVersionNumber($line), $mode, $sourceLine, $author);
                $this->entityManager->persist($policy);
                foreach ($triggers as [$user, $weekdays, $increment]) {
                    $this->entityManager->persist(new DemandTrigger($policy, $user, $weekdays, $increment));
                }
                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw new DemandPolicyConflictException('concurrent_update', 'The demand policy of this line was changed at the same time by someone else.');
        }

        return $this->read($line);
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
        if ($mode->isConditional()) {
            foreach ($this->lineRepository->findByPlanning($line->getPlanning()) as $other) {
                if ($other->isActive() && \in_array($other->getPlanningPeriod()->getStatus(), [PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED], true)) {
                    throw new DemandPolicyConflictException('planning_already_published', 'This planning is already published: its lines can no longer be generated for this period, so a conditional line configured now could never be used. Configure it before the first publication.');
                }
            }
        }

        $modeOrSourceChanges = $current->mode !== $mode
            || $current->sourceLineStableId !== (null !== $sourceLine ? (string) $sourceLine->getStableId() : null);
        if ($modeOrSourceChanges && $this->dutyRepository->existsForPeriod($line->getPlanningPeriod())) {
            throw new DemandPolicyConflictException('line_already_materialized', 'The duties of this line already exist for this period: its mode or its source can no longer change for this period.');
        }
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
            $options[] = new DemandSourceOption($line, $this->currentPeople($line));
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
