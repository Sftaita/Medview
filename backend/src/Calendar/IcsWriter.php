<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Minimal RFC 5545 writer for a published, read-only calendar of all-day
 * events (docs/decisions.md D170) — deliberately no library: the subset is
 * small (VCALENDAR + VEVENT, DATE values only) and its three pitfalls are
 * covered here and in IcsWriterTest: CRLF line endings, TEXT escaping
 * (\ ; , and newlines) and folding of lines longer than 75 octets without
 * ever splitting a UTF-8 character.
 */
final class IcsWriter
{
    private const MAX_OCTETS = 75;

    /**
     * @param list<IcsEvent> $events
     */
    public function write(string $calendarName, array $events, \DateTimeImmutable $now): string
    {
        $stamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MedVue//Mes gardes//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'NAME:'.self::escape($calendarName),
            'X-WR-CALNAME:'.self::escape($calendarName),
            // A hint only: Google ignores it and polls on its own schedule (up to a day).
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
            'X-PUBLISHED-TTL:PT6H',
        ];

        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.self::escape($event->uid);
            $lines[] = 'DTSTAMP:'.$stamp;
            $lines[] = 'DTSTART;VALUE=DATE:'.$event->firstDay->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$event->lastDay->modify('+1 day')->format('Ymd');
            $lines[] = 'SUMMARY:'.self::escape($event->summary);
            if ('' !== $event->description) {
                $lines[] = 'DESCRIPTION:'.self::escape($event->description);
            }
            if (null !== $event->url) {
                $lines[] = 'URL:'.$event->url;
            }
            $lines[] = 'STATUS:'.($event->tentative ? 'TENTATIVE' : 'CONFIRMED');
            // A duty blocks the whole day in free/busy views.
            $lines[] = 'TRANSP:OPAQUE';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode('', array_map(static fn (string $line): string => self::fold($line)."\r\n", $lines));
    }

    /** RFC 5545 §3.3.11 TEXT escaping. */
    public static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $text);
    }

    /** RFC 5545 §3.1: at most 75 octets per line, continuation lines start with one space. */
    public static function fold(string $line): string
    {
        $folded = '';
        $current = '';
        $limit = self::MAX_OCTETS;
        foreach (mb_str_split($line, 1, 'UTF-8') as $char) {
            if (\strlen($current) + \strlen($char) > $limit) {
                $folded .= $current."\r\n ";
                $current = '';
                // The leading space counts towards the 75 octets of a continuation line.
                $limit = self::MAX_OCTETS - 1;
            }
            $current .= $char;
        }

        return $folded.$current;
    }
}
