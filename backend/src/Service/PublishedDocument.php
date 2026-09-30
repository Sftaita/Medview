<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What a publication email attaches and says about the planning
 * (docs/decisions.md D173): the PDF bytes and the planning's name and
 * period as they were when the publication was recorded — never the
 * planning's current ones, which an extension or a rename may have changed.
 */
final readonly class PublishedDocument
{
    public function __construct(
        public string $content,
        public string $filename,
        public string $planningName,
        public \DateTimeImmutable $firstDay,
        /** inclusive */
        public \DateTimeImmutable $lastDay,
    ) {
    }

    /** "du 1er octobre 2026 au 31 décembre 2026". */
    public function periodLabel(): string
    {
        return 'du '.FrenchDate::long($this->firstDay).' au '.FrenchDate::long($this->lastDay);
    }
}
