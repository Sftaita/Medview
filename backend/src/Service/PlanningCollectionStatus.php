<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;

/**
 * The picture an OWNER/ADMIN needs before generating: who confirmed, who did
 * not, how many unavailabilities the period holds, and the (informative)
 * deadline. Read model only — see PlanningCollectionStatusService.
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

    /** Participants who are expected to answer (confirmed + pending). */
    public function expectedCount(): int
    {
        return $this->confirmedCount() + $this->pendingCount();
    }

    public function confirmedCount(): int
    {
        return $this->countState(MemberCollectionState::ACKNOWLEDGED);
    }

    public function pendingCount(): int
    {
        return $this->countState(MemberCollectionState::PENDING);
    }

    public function notExpectedCount(): int
    {
        return $this->countState(MemberCollectionState::NOT_EXPECTED);
    }

    public function participantCount(): int
    {
        return \count($this->rows);
    }

    public function unavailabilityCount(): int
    {
        return array_sum(array_map(static fn (MemberCollectionRow $row): int => $row->unavailabilityCount, $this->rows));
    }

    private function countState(MemberCollectionState $state): int
    {
        return \count(array_filter($this->rows, static fn (MemberCollectionRow $row): bool => $row->state === $state));
    }
}
