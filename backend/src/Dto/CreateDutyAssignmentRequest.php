<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input shape for POST .../assignments. Notably absent: any "source"
 * field — it is always forced to MANUAL server-side
 * (docs/planning-generation.md §17), never accepted from the client.
 */
final class CreateDutyAssignmentRequest
{
    #[Assert\NotBlank]
    public string $dutyStableId = '';

    #[Assert\NotBlank]
    public string $teamMemberStableId = '';

    public bool $locked = false;
}
