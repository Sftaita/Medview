<?php

declare(strict_types=1);

namespace App\Service;

/**
 * French date labels for emails and the planning PDF — calendar dates only
 * (a Duty's localDate, already in the Planning's timezone), never a
 * timezone conversion, never the server's locale.
 */
final class FrenchDate
{
    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    private const DAYS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    /** "1er octobre 2026". */
    public static function long(\DateTimeImmutable $date): string
    {
        $day = (int) $date->format('j');

        return (1 === $day ? '1er' : (string) $day).' '.self::MONTHS[(int) $date->format('n') - 1].' '.$date->format('Y');
    }

    /** "mardi 21 octobre 2026". */
    public static function withWeekday(\DateTimeImmutable $date): string
    {
        return self::weekday($date).' '.self::long($date);
    }

    /** "mardi". */
    public static function weekday(\DateTimeImmutable $date): string
    {
        return self::DAYS[(int) $date->format('N') - 1];
    }

    /** "Mar 21/10". */
    public static function short(\DateTimeImmutable $date): string
    {
        return ucfirst(mb_substr(self::weekday($date), 0, 3)).' '.$date->format('d/m');
    }

    /** "octobre 2026". */
    public static function month(\DateTimeImmutable $date): string
    {
        return self::MONTHS[(int) $date->format('n') - 1].' '.$date->format('Y');
    }

    /**
     * "mardi 21 octobre 2026" for one day, "du vendredi 23 au dimanche 25
     * octobre 2026" for consecutive days (year/month repeated only when
     * they differ).
     */
    public static function range(\DateTimeImmutable $first, \DateTimeImmutable $last): string
    {
        if ($first->format('Y-m-d') === $last->format('Y-m-d')) {
            return self::withWeekday($first);
        }

        $start = self::weekday($first).' '.(1 === (int) $first->format('j') ? '1er' : $first->format('j'));
        if ($first->format('Y-m') !== $last->format('Y-m')) {
            $start .= ' '.self::MONTHS[(int) $first->format('n') - 1];
        }
        if ($first->format('Y') !== $last->format('Y')) {
            $start .= ' '.$first->format('Y');
        }

        return 'du '.$start.' au '.self::withWeekday($last);
    }
}
