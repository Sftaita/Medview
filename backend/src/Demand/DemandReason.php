<?php

declare(strict_types=1);

namespace App\Demand;

/**
 * Why a duty is, or is not, needed (docs/decisions.md D163) — always
 * explained, never a bare boolean.
 *
 * Intrinsic duties (every duty of an independent line):
 * - INTRINSIC_REQUIRED / INTRINSIC_OPTIONAL: its own demandType, unchanged
 *   semantics (OPTIONAL is not redefined).
 *
 * Conditional duties — the evaluation of the duty's own day:
 * - TRIGGERED: the holder of its coverage source triggers it that weekday;
 * - SOURCE_LINE_NOT_GENERATED: the source line has no calendar yet (no
 *   COMPLETED generation), so nobody holds the source duty;
 * - SOURCE_UNASSIGNED: the source line is generated but its duty has no
 *   holder right now;
 * - HOLDER_HAS_NO_TRIGGER: the holder has no trigger at all;
 * - WEEKDAY_NOT_TRIGGERED: the holder's trigger does not cover that weekday;
 * - LINE_NOT_CONDITIONAL: the line's rules are not conditional (never
 *   produced in practice: changing the mode of a materialized line is
 *   refused, D162).
 *
 * And at the level of a whole block (a conditional block is atomic):
 * - TRIGGERED_BY_BLOCK: this day is not triggered itself, but another day
 *   of its block is — the whole block is required.
 */
enum DemandReason: string
{
    case INTRINSIC_REQUIRED = 'INTRINSIC_REQUIRED';
    case INTRINSIC_OPTIONAL = 'INTRINSIC_OPTIONAL';
    case TRIGGERED = 'TRIGGERED';
    case TRIGGERED_BY_BLOCK = 'TRIGGERED_BY_BLOCK';
    case SOURCE_LINE_NOT_GENERATED = 'SOURCE_LINE_NOT_GENERATED';
    case SOURCE_UNASSIGNED = 'SOURCE_UNASSIGNED';
    case HOLDER_HAS_NO_TRIGGER = 'HOLDER_HAS_NO_TRIGGER';
    case WEEKDAY_NOT_TRIGGERED = 'WEEKDAY_NOT_TRIGGERED';
    case LINE_NOT_CONDITIONAL = 'LINE_NOT_CONDITIONAL';
}
