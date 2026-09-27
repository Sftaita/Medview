<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningPublication;

/**
 * The result of a real diffusion (docs/decisions.md D143): the recorded
 * PlanningPublication, each line's resulting status, and how many people
 * were emailed (and how many emails the transport accepted).
 */
final readonly class PublicationOutcome
{
    /**
     * @param list<PublicationLineResult> $lines
     */
    public function __construct(
        public PlanningPublication $publication,
        public array $lines,
        public int $recipientCount,
        public int $sentCount,
    ) {
    }
}
