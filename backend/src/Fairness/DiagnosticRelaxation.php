<?php

declare(strict_types=1);

namespace App\Fairness;

use App\Eligibility\ConstraintTier;
use App\Eligibility\ExclusionReason;

/**
 * Layer D of the UNSAT model (docs/allocation-algorithm.md §16) — a
 * conditional, never-certain suggestion that relaxing one POLICY_HARD
 * rule *might* restore full coverage. `$tier` is derived from `$ruleCode`
 * (`ExclusionReason::tier()`) and the constructor refuses anything but
 * `POLICY_HARD` — the same "structurally impossible to misuse" pattern as
 * `EligibilityExclusion::$tier` — so a HARD constraint (`UNAVAILABLE`,
 * `MEMBERSHIP_OUT_OF_RANGE`, `LEGAL_MIN_REST`, ...) can never be proposed
 * as a relaxation (docs/allocation-algorithm.md §16: "jamais sur une
 * contrainte HARD").
 *
 * `$phrasing` must stay conditional ("relâcher X permettrait...", never
 * "X est la cause") — several constraint sets can independently explain
 * one UNSAT, so no single relaxation is ever presented as *the* cause.
 * `docs/decisions.md` D097: no `EligibilityService` reason is
 * `POLICY_HARD`-*produced* today (`docs/eligibility.md` §3), so this type
 * exists but nothing in `src/` constructs an instance yet — the contract
 * is ready, `diagnosticRelaxations` stays `[]` in every real result.
 */
final readonly class DiagnosticRelaxation
{
    public ConstraintTier $tier;

    public function __construct(
        public ExclusionReason $ruleCode,
        public string $phrasing,
        public string $disclaimer = 'Une relaxation possible parmi d\'autres — pas nécessairement la cause unique.',
    ) {
        if (ConstraintTier::POLICY_HARD !== $ruleCode->tier()) {
            throw new \InvalidArgumentException(sprintf('A DiagnosticRelaxation can only ever propose a POLICY_HARD rule, %s is %s.', $ruleCode->value, $ruleCode->tier()->value));
        }

        $this->tier = ConstraintTier::POLICY_HARD;
    }
}
