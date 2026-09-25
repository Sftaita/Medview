<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;

/**
 * The rest gap in hours between two non-overlapping Duties, in whichever
 * chronological order they actually fall — always exact instant
 * arithmetic (Unix timestamps), never local wall-clock, so a DST
 * transition between the two never skews the result
 * (docs/allocation-algorithm.md). Extracted out of AssignmentConflictAnalyzer
 * (docs/decisions.md D131) so the live reassignment check
 * (ReassignmentCandidateService) can never silently diverge from the exact
 * same arithmetic the solver's own snapshot-time analysis uses.
 */
final class RestGapCalculator
{
    public function gapHours(Duty $a, Duty $b): float
    {
        if ($a->getEndsAt() <= $b->getStartsAt()) {
            $seconds = $b->getStartsAt()->getTimestamp() - $a->getEndsAt()->getTimestamp();
        } else {
            $seconds = $a->getStartsAt()->getTimestamp() - $b->getEndsAt()->getTimestamp();
        }

        return $seconds / 3600;
    }
}
