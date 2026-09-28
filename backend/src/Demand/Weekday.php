<?php

declare(strict_types=1);

namespace App\Demand;

/**
 * A day of the week, ISO order (Monday first) — the unit a demand trigger
 * is expressed in (docs/decisions.md D162). Its value is the stable API
 * code; weekStructureCode() maps it to the French day codes of the weekly
 * structure (docs/week-structure.md: LUN..DIM), never the other way round
 * by guessing.
 */
enum Weekday: string
{
    case MONDAY = 'MONDAY';
    case TUESDAY = 'TUESDAY';
    case WEDNESDAY = 'WEDNESDAY';
    case THURSDAY = 'THURSDAY';
    case FRIDAY = 'FRIDAY';
    case SATURDAY = 'SATURDAY';
    case SUNDAY = 'SUNDAY';

    private const WEEK_STRUCTURE_CODES = ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'];

    /** 1 = Monday .. 7 = Sunday (ISO-8601, PHP's `N` format). */
    public function isoNumber(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    public static function fromIsoNumber(int $isoNumber): self
    {
        return self::cases()[$isoNumber - 1] ?? throw new \InvalidArgumentException(\sprintf('ISO weekday must be 1..7, got %d.', $isoNumber));
    }

    /** The weekday of a calendar date — its own wall-clock date, never an instant converted to another zone. */
    public static function ofDate(\DateTimeImmutable $localDate): self
    {
        return self::fromIsoNumber((int) $localDate->format('N'));
    }

    public function weekStructureCode(): string
    {
        return self::WEEK_STRUCTURE_CODES[$this->isoNumber() - 1];
    }

    public static function fromWeekStructureCode(string $code): self
    {
        $index = array_search($code, self::WEEK_STRUCTURE_CODES, true);
        if (false === $index) {
            throw new \InvalidArgumentException(\sprintf('Unknown week-structure day code "%s".', $code));
        }

        return self::cases()[$index];
    }

    /**
     * @param iterable<self> $weekdays
     *
     * @return list<self> without duplicates, in ISO order
     */
    public static function sorted(iterable $weekdays): array
    {
        $byNumber = [];
        foreach ($weekdays as $weekday) {
            $byNumber[$weekday->isoNumber()] = $weekday;
        }
        ksort($byNumber);

        return array_values($byNumber);
    }
}
