<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningPublicationLineKind;

/**
 * One line's outcome of a publication (docs/decisions.md D133, D196):
 * `kind` says what this publication did to it — FIRST_FOR_LINE, UPDATED, or
 * null (already published and unchanged, or left as it was: not selected,
 * not ready). `alreadyPublished` distinguishes a line already PUBLISHED
 * before the call from one this call transitioned — never conflated.
 */
final readonly class PublicationLineResult
{
    public function __construct(
        public PlanningLine $line,
        public PlanningPeriodStatus $periodStatus,
        public bool $alreadyPublished,
        public ?PlanningPublicationLineKind $kind = null,
    ) {
    }
}
