<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One person of the absence export (docs/availability.md §11): identified by
 * their User stableId — appears once, whatever the number of their
 * memberships in the planning. Read model only.
 */
final readonly class PlanningAbsenceExportMember
{
    /**
     * @param list<string>                      $lineNames      lines of the planning the person belongs to during the period, in line order
     * @param list<string>                      $days           civil days ("Y-m-d", sorted, distinct) touched by an UNAVAILABLE period, within the planning period and the person's memberships
     * @param list<array{0: string, 1: string}> $membershipRuns the days of the planning period the person is a member (inclusive runs)
     */
    public function __construct(
        public string $userStableId,
        public string $firstName,
        public string $lastName,
        public array $lineNames,
        public array $days,
        public array $membershipRuns,
    ) {
    }

    public function displayName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    /**
     * @return list<array{0: string, 1: string}> consecutive absence days grouped — presentation only
     */
    public function absenceRuns(): array
    {
        return AbsenceDays::runs($this->days);
    }
}
