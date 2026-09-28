<?php

declare(strict_types=1);

namespace App\Demand;

/**
 * Who holds one source duty, as the demand calculation needs it
 * (docs/decisions.md D163) — supplied by the view: from
 * DutyAssignment.current for the LIVE view, from a generation's frozen
 * decisions for the SNAPSHOT view (next lot). The calculation never reads
 * it itself.
 */
final readonly class SourceHolding
{
    /**
     * @param bool        $sourceLineGenerated the source line has a calendar (a COMPLETED generation)
     * @param string|null $holderUserStableId  the person holding the source duty, null when nobody does
     */
    public function __construct(
        public bool $sourceLineGenerated,
        public ?string $holderUserStableId,
    ) {
        if (!$sourceLineGenerated && null !== $holderUserStableId) {
            throw new \InvalidArgumentException('Nobody can hold a duty of a line that has never been generated.');
        }
    }

    public static function notGenerated(): self
    {
        return new self(false, null);
    }
}
