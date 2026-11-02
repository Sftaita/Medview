<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What "Générer les postes non pourvus" / "Générer cette ligne" did on one
 * line (docs/decisions.md D222). The counts are measured on the visible
 * calendar before and after (CurrentCalendarLedger), never derived from what
 * the engine says it did:
 *
 * - `keptCount`: assignments the line held before, still visible unchanged;
 * - `modifiedCount`: assignments the line held before and no longer holds
 *   exactly as before — 0 by construction; anything else is reported;
 * - `addedCount`: assignments visible now that were not before;
 * - `remainingUncoveredCount`: required duties still without anybody.
 *
 * `$status`: `done` (the operation ran, possibly adding nothing when nobody
 * could take a duty), `nothing_to_do`, `not_selected`, `excluded`,
 * `blocked` (a blocker appeared since the preview), `failed` (no usable
 * result, nothing written), `calendar_changed` (the calendar changed during
 * the computation, nothing written).
 */
final readonly class FillLineResult
{
    /**
     * @param list<string>              $blockers
     * @param array<string, mixed>|null $launch   the generation's own report, for a first generation
     */
    public function __construct(
        public FillLinePlan $plan,
        public string $status,
        public ?string $reason = null,
        public int $keptCount = 0,
        public int $modifiedCount = 0,
        public int $addedCount = 0,
        public ?int $remainingUncoveredCount = null,
        public array $blockers = [],
        public ?array $launch = null,
    ) {
    }

    public function withCounts(int $kept, int $modified, int $added, ?int $remainingUncovered): self
    {
        return new self($this->plan, $this->status, $this->reason, $kept, $modified, $added, $remainingUncovered, $this->blockers, $this->launch);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lineStableId' => (string) $this->plan->line->getStableId(),
            'lineName' => $this->plan->line->getName(),
            'action' => $this->plan->action,
            'status' => $this->status,
            'reason' => $this->reason,
            'keptCount' => $this->keptCount,
            'modifiedCount' => $this->modifiedCount,
            'addedCount' => $this->addedCount,
            'remainingUncoveredCount' => $this->remainingUncoveredCount,
            'blockers' => $this->blockers,
            'launch' => $this->launch,
        ];
    }
}
