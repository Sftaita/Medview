<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;

/**
 * What "Générer les postes non pourvus" / "Générer cette ligne" would do with
 * one line (docs/decisions.md D222), read from the current state:
 *
 * - GENERATE — the line was never generated: its first generation, this line
 *   only, every other line kept and constraining it;
 * - COMPLETE — the line has a current generation with uncovered required
 *   duties: its holes only, every assignment it holds fixed (D145);
 * - NOTHING — generated, nothing uncovered: nothing is written;
 * - EXCLUDED — never touched, `$reason` says why (archived, nothing
 *   demanded, a blocker of its first generation…).
 *
 * A published (or validated) line is EXCLUDED (`published`), never completed by
 * this operation, and never blocks the other lines — except for the duties an
 * extension of the period created (docs/decisions.md D226): COMPLETE with
 * `$extensionOnly`, those duties alone.
 */
final readonly class FillLinePlan
{
    public const GENERATE = 'GENERATE';
    public const COMPLETE = 'COMPLETE';
    public const NOTHING = 'NOTHING';
    public const EXCLUDED = 'EXCLUDED';

    /**
     * @param list<string> $blockers PreflightIssueCode values that prevent its first generation
     * @param list<string> $warnings PreflightIssueCode values worth showing before its first generation
     */
    public function __construct(
        public PlanningLine $line,
        public PlanningPeriodStatus $periodStatus,
        public bool $generated,
        public string $action,
        public ?string $reason,
        public bool $selectable,
        public bool $selectedByDefault,
        /** Required duties of the line — null when not known yet (a reinforcement line never generated, D167). */
        public ?int $requiredDutyCount,
        public int $coveredRequiredDutyCount,
        public ?int $uncoveredRequiredDutyCount,
        /** The assignments the line currently holds: never changed by this operation. */
        public int $protectedAssignmentCount,
        public array $blockers = [],
        public array $warnings = [],
        /** A first generation that needs its source line's, generated just before it in the same run (D164). */
        public bool $afterSource = false,
        /** Members who joined after the line's generation, taken into account by its completion. */
        public int $lateMemberCount = 0,
        /** docs/decisions.md D224, D226: nothing of its own now, but it follows a source line this run gives holders to. */
        public bool $followsSource = false,
        /** docs/decisions.md D226: a published line, only the duties its extension created. */
        public bool $extensionOnly = false,
        /** docs/decisions.md D226: its older holes, never completed automatically. */
        public int $otherUncoveredCount = 0,
    ) {
    }

    public function followingSource(): self
    {
        return new self($this->line, $this->periodStatus, $this->generated, $this->action, $this->reason, $this->selectable, $this->selectedByDefault, $this->requiredDutyCount, $this->coveredRequiredDutyCount, $this->uncoveredRequiredDutyCount, $this->protectedAssignmentCount, $this->blockers, $this->warnings, $this->afterSource, $this->lateMemberCount, true, $this->extensionOnly, $this->otherUncoveredCount);
    }

    public function writes(): bool
    {
        return self::GENERATE === $this->action || self::COMPLETE === $this->action;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lineStableId' => (string) $this->line->getStableId(),
            'lineName' => $this->line->getName(),
            'lineType' => $this->line->getType()->value,
            'periodStatus' => $this->periodStatus->value,
            'generated' => $this->generated,
            'action' => $this->action,
            'reason' => $this->reason,
            'selectable' => $this->selectable,
            'selectedByDefault' => $this->selectedByDefault,
            'requiredDutyCount' => $this->requiredDutyCount,
            'coveredRequiredDutyCount' => $this->coveredRequiredDutyCount,
            'uncoveredRequiredDutyCount' => $this->uncoveredRequiredDutyCount,
            'protectedAssignmentCount' => $this->protectedAssignmentCount,
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'afterSource' => $this->afterSource,
            'lateMemberCount' => $this->lateMemberCount,
            'followsSource' => $this->followsSource,
            'extensionOnly' => $this->extensionOnly,
            'otherUncoveredCount' => $this->otherUncoveredCount,
        ];
    }
}
