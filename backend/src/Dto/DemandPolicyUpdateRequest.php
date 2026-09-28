<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * The body of PUT /api/planning-lines/{id}/demand-policy
 * (docs/decisions.md D162), already shape-checked by the controller
 * (types, unknown fields). Business rules are PlanningLineDemandPolicyService's.
 */
final readonly class DemandPolicyUpdateRequest
{
    /**
     * @param list<DemandTriggerInput> $triggers
     */
    public function __construct(
        public int $schemaVersion,
        public string $mode,
        public ?string $sourceLineStableId,
        public array $triggers,
    ) {
    }
}
