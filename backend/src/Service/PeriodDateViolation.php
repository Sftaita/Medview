<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\PeriodBoundarySplitsBlockException;
use App\Exception\PlanningCycleException;

/**
 * One reason a date of a period is refused (docs/decisions.md D207): the
 * error code of the API (the same one a write answers), its HTTP status, a
 * developer hint, and the blocks concerned when a block would be cut.
 */
final readonly class PeriodDateViolation
{
    public const SPLITS_BLOCK = 'period_boundary_splits_block';

    /**
     * @param list<BlockCut> $cuts
     */
    public function __construct(
        public string $code,
        public int $status,
        public string $message,
        public array $cuts = [],
    ) {
    }

    /** @param list<BlockCut> $cuts */
    public static function splitsBlock(array $cuts): self
    {
        return new self(self::SPLITS_BLOCK, 422, 'A block of duties would be cut in two.', $cuts);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self($code, 409, $message);
    }

    public static function invalid(string $code, string $message): self
    {
        return new self($code, 422, $message);
    }

    /**
     * What a write throws: exactly what the date options announced.
     */
    public function toException(): \RuntimeException
    {
        if (self::SPLITS_BLOCK === $this->code && [] !== $this->cuts) {
            return new PeriodBoundarySplitsBlockException($this->cuts);
        }

        return 422 === $this->status
            ? PlanningCycleException::invalid($this->code, $this->message)
            : PlanningCycleException::conflict($this->code, $this->message);
    }

    /**
     * @return array{code: string, cuts?: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        $array = ['code' => $this->code];
        if ([] !== $this->cuts) {
            $array['cuts'] = array_map(static fn (BlockCut $cut): array => $cut->toArray(), $this->cuts);
        }

        return $array;
    }
}
