<?php

declare(strict_types=1);

namespace App\Service;

/**
 * "I am still working" signal for long engine work (docs/decisions.md
 * D149). Called frequently — between pipeline steps and every few hundred
 * milliseconds while the CP-SAT subprocess runs — so implementations must
 * throttle themselves. Outside a worker job it is a no-op.
 */
interface WorkHeartbeat
{
    public function beat(): void;
}
