<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\PlanningLine;

/**
 * "Compléter automatiquement" asked on a period whose every generated line
 * is published, validated or archived (docs/decisions.md D223): such a line
 * is never completed automatically, so no job is queued and nothing is
 * written. Its holes are filled one by one (manual reassignment), then
 * republished.
 */
final class PublishedLinesNotCompletedException extends \RuntimeException
{
    /**
     * @param non-empty-list<PlanningLine> $lines the locked lines
     */
    public function __construct(public readonly array $lines)
    {
        parent::__construct('A published line is never completed automatically: assign its duties one by one, then republish.');
    }
}
