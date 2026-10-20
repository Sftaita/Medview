<?php

declare(strict_types=1);

namespace App\Exception;

use App\Service\BlockCut;

/**
 * 409 `historical_block_cut_confirmation_required` (docs/decisions.md D192):
 * the start of the period to create is the end of a locked previous period
 * that cuts a block whose earlier days were never materialized. The period
 * is created only once the manager confirms it
 * (`acceptHistoricalBlockCuts: true`): the days of each occurrence listed in
 * `details` (`remainderDays`) then become a remainder block of the new
 * period; nothing changes in the previous one (`earlierDays` stay without
 * duty).
 */
final class HistoricalBlockCutConfirmationRequiredException extends \RuntimeException implements ApiProblemWithDetails
{
    /**
     * @param non-empty-list<BlockCut> $cuts
     */
    public function __construct(public readonly array $cuts)
    {
        parent::__construct('The end of the previous period, which can no longer move, cuts a block: confirm that the days of each occurrence in the new period become a remainder block (acceptHistoricalBlockCuts).');
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'historical_block_cut_confirmation_required';
    }

    public function details(): array
    {
        return array_map(static fn (BlockCut $cut): array => $cut->toArray(), $this->cuts);
    }
}
