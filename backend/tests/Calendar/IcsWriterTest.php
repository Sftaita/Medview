<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\IcsEvent;
use App\Calendar\IcsWriter;
use PHPUnit\Framework\TestCase;

/**
 * RFC 5545 subset of the duty calendar feed (docs/decisions.md D170).
 */
final class IcsWriterTest extends TestCase
{
    public function testAnAllDayEventEndsTheDayAfterItsLastDay(): void
    {
        $ics = $this->writeOne(new IcsEvent('u1@medvue', new \DateTimeImmutable('2027-01-09'), new \DateTimeImmutable('2027-01-10'), 'Garde', ''));

        self::assertStringContainsString("DTSTART;VALUE=DATE:20270109\r\n", $ics);
        self::assertStringContainsString("DTEND;VALUE=DATE:20270111\r\n", $ics, 'DTEND is exclusive: a Saturday + Sunday block ends on Monday.');
    }

    public function testASingleDayEventAcrossAMonthAndYearBoundary(): void
    {
        $ics = $this->writeOne(new IcsEvent('u1@medvue', new \DateTimeImmutable('2027-12-31'), new \DateTimeImmutable('2027-12-31'), 'Garde', ''));

        self::assertStringContainsString("DTSTART;VALUE=DATE:20271231\r\nDTEND;VALUE=DATE:20280101\r\n", $ics);
    }

    public function testCalendarEnvelopeAndEventProperties(): void
    {
        $ics = (new IcsWriter())->write('MedVue — Mes gardes', [
            new IcsEvent('a@medvue', new \DateTimeImmutable('2027-01-05'), new \DateTimeImmutable('2027-01-05'), 'Garde Seniors', 'Planning : Urgences', url: 'https://medvue.be/plannings/p1'),
            new IcsEvent('b@medvue', new \DateTimeImmutable('2027-01-06'), new \DateTimeImmutable('2027-01-06'), 'Renfort', '', tentative: true),
        ], new \DateTimeImmutable('2026-12-10 09:00:00', new \DateTimeZone('Europe/Brussels')));

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertStringContainsString("X-WR-CALNAME:MedVue — Mes gardes\r\n", $ics);
        self::assertStringContainsString("METHOD:PUBLISH\r\n", $ics);
        self::assertSame(2, substr_count($ics, "BEGIN:VEVENT\r\n"));
        self::assertSame(2, substr_count($ics, "DTSTAMP:20261210T080000Z\r\n"), 'DTSTAMP in UTC.');
        self::assertStringContainsString("UID:a@medvue\r\n", $ics);
        self::assertStringContainsString("URL:https://medvue.be/plannings/p1\r\n", $ics);
        self::assertStringContainsString("STATUS:CONFIRMED\r\n", $ics);
        self::assertStringContainsString("STATUS:TENTATIVE\r\n", $ics);
        self::assertSame(1, substr_count($ics, 'DESCRIPTION:'), 'No empty DESCRIPTION.');
        self::assertDoesNotMatchRegularExpression("/(?<!\r)\n/", $ics, 'Every line ends with CRLF.');
    }

    public function testTextIsEscaped(): void
    {
        self::assertSame('a\\\\b\;c\,d\ne\nf', IcsWriter::escape("a\\b;c,d\ne\r\nf"));
    }

    public function testNoEventsIsStillAValidCalendar(): void
    {
        $ics = (new IcsWriter())->write('X', [], new \DateTimeImmutable());

        self::assertStringNotContainsString('BEGIN:VEVENT', $ics);
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
    }

    public function testLongLinesAreFoldedAt75OctetsWithoutSplittingACharacter(): void
    {
        $line = 'SUMMARY:'.str_repeat('é', 100);
        $folded = IcsWriter::fold($line);

        $parts = explode("\r\n", $folded);
        self::assertGreaterThan(1, \count($parts));
        foreach ($parts as $i => $part) {
            self::assertLessThanOrEqual(75, \strlen($part), "Line {$i} is at most 75 octets, continuation space included.");
            self::assertTrue(mb_check_encoding($part, 'UTF-8'), "Line {$i} never cuts a UTF-8 character.");
            if ($i > 0) {
                self::assertStringStartsWith(' ', $part);
            }
        }
        // Unfolding (RFC 5545 §3.1) gives the original line back.
        self::assertSame($line, str_replace("\r\n ", '', $folded));
    }

    public function testAShortLineIsLeftAlone(): void
    {
        self::assertSame(str_repeat('a', 75), IcsWriter::fold(str_repeat('a', 75)));
        self::assertSame(str_repeat('a', 75)."\r\n a", IcsWriter::fold(str_repeat('a', 76)));
    }

    public function testAnEventCannotEndBeforeItStarts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IcsEvent('u', new \DateTimeImmutable('2027-01-10'), new \DateTimeImmutable('2027-01-09'), 'x', '');
    }

    private function writeOne(IcsEvent $event): string
    {
        return (new IcsWriter())->write('X', [$event], new \DateTimeImmutable('2026-12-10'));
    }
}
