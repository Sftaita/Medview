<?php

declare(strict_types=1);

namespace App\Exception;

use App\Service\BlockCut;

/**
 * 422 `period_boundary_splits_block` (docs/planning-cycles.md §5.1, lot L3,
 * option A): a boundary between two periods would cut an atomic block of
 * duties, which would then be materialized in neither period. Refused when
 * a period is created, when the end of the last one moves, and when a
 * line's weekly structure changes — `details` lists every occurrence cut.
 */
final class PeriodBoundarySplitsBlockException extends \RuntimeException implements ApiProblemWithDetails
{
    /**
     * @param non-empty-list<BlockCut> $cuts
     */
    public function __construct(public readonly array $cuts)
    {
        $first = $cuts[0];
        parent::__construct(\sprintf(
            'The boundary of %s cuts the block "%s"%s (%s → %s): a block is never split between two periods. Choose another date, or another block.',
            $first->boundary->format('Y-m-d'),
            $first->blockName,
            null !== $first->line ? \sprintf(' of the line "%s"', $first->line->getName()) : '',
            $first->firstDay->format('Y-m-d'),
            $first->lastDay->format('Y-m-d'),
        ));
    }

    public function statusCode(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'period_boundary_splits_block';
    }

    public function details(): array
    {
        return array_map(static fn (BlockCut $cut): array => $cut->toArray(), $this->cuts);
    }
}
