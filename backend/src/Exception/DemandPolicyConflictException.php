<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A demand policy that is valid in itself but cannot be applied in the
 * current state of the Planning (docs/decisions.md D162) — 409 with a
 * stable code:
 *
 * - `planning_already_published`: a line of the Planning is published, so
 *   no line can be generated again for this period (D133); a conditional
 *   line configured now could never be used (V1 limitation, D1);
 * - `line_already_materialized`: the line's duties already exist, and a
 *   change of mode or source would contradict them (their demand kind is
 *   fixed at materialization);
 * - `concurrent_update`: another change of the same line's policy won.
 */
final class DemandPolicyConflictException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
