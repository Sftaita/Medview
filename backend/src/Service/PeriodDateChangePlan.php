<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;

/**
 * What "Modifier les dates" would do (docs/decisions.md D225), computed
 * without writing anything — the same plan answers the preview and, under the
 * lock, the write. $impactHash is what the manager confirmed: a write whose
 * plan no longer has this hash is refused, whatever changed in between.
 */
final readonly class PeriodDateChangePlan
{
    public const NOTIFY_NOBODY = 'nobody_to_notify';
    public const NOTIFY_PENDING_CHANGES = 'pending_changes';
    public const NOTIFY_NOT_REPUBLISHABLE = 'not_republishable';

    /**
     * @param list<array{cycle: PlanningCycle, previousStartsAt: \DateTimeImmutable, previousEndsAt: \DateTimeImmutable, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable, generated: bool, published: bool, selected: bool}> $targets        the periods whose dates move
     * @param list<array{cycle: PlanningCycle, line: PlanningLine, duty: Duty, assignment: ?DutyAssignment, published: bool}>                                                                                                          $retired        the duties left outside their period
     * @param list<array{cycle: PlanningCycle, line: PlanningLine, from: \DateTimeImmutable, to: \DateTimeImmutable, dutyCount: ?int, published: bool}>                                                                                $additions      the days a generated line gains (to: exclusive) — dutyCount null: decided by the holders of its source line
     * @param list<array{cycle: PlanningCycle, startsAt: string, endsAt: string, newStartsAt: string, newEndsAt: string, contacted: bool, answered: bool}>                                                                             $collections    the collections whose window changes
     * @param list<PlanningCycle>                                                                                                                                                                                                      $republish      the periods "Confirmer et avertir" republishes
     * @param list<string>                                                                                                                                                                                                             $recipients     who "Confirmer et avertir" would write to
     * @param array<int, int>                                                                                                                                                                                                          $pendingChanges unpublished changes per period number, before this change
     */
    public function __construct(
        public array $targets,
        public array $retired,
        public array $additions,
        public array $collections,
        public int $swapRequestCount,
        public bool $confirmationRequired,
        public array $republish,
        public array $recipients,
        public ?string $notifyBlock,
        public array $pendingChanges,
        public string $impactHash,
    ) {
    }

    public function changesAnything(): bool
    {
        return [] !== $this->targets;
    }

    /**
     * What the screen shows, and what the journal freezes: names and dates,
     * never an internal notion.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $units = [];
        foreach ($this->retired as $row) {
            $duty = $row['duty'];
            $key = null !== $duty->getGroupInstance() ? 'g'.$duty->getGroupInstance()->getId() : 'd'.$duty->getId();
            $units[$key] ??= [
                'periodNumber' => $row['cycle']->getNumber(),
                'line' => $row['line']->getName(),
                'block' => $duty->getGroupInstance()?->getPattern()->getName(),
                'dates' => [],
                'holder' => null !== $row['assignment'] ? self::nameOf($row['assignment']) : null,
                'published' => $row['published'],
            ];
            $units[$key]['dates'][] = $duty->getLocalDate()->format('Y-m-d');
        }

        $assigned = array_values(array_filter($this->retired, static fn (array $row): bool => null !== $row['assignment']));
        $people = [];
        foreach ($assigned as $row) {
            $name = self::nameOf($row['assignment']);
            $people[$name] = ($people[$name] ?? 0) + 1;
        }
        ksort($people);

        return [
            'changes' => array_map(static fn (array $target): array => [
                'periodStableId' => (string) $target['cycle']->getStableId(),
                'periodNumber' => $target['cycle']->getNumber(),
                'selected' => $target['selected'],
                'generated' => $target['generated'],
                'published' => $target['published'],
                'from' => ['startsAt' => $target['previousStartsAt']->format('Y-m-d'), 'lastDay' => $target['previousEndsAt']->modify('-1 day')->format('Y-m-d')],
                'to' => ['startsAt' => $target['startsAt']->format('Y-m-d'), 'lastDay' => $target['endsAt']->modify('-1 day')->format('Y-m-d')],
            ], $this->targets),
            'retiredDutyCount' => \count($this->retired),
            'retiredAssignedCount' => \count($assigned),
            'retiredPublishedCount' => \count(array_filter($assigned, static fn (array $row): bool => $row['published'])),
            'retiredUnits' => array_values($units),
            'holders' => array_map(static fn (string $name, int $count): array => ['name' => $name, 'dutyCount' => $count], array_keys($people), array_values($people)),
            'additions' => array_map(static fn (array $addition): array => [
                'periodNumber' => $addition['cycle']->getNumber(),
                'line' => $addition['line']->getName(),
                'firstDay' => $addition['from']->format('Y-m-d'),
                'lastDay' => $addition['to']->modify('-1 day')->format('Y-m-d'),
                'dutyCount' => $addition['dutyCount'],
                'published' => $addition['published'],
            ], $this->additions),
            'collections' => array_map(static fn (array $collection): array => [
                'periodNumber' => $collection['cycle']->getNumber(),
                'startsAt' => $collection['startsAt'],
                'lastDay' => (new \DateTimeImmutable($collection['endsAt']))->modify('-1 day')->format('Y-m-d'),
                'newStartsAt' => $collection['newStartsAt'],
                'newLastDay' => (new \DateTimeImmutable($collection['newEndsAt']))->modify('-1 day')->format('Y-m-d'),
                'contacted' => $collection['contacted'],
                'answered' => $collection['answered'],
            ], $this->collections),
            'swapRequestCount' => $this->swapRequestCount,
            'confirmationRequired' => $this->confirmationRequired,
            'republishPeriods' => array_map(static fn (PlanningCycle $cycle): int => $cycle->getNumber(), $this->republish),
            'recipients' => $this->recipients,
            'canNotify' => null === $this->notifyBlock,
            'notifyBlock' => $this->notifyBlock,
            'pendingChanges' => array_sum($this->pendingChanges),
            'impactHash' => $this->impactHash,
        ];
    }

    private static function nameOf(DutyAssignment $assignment): string
    {
        $user = $assignment->getTeamMember()->getUser();

        return trim($user->getFirstName().' '.$user->getLastName());
    }
}
