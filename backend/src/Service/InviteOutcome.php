<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningTeamMember;
use App\Entity\TeamInvitation;

final class InviteOutcome
{
    /**
     * @param bool $emailSent false when a notification was due but could not be handed to the mail transport
     *                        (the database change stands regardless); true when nothing needed sending
     */
    public function __construct(
        public readonly InviteStatus $status,
        public readonly ?PlanningTeamMember $member = null,
        public readonly ?TeamInvitation $invitation = null,
        public readonly bool $emailSent = true,
    ) {
    }
}
