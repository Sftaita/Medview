<?php

declare(strict_types=1);

namespace App\Entity;

/** A reminder is N days or N weeks before the target generation date (docs/decisions.md D210). */
enum ReminderOffsetUnit: string
{
    case DAY = 'DAY';
    case WEEK = 'WEEK';

    public function maximum(): int
    {
        return self::DAY === $this ? 365 : 52;
    }

    public function days(int $value): int
    {
        return self::DAY === $this ? $value : 7 * $value;
    }
}
