<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * One deterministic capacity finding (docs/allocation-algorithm.md §16).
 * See `StructuralDiagnosticCode` for why only `NO_ELIGIBLE_CANDIDATE` is
 * produced today.
 */
final readonly class StructuralDiagnostic
{
    public function __construct(
        public StructuralDiagnosticCode $code,
        public string $dutyUnitStableKey,
    ) {
    }
}
