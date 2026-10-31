<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;

/**
 * The picture an OWNER/ADMIN needs before generating: who takes part, how
 * many unavailabilities the period holds, and the (informative) deadline.
 * Read model only — see PlanningCollectionStatusService. docs/decisions.md
 * D214: no count of confirmations, nobody is asked for one.
 */
final readonly class PlanningCollectionStatus
{
    /**
     * @param list<MemberCollectionRow> $rows
     */
    public function __construct(
        public Planning $planning,
        /** Latest deadline among the open collections; informative, never blocking (D127). */
        public ?\DateTimeImmutable $availabilityDeadline,
        /** Whole days elapsed since the deadline, or null when there is none / it is not passed. */
        public ?int $deadlineOverdueDays,
        public int $openCollectionCount,
        public array $rows,
    ) {
    }

    /** Everyone listed (members of the period). */
    public function participantCount(): int
    {
        return \count($this->rows);
    }

    /** Listed people an announcement or a reminder reaches. */
    public function concernedCount(): int
    {
        return \count(array_filter($this->rows, static fn (MemberCollectionRow $row): bool => $row->participant));
    }

    public function unavailabilityCount(): int
    {
        return array_sum(array_map(static fn (MemberCollectionRow $row): int => $row->unavailabilityCount, $this->rows));
    }
}
