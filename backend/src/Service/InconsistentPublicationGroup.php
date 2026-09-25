<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DutyGroupInstance;

/**
 * An atomic block whose constituent Duties do not currently share the same
 * assignee (docs/decisions.md D133 §4) — should never happen given the
 * atomicity Sub-lot A enforces, but the publication preflight is an
 * independent defense, not a re-implementation of that guarantee.
 */
final readonly class InconsistentPublicationGroup
{
    public function __construct(
        public DutyGroupInstance $group,
    ) {
    }
}
