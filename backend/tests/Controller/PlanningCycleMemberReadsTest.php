<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\WeeklyDutyReminderRepository;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\PlanningCycleTestHelpers;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * What members read of a planning with several periods, lot L4e
 * (docs/decisions.md D198): "Mes gardes", the calendar feed and Saturday's
 * reminder show the duties of every period whose own status is PUBLISHED —
 * never the planning's status as a whole — once each, with their period.
 *
 * Fixture (pilotScenario, LEGAL_MIN_REST 11 h, Europe/Brussels): period 1
 * 2027-01-01 → 2027-05-01, period 2 → 2027-08-01, period 3 → 2027-11-01.
 * Holders, forced by the manager:
 *
 *   period 1  Tue 27 Apr 08-20                                 bob
 *             Fri 30 Apr 20:00 → Sat 1 May 08:00 (the night)   alice
 *   period 2  Sun 2 May 10-18                                  alice
 *             Tue 4 May 08-20                                  admin
 *   period 3  Tue 3 Aug 08-20                                  alice
 */
final class PlanningCycleMemberReadsTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;
    use PlanningCycleTestHelpers;

    private const REST = ['legalMinRestEnabled' => true, 'legalMinRestHours' => 11];
    /** Seen from it, next week is Monday 26 April → Monday 3 May: across the boundary of periods 1 and 2. */
    private const SATURDAY = '2027-04-24 08:00:00 Europe/Brussels';

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    /** @var array<string, string> name → duty stableId */
    private array $d = [];

    protected function setUp(): void
    {
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
    }

    // --- helpers ---------------------------------------------------------------------------------

    /** @param list<int> $published the periods published, in this order */
    private function scenario(array $published): void
    {
        $this->createPeriod('2027-08-01');
        $this->createPeriod('2027-11-01');
        $this->addDuties(1, [['2027-04-27 08:00', '2027-04-27 20:00'], ['2027-04-30 20:00', '2027-05-01 08:00']]);
        $this->addDuties(2, [['2027-05-02 10:00', '2027-05-02 18:00'], ['2027-05-04 08:00', '2027-05-04 20:00']]);
        $this->addDuties(3, [['2027-08-03 08:00', '2027-08-03 20:00']]);
        foreach ([1, 2, 3] as $number) {
            $this->generatePeriod($number, self::REST);
        }
        $this->d = [
            'T1' => $this->dutyIn(1, '2027-04-27'),
            'N1' => $this->dutyIn(1, '2027-04-30'),
            'S2' => $this->dutyIn(2, '2027-05-02'),
            'C2' => $this->dutyIn(2, '2027-05-04'),
            'P3' => $this->dutyIn(3, '2027-08-03'),
        ];
        $this->forceHolders([
            $this->d['T1'] => 'bob@example.com',
            $this->d['N1'] => 'alice@example.com',
            $this->d['S2'] => 'alice@example.com',
            $this->d['C2'] => 'admin@example.com',
            $this->d['P3'] => 'alice@example.com',
        ]);
        foreach ($published as $number) {
            $this->publishPeriod($number);
        }
    }

    /** @return list<array<string, mixed>> */
    private function myDuties(string $who): array
    {
        $duties = $this->api($this->client, 'GET', '/api/me/duties', token: $this->s[$who])['duties'];
        self::assertResponseIsSuccessful();

        return $duties;
    }

    /** @return array<string, int> duty stableId → period number, in the order returned */
    private function periodsOf(string $who): array
    {
        $byDuty = [];
        foreach ($this->myDuties($who) as $duty) {
            self::assertArrayNotHasKey($duty['dutyStableId'], $byDuty, 'never twice');
            $byDuty[$duty['dutyStableId']] = $duty['period']['number'];
        }

        return $byDuty;
    }

    private function feedAddress(string $who): string
    {
        $feed = $this->api($this->client, 'POST', '/api/me/calendar-feed', token: $this->s[$who])['feed'];
        self::assertResponseIsSuccessful();

        return $feed['token'];
    }

    /** @return array<string, array{first: string, end: string}> UID → all-day span (end exclusive) */
    private function feedEvents(string $address): array
    {
        $this->client->request('GET', "/api/calendar-feeds/{$address}.ics");
        self::assertResponseIsSuccessful();
        $ics = str_replace("\r\n ", '', (string) $this->client->getResponse()->getContent());
        preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $blocks);
        $events = [];
        foreach ($blocks[1] as $block) {
            preg_match('/^UID:(.+)$/m', $block, $uid);
            preg_match('/^DTSTART;VALUE=DATE:(\d{8})/m', $block, $first);
            preg_match('/^DTEND;VALUE=DATE:(\d{8})/m', $block, $end);
            $uid = trim($uid[1]);
            self::assertArrayNotHasKey($uid, $events, 'UIDs are unique within a feed');
            $events[$uid] = ['first' => $first[1], 'end' => $end[1]];
        }

        return $events;
    }

    private function uid(string $duty): string
    {
        return $this->d[$duty].'@medvue';
    }

    private function runReminders(): string
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:duty-reminders:weekly'));
        $tester->execute(['--now' => self::SATURDAY]);
        $tester->assertCommandIsSuccessful();

        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /** @return array<string, list<string>> recipient → text bodies of the weekly reminders */
    private function reminders(): array
    {
        $byRecipient = [];
        foreach (self::getMailerMessages() as $message) {
            \assert($message instanceof Email);
            if (str_starts_with((string) $message->getSubject(), 'Vos gardes de la semaine prochaine')) {
                $byRecipient[$message->getTo()[0]->getAddress()][] = (string) $message->getTextBody();
            }
        }
        ksort($byRecipient);

        return $byRecipient;
    }

    // --- "Mes gardes" -----------------------------------------------------------------------------

    public function testMyDutiesShowEveryPublishedPeriodNeverADraftOne(): void
    {
        // Period 3 published before period 2 (allowed, D196): 1 and 3 published, 2 still a draft.
        $this->scenario([1, 3]);

        self::assertSame([$this->d['N1'] => 1, $this->d['P3'] => 3], $this->periodsOf('alice'), 'never Sunday 2 May, a draft');
        self::assertSame([$this->d['T1'] => 1], $this->periodsOf('bob'));
        self::assertSame([], $this->periodsOf('admin'), 'his only duty is in the draft period');
        $alice = $this->myDuties('alice');
        // Lot L7: with the planning's period count — the screen says "Période 1" because there are several.
        self::assertSame(['stableId' => $this->periodId(1), 'number' => 1, 'count' => 3], $alice[0]['period']);

        // Period 2 published: its duties appear, in order, each once — the boundary shows nothing twice.
        $this->publishPeriod(2);
        self::assertSame([$this->d['N1'] => 1, $this->d['S2'] => 2, $this->d['P3'] => 3], $this->periodsOf('alice'));
        self::assertSame([$this->d['C2'] => 2], $this->periodsOf('admin'));
    }

    public function testTheNightAtTheBoundaryIsADutyOfItsOwnPeriodWithItsRealHours(): void
    {
        $this->scenario([1, 2]);

        $night = $this->myDuties('alice')[0];
        self::assertSame($this->d['N1'], $night['dutyStableId']);
        self::assertSame(1, $night['period']['number'], 'the night of 30 April belongs to period 1, though it ends in period 2');
        self::assertSame(['2027-04-30'], $night['dates'], 'one calendar day — never also 1 May');
        self::assertSame('2027-04-30T18:00:00+00:00', $night['startsAt'], '20:00 in Brussels — instants are given in UTC, as before (D168)');
        self::assertSame('2027-05-01T06:00:00+00:00', $night['endsAt'], '08:00 in Brussels');

        // The feed: one all-day event on 30 April, nothing on 1 May.
        $events = $this->feedEvents($this->feedAddress('alice'));
        self::assertSame(['first' => '20270430', 'end' => '20270501'], $events[$this->uid('N1')]);
        self::assertSame(['first' => '20270502', 'end' => '20270503'], $events[$this->uid('S2')]);
        self::assertCount(2, $events);
    }

    public function testTheDutiesOfAnEndedMembershipStayVisibleInEveryPublishedPeriod(): void
    {
        $this->scenario([1, 3]);
        $address = $this->feedAddress('bob');
        $before = $this->myDuties('bob');
        self::assertSame([$this->d['T1']], array_column($before, 'dutyStableId'));

        // Bob leaves the team after period 1; his 27 April stays his, in "Mes gardes" and in his calendar.
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/teams/{$this->s['teamId']}/members/{$this->memberIdOf($this->client, $this->s, 'bob@example.com')}/end", ['membershipEnd' => '2027-05-01'], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->publishPeriod(2);

        self::assertSame($before, $this->myDuties('bob'));
        self::assertSame([$this->uid('T1')], array_keys($this->feedEvents($address)));
    }

    // --- the calendar feed ------------------------------------------------------------------------

    public function testTheFeedCarriesEveryPublishedPeriodWithStableUids(): void
    {
        $this->scenario([1, 2]);
        $alice = $this->feedAddress('alice');
        $admin = $this->feedAddress('admin');
        self::assertSame([$this->uid('N1'), $this->uid('S2')], array_keys($this->feedEvents($alice)));
        self::assertSame([$this->uid('C2')], array_keys($this->feedEvents($admin)));

        // Reassigned after publication: the event leaves alice's feed and enters admin's — the same UID.
        $this->forceHolders([$this->d['S2'] => 'admin@example.com']);
        self::assertSame([$this->uid('N1')], array_keys($this->feedEvents($alice)));
        self::assertSame([$this->uid('S2'), $this->uid('C2')], array_keys($this->feedEvents($admin)));

        // Republished: unchanged UIDs.
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/republish", ['period' => $this->periodId(2)], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame([$this->uid('S2'), $this->uid('C2')], array_keys($this->feedEvents($admin)));

        // Another period published: period 1's UIDs do not move, period 3's are added.
        $this->publishPeriod(3);
        self::assertSame([$this->uid('N1'), $this->uid('P3')], array_keys($this->feedEvents($alice)));
    }

    // --- Saturday's reminder ----------------------------------------------------------------------

    public function testTheReminderOfAWeekAcrossTwoPeriodsListsBothOnceAndIsSentOnce(): void
    {
        $this->scenario([1, 2]);

        $output = $this->runReminders();
        self::assertStringContainsString('1 planning(s) examined', $output);
        $reminders = $this->reminders();
        self::assertSame(['alice@example.com', 'bob@example.com'], array_keys($reminders), 'admin\'s 4 May is the week after');
        self::assertCount(1, $reminders['alice@example.com'], 'one email for the whole week, whatever its periods');
        $alice = $reminders['alice@example.com'][0];
        self::assertStringContainsString('Vendredi 30 avril — Seniors', $alice, 'period 1');
        self::assertStringContainsString('Dimanche 2 mai — Seniors', $alice, 'period 2');
        self::assertSame(1, substr_count($alice, '30 avril'), 'the night is listed once');
        self::assertStringContainsString('Mardi 27 avril — Seniors', $reminders['bob@example.com'][0]);

        // Idempotent: the same Saturday sends nothing twice.
        $output = $this->runReminders();
        self::assertStringContainsString('0 reminder(s) sent, 2 already sent', $output);
        self::assertCount(2, static::getContainer()->get(WeeklyDutyReminderRepository::class)->findAll());
    }

    public function testTheReminderLeavesOutADraftPeriodOfTheWeek(): void
    {
        $this->scenario([1, 3]);

        $this->runReminders();
        $reminders = $this->reminders();
        self::assertSame(['alice@example.com', 'bob@example.com'], array_keys($reminders));
        self::assertStringContainsString('Vendredi 30 avril', $reminders['alice@example.com'][0]);
        self::assertStringNotContainsString('2 mai', $reminders['alice@example.com'][0], 'period 2 is a draft');
    }
}
