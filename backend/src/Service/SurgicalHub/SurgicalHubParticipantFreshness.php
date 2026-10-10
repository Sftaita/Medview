<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

use App\Entity\SurgicalHubLink;
use App\Entity\User;

final readonly class SurgicalHubParticipantFreshness
{
    public function __construct(
        public User $user,
        public SurgicalHubLink $link,
        public SurgicalHubFreshness $freshness,
        public ?SurgicalHubSyncError $error,
    ) {
    }
}
