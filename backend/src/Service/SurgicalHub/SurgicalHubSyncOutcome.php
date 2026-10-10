<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

final readonly class SurgicalHubSyncOutcome
{
    public function __construct(
        public SurgicalHubSyncStatus $status,
        public ?SurgicalHubSyncError $error = null,
        public int $created = 0,
        public int $updated = 0,
        public int $removed = 0,
    ) {
    }

    public function isSuccessful(): bool
    {
        return SurgicalHubSyncStatus::SYNCED === $this->status;
    }
}
