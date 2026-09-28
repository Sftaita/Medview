<?php

declare(strict_types=1);

namespace App\Eligibility;

/**
 * Why a block of duties is incompatible with a duty the same person already
 * holds (PersonCommitmentChecker, docs/decisions.md D161): the reason and
 * the commitment that caused it — never a reason without its evidence.
 */
final readonly class CommitmentViolation
{
    public function __construct(
        public ExclusionReason $reason,
        public CommitmentInterval $commitment,
    ) {
    }
}
