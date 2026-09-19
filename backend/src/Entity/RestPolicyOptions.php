<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Per-`PlanningGeneration` rest-policy choice (docs/decisions.md D105) —
 * deliberately **not** a team-wide default: `LEGAL_MIN_REST` and
 * `TEAM_MIN_REST` are options a planner picks for *this* generation, not
 * constants imposed on every planning. Immutable and structurally
 * impossible to construct invalid, the same "validate in the constructor,
 * never trust the caller" pattern as `AssignmentConflict`/`DiagnosticRelaxation`:
 *
 * - `*Hours` is required (and strictly positive) exactly when its `*Enabled`
 *   flag is true, and must be absent (`null`) when it is false — never a
 *   silent default of either kind.
 * - When both are enabled, `teamMinRestHours >= legalMinRestHours` is
 *   enforced — a team policy that claims to be "more protective" can never
 *   actually be laxer than the legal floor it is layered on top of.
 *
 * `legalMinRestHours` is never defaulted to a guessed value (e.g. "11h
 * because it's common") — MedVue has no automatic source of truth for a
 * legal rest floor (D036); a planner must supply it explicitly every time
 * `legalMinRestEnabled` is true.
 */
final readonly class RestPolicyOptions
{
    public function __construct(
        public bool $legalMinRestEnabled,
        public ?int $legalMinRestHours,
        public bool $teamMinRestEnabled,
        public ?int $teamMinRestHours,
    ) {
        self::assertHoursMatchesEnabled('legalMinRestHours', $legalMinRestEnabled, $legalMinRestHours);
        self::assertHoursMatchesEnabled('teamMinRestHours', $teamMinRestEnabled, $teamMinRestHours);

        if ($legalMinRestEnabled && $teamMinRestEnabled && $teamMinRestHours < $legalMinRestHours) {
            throw new \InvalidArgumentException(sprintf('teamMinRestHours (%d) must be >= legalMinRestHours (%d) when both rest policies are enabled — an internal policy can never claim to be more protective while actually being laxer than the legal floor.', $teamMinRestHours, $legalMinRestHours));
        }
    }

    /**
     * Both policies disabled — CONFLICT (HARD) still always applies
     * regardless (docs/planning-solver.md), only the two rest policies
     * are optional.
     */
    public static function none(): self
    {
        return new self(false, null, false, null);
    }

    private static function assertHoursMatchesEnabled(string $field, bool $enabled, ?int $hours): void
    {
        if ($enabled) {
            if (null === $hours || $hours <= 0) {
                throw new \InvalidArgumentException(sprintf('%s must be a strictly positive integer when its policy is enabled.', $field));
            }

            return;
        }

        if (null !== $hours) {
            throw new \InvalidArgumentException(sprintf('%s must be null when its policy is disabled — never silently ignored.', $field));
        }
    }
}
