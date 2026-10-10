<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

/**
 * The SurgicalHub check of one launch: one entry per associated participant
 * (people without an association are never part of it, and never blocked).
 */
final readonly class SurgicalHubFreshnessReport
{
    /**
     * @param list<SurgicalHubParticipantFreshness> $participants
     */
    public function __construct(public array $participants)
    {
    }

    /** @return list<SurgicalHubParticipantFreshness> */
    public function blocking(): array
    {
        return $this->with(SurgicalHubFreshness::STALE_BLOCKING);
    }

    /** @return list<SurgicalHubParticipantFreshness> */
    public function warnings(): array
    {
        return $this->with(SurgicalHubFreshness::STALE_RECENT);
    }

    /** @return list<SurgicalHubParticipantFreshness> */
    private function with(SurgicalHubFreshness $freshness): array
    {
        return array_values(array_filter($this->participants, static fn (SurgicalHubParticipantFreshness $p): bool => $freshness === $p->freshness));
    }
}
