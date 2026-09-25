<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input shape for POST .../duties/{duty}/reassign (docs/decisions.md
 * D131). `expectedCurrentTeamMemberStableId` is the concurrency check
 * (§Concurrence) — the identity of whoever the client believes currently
 * holds this block, `null` when it believes the block is uncovered; never
 * optional/omittable, so a stale client can never accidentally skip the
 * check by leaving it out. Notably absent: any "source" field — always
 * forced to MANUAL server-side, like CreateDutyAssignmentRequest.
 */
final class ReassignDutyRequest
{
    #[Assert\NotBlank]
    public string $teamMemberStableId = '';

    public ?string $expectedCurrentTeamMemberStableId = null;
}
