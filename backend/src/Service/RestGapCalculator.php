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
        return $this->gapHoursBetween($a->getStartsAt(), $a->getEndsAt(), $b->getStartsAt(), $b->getEndsAt());
    }

    /**
     * Same arithmetic on two raw non-overlapping intervals — for a duty
     * held on another line and frozen as an interval in a snapshot
     * (PlanningSnapshotExternalCommitment, docs/decisions.md D161), where
     * no live Duty is read.
     */
    public function gapHoursBetween(\DateTimeImmutable $aStart, \DateTimeImmutable $aEnd, \DateTimeImmutable $bStart, \DateTimeImmutable $bEnd): float
    {
        if ($aEnd <= $bStart) {
            $seconds = $bStart->getTimestamp() - $aEnd->getTimestamp();
        } else {
            $seconds = $aStart->getTimestamp() - $bEnd->getTimestamp();
        }

        return $seconds / 3600;
    }
}
