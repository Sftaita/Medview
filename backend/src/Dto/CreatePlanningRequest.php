<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input shape for POST /api/plannings. Notably absent: any "creator"
 * field — always forced to #[CurrentUser] server-side (docs/planning.md
 * §Autorisations), never accepted from the client. Also notably absent:
 * any team identifier — a Planning's primary PlanningTeam is always
 * created fresh, inline, from $primaryTeamName; a client can never
 * reference an existing team (docs/decisions.md D079).
 */
final class CreatePlanningRequest
{
    #[Assert\NotBlank]
    public string $name = '';

    #[Assert\NotBlank]
    public string $startsAt = '';

    #[Assert\NotBlank]
    public string $endsAt = '';

    #[Assert\NotBlank]
    public string $timezone = '';

    /**
     * Read from the nested `primaryTeam.name` field of the request body —
     * see PlanningController::deserializeAndValidate().
     */
    #[Assert\NotBlank]
    public string $primaryTeamName = '';

    /**
     * "M'inclure dans le planning": makes the creator a participant of the
     * primary line (an open membership) — independent of their right to
     * manage the Planning (docs/decisions.md D123). Defaults to false, the
     * behaviour before this option existed.
     */
    public bool $includeMe = false;
}
