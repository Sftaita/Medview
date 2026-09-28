<?php

declare(strict_types=1);

namespace App\Demand;

use App\Eligibility\DutyUnit;
use App\Entity\Duty;

/**
 * The one way to ask "is this duty needed, and why?" (docs/decisions.md
 * D163). Two kinds, never mixed:
 *
 * - LIVE (LiveDemandView): the current calendar — who holds each source
 *   duty right now (DutyAssignment.current). For the calendar,
 *   reassignment, completion, publication, statistics, exports, emails.
 * - SNAPSHOT (next lot): what a generation decided and froze — never
 *   rebuilt by re-reading live data.
 *
 * Both answer through DemandCalculator, so the rule (triggers, weekday,
 * block atomicity) exists once.
 */
interface DemandView
{
    public function forDuty(Duty $duty): DutyDemand;

    /** Same answer for every duty of the unit (a block is atomic). */
    public function forUnit(DutyUnit $unit): UnitDemand;
}
