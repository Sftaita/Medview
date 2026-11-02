<?php

declare(strict_types=1);

namespace App\Demand;

/**
 * Where a conditional line's duties and blocks come from (docs/decisions.md
 * D224).
 *
 * - OWN: the line's own weekly structure, materialized whatever the source
 *   holders, one triggered day requiring its whole block (D163/D164). Kept
 *   only to read what was recorded before D224 — no new policy is ever
 *   written with it.
 * - INHERITED: no structure of its own. A reinforcement duty is created
 *   only for a source duty whose holder triggers it, with that duty's exact
 *   times; the triggered days of one source block form one block (two days
 *   or more), a single one is a standalone duty. Never a duty on a day that
 *   triggers nothing.
 */
enum StructureSource: string
{
    case OWN = 'OWN';
    case INHERITED = 'INHERITED';
}
