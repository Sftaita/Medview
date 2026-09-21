<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Input shape for POST /api/plannings/{planningStableId}/extensions: the
 * planning's NEW full range (either bound may be omitted to keep it) and an
 * optional answer deadline for the availability collection(s) it opens.
 * At least one bound must actually move — see AvailabilityWindowCalculator.
 */
final class ExtendPlanningRequest
{
    /** "YYYY-MM-DD" or empty (= keep the current start). */
    public string $startsAt = '';

    /** "YYYY-MM-DD" or empty (= keep the current end). endsAt is exclusive, like Planning::$endsAt. */
    public string $endsAt = '';

    /** "YYYY-MM-DD" or empty (= no deadline). */
    public string $deadline = '';
}
