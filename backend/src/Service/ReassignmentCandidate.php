<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One PlanningTeamMember as a candidate for a live reassignment
 * (docs/decisions.md D131) — always the whole live candidate pool, never
 * filtered down to only the selectable ones (§9 of the spec: "je veux
 * comprendre pourquoi un médecin ne peut pas être choisi").
 */
final readonly class ReassignmentCandidate
{
    /**
     * @param list<string> $blockingReasons translated labels (ExclusionReasonLabeler) — never empty when !$selectable, always empty when $selectable
     */
    public function __construct(
        public string $teamMemberStableId,
        public string $firstName,
        public string $lastName,
        public bool $selectable,
        public bool $isCurrent,
        public array $blockingReasons,
    ) {
    }
}
