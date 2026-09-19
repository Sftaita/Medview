<?php

declare(strict_types=1);

namespace App\Eligibility;

/**
 * docs/allocation-algorithm.md §3. HARD is never negotiable, never
 * proposed as a relaxation. POLICY_HARD blocks a solve exactly like HARD
 * but is the only tier a future UNSAT diagnostic may propose relaxing.
 * SOFT never blocks — it is an optimization objective, not evaluated by
 * EligibilityService at all (this lot only ever produces HARD/POLICY_HARD
 * exclusions).
 */
enum ConstraintTier: string
{
    case HARD = 'HARD';
    case POLICY_HARD = 'POLICY_HARD';
    case SOFT = 'SOFT';
}
