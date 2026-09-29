<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\CalendarWorkflowTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * GET /api/me/duties — "Mes gardes" (docs/decisions.md D168): the caller's
 * own duties, read from the current calendar of PUBLISHED lines only, one
 * entry per unit (a block once).
 *
 * Fixture: planning 2027-01-01 → 2027-05-01 (Europe/Brussels), standalone
 * duties on Tuesday 5 and Tuesday 12 January, one block Saturday 9 +
 * Sunday 10 January, shared between admin, alice and bob.
 */
final class MyDutiesControllerTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;

    private const MEMBERS = ['admin', 'alice', 'bob'];

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    public function testEachMemberSeesExactlyTheirOwnDutiesWithABlockOnce(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $calendar = $this->currentCalendar($s['planningId']);

        $seen = [];
        foreach (self::MEMBERS as $who) {
            foreach ($this->myDuties($client, $s[$who]) as $unit) {
                self::assertSame($s['planningId'], $unit['planningStableId']);
                self::assertNotEmpty($unit['planningName']);
                self::assertSame('Seniors', $unit['lineName']);
                foreach ($unit['dates'] as $date) {
                    self::assertSame($who.'@example.com', $this->holderOn($calendar, $date), "{$who} only sees duties they hold ({$date}).");
                }
                $seen[] = $unit['dates'];
            }
        }

        usort($seen, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        self::assertSame([['2027-01-05'], ['2027-01-09', '2027-01-10'], ['2027-01-12']], $seen, 'Every unit exactly once, the block as one entry with both days.');
    }

    public function testABlockCarriesItsNameAndItsWholeSpan(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $holder = $this->holderOn($this->currentCalendar($s['planningId']), '2027-01-09');

        $block = $this->unitOn($this->myDuties($client, $s[strstr($holder, '@', true)]), '2027-01-09');
        self::assertSame('Test group', $block['blockName']);
        self::assertSame(['2027-01-09', '2027-01-10'], $block['dates']);
        $tz = new \DateTimeZone('Europe/Brussels');
        self::assertSame('2027-01-09 00:00', (new \DateTimeImmutable($block['startsAt']))->setTimezone($tz)->format('Y-m-d H:i'));
        self::assertSame('2027-01-11 00:00', (new \DateTimeImmutable($block['endsAt']))->setTimezone($tz)->format('Y-m-d H:i'), 'Ends when its last day does.');
        self::assertFalse($block['conditional']);
        self::assertNull($block['coverageState']);
    }

    public function testDutiesAreChronological(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);

        foreach (self::MEMBERS as $who) {
            $starts = array_column($this->myDuties($client, $s[$who]), 'startsAt');
            $sorted = $starts;
            sort($sorted);
            self::assertSame($sorted, $starts);
        }
    }

    public function testADraftCalendarIsNeverShown(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], ['2027-01-09', '2027-01-10', '2027-01-11']);
        $this->generate($client, $s);

        foreach (self::MEMBERS as $who) {
            self::assertSame([], $this->myDuties($client, $s[$who]), 'Generated but not published: nothing yet.');
        }
    }

    public function testALateChangeOnAPublishedLineIsReflected(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $calendar = $this->currentCalendar($s['planningId']);
        $former = $this->holderOn($calendar, '2027-01-05');
        $newcomer = array_values(array_diff(['admin@example.com', 'alice@example.com', 'bob@example.com'], [$former]))[0];

        // Reassigned after publication, never republished: the page follows the real calendar, like the weekly reminder.
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-05'), $newcomer);
        self::assertResponseIsSuccessful();

        self::assertNotNull($this->unitOn($this->myDuties($client, $s[strstr($newcomer, '@', true)]), '2027-01-05'));
        self::assertNull($this->unitOn($this->myDuties($client, $s[strstr($former, '@', true)]), '2027-01-05'));
    }

    public function testDutiesStayVisibleAfterLeavingTheTeam(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $holder = $this->holderOn($this->currentCalendar($s['planningId']), '2027-01-05');
        $token = $s[strstr($holder, '@', true)];
        $before = $this->myDuties($client, $token);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/teams/{$s['teamId']}/members/{$this->memberIdOf($client, $s, $holder)}/end", ['membershipEnd' => '2027-02-01'], $s['creator']);
        self::assertResponseIsSuccessful();

        self::assertSame($before, $this->myDuties($client, $token), 'An ended membership never erases the duties done under it.');
    }

    public function testSomebodyOutsideEveryPlanningSeesNothing(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);

        self::assertSame([], $this->myDuties($client, $s['outsider']));
    }

    public function testRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/me/duties');

        self::assertResponseStatusCodeSame(401);
    }

    // --- helpers -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function publishedScenario(KernelBrowser $client): array
    {
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']], ['2027-01-09', '2027-01-10', '2027-01-11']);
        $this->generate($client, $s);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();

        return $s;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function myDuties(KernelBrowser $client, string $token): array
    {
        $body = $this->api($client, 'GET', '/api/me/duties', token: $token);
        self::assertResponseIsSuccessful();

        return $body['duties'];
    }

    /**
     * @param list<array<string, mixed>> $duties
     *
     * @return array<string, mixed>|null
     */
    private function unitOn(array $duties, string $date): ?array
    {
        foreach ($duties as $unit) {
            if (\in_array($date, $unit['dates'], true)) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * @param array<string, string|null> $calendar
     */
    private function holderOn(array $calendar, string $date): string
    {
        foreach ($calendar as $key => $who) {
            if (str_starts_with($key, '0|'.$date.'|')) {
                return (string) $who;
            }
        }

        self::fail("No duty on {$date}.");
    }
}
