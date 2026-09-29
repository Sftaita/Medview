<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CalendarFeed;
use App\Entity\User;
use App\Repository\CalendarFeedRepository;
use App\Repository\UserRepository;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Calendar subscription of "Mes gardes" (docs/decisions.md D170):
 * /api/me/calendar-feed (JWT, the caller's own address) and the public
 * /api/calendar-feeds/{token}.ics polled by Google, Apple and Outlook.
 *
 * Fixture (same as MyDutiesControllerTest): planning 2027-01-01 → 2027-05-01
 * (Europe/Brussels), standalone duties on Tuesday 5 and Tuesday 12 January,
 * one block Saturday 9 + Sunday 10 January, shared between admin, alice
 * and bob.
 */
final class CalendarFeedControllerTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;

    private const MEMBERS = ['admin', 'alice', 'bob'];

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    // --- managing the address ----------------------------------------------------------

    public function testNoAddressUntilAskedFor(): void
    {
        $client = static::createClient();
        $token = $this->userToken($client, 'alice@example.com');

        self::assertSame(['feed' => null], $this->api($client, 'GET', '/api/me/calendar-feed', token: $token));
        self::assertResponseIsSuccessful();
    }

    public function testEnablingIsIdempotent(): void
    {
        $client = static::createClient();
        $token = $this->userToken($client, 'alice@example.com');

        $first = $this->api($client, 'POST', '/api/me/calendar-feed', token: $token)['feed'];
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first['token']);
        self::assertNull($first['lastFetchedAt']);

        $second = $this->api($client, 'POST', '/api/me/calendar-feed', token: $token)['feed'];
        self::assertSame($first['token'], $second['token'], 'A second click never creates a second address.');
        self::assertSame($first, $this->api($client, 'GET', '/api/me/calendar-feed', token: $token)['feed']);
    }

    public function testEachPersonHasTheirOwnAddress(): void
    {
        $client = static::createClient();
        $alice = $this->api($client, 'POST', '/api/me/calendar-feed', token: $this->userToken($client, 'alice@example.com'))['feed']['token'];
        $bob = $this->api($client, 'POST', '/api/me/calendar-feed', token: $this->userToken($client, 'bob@example.com'))['feed']['token'];

        self::assertNotSame($alice, $bob);
    }

    public function testRegeneratingRevokesThePreviousAddressAtOnce(): void
    {
        $client = static::createClient();
        $token = $this->userToken($client, 'alice@example.com');
        $old = $this->api($client, 'POST', '/api/me/calendar-feed', token: $token)['feed']['token'];

        $new = $this->api($client, 'POST', '/api/me/calendar-feed/regenerate', token: $token)['feed']['token'];
        self::assertResponseIsSuccessful();
        self::assertNotSame($old, $new);

        $this->fetchIcs($client, $old);
        self::assertResponseStatusCodeSame(404);
        $this->fetchIcs($client, $new);
        self::assertResponseIsSuccessful();
        self::assertSame($new, $this->api($client, 'GET', '/api/me/calendar-feed', token: $token)['feed']['token']);
        self::assertCount(2, static::getContainer()->get(CalendarFeedRepository::class)->findAll(), 'The revoked address is kept, never deleted.');
    }

    public function testRegeneratingWithoutAnAddressCreatesOne(): void
    {
        $client = static::createClient();
        $token = $this->userToken($client, 'alice@example.com');

        $feed = $this->api($client, 'POST', '/api/me/calendar-feed/regenerate', token: $token)['feed'];
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $feed['token']);
    }

    public function testDisablingStopsTheAddress(): void
    {
        $client = static::createClient();
        $token = $this->userToken($client, 'alice@example.com');
        $address = $this->api($client, 'POST', '/api/me/calendar-feed', token: $token)['feed']['token'];

        $client->request('DELETE', '/api/me/calendar-feed', server: $this->bearer($token));
        self::assertResponseStatusCodeSame(204);

        $this->fetchIcs($client, $address);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['feed' => null], $this->api($client, 'GET', '/api/me/calendar-feed', token: $token));

        $client->request('DELETE', '/api/me/calendar-feed', server: $this->bearer($token));
        self::assertResponseStatusCodeSame(204, 'Disabling twice is harmless.');

        $again = $this->api($client, 'POST', '/api/me/calendar-feed', token: $token)['feed']['token'];
        self::assertNotSame($address, $again, 'Re-enabling never brings a revoked address back.');
    }

    public function testManagingTheAddressRequiresAuthentication(): void
    {
        $client = static::createClient();

        foreach ([['GET', '/api/me/calendar-feed'], ['POST', '/api/me/calendar-feed'], ['POST', '/api/me/calendar-feed/regenerate'], ['DELETE', '/api/me/calendar-feed']] as [$method, $url]) {
            $client->request($method, $url);
            self::assertResponseStatusCodeSame(401, "{$method} {$url}");
        }
    }

    // --- the feed itself ----------------------------------------------------------------

    public function testTheFeedIsPublicAndCarriesExactlyTheOwnersDuties(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $calendar = $this->currentCalendar($s['planningId']);

        $seen = [];
        foreach (self::MEMBERS as $who) {
            $ics = $this->icsOf($client, $s[$who]);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'text/calendar; charset=utf-8');
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));

            foreach ($this->eventsOf($ics) as $event) {
                self::assertSame($who.'@example.com', $this->holderOn($calendar, $event['first']), "{$who}'s feed only has their own duties.");
                $seen[] = [$event['first'], $event['end']];
            }
        }

        sort($seen);
        self::assertSame([['2027-01-05', '2027-01-06'], ['2027-01-09', '2027-01-11'], ['2027-01-12', '2027-01-13']], $seen, 'Every unit once across the three feeds, the block as one all-day event (DTEND exclusive).');
    }

    public function testAnEventNamesItsLineAndLinksToItsPlanning(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $holder = $this->holderOn($this->currentCalendar($s['planningId']), '2027-01-09');

        $ics = $this->unfold($this->icsOf($client, $s[strstr($holder, '@', true)]));
        self::assertStringContainsString("SUMMARY:Garde Seniors · Test group\r\n", $ics);
        self::assertStringContainsString("URL:http://localhost:5183/plannings/{$s['planningId']}\r\n", $ics);
        self::assertStringContainsString('X-WR-CALNAME:MedVue — Mes gardes', $ics);
    }

    public function testADraftCalendarNeverReachesTheFeed(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], ['2027-01-09', '2027-01-10', '2027-01-11']);
        $this->generate($client, $s);

        foreach (self::MEMBERS as $who) {
            self::assertSame([], $this->eventsOf($this->icsOf($client, $s[$who])), 'Generated but not published: nothing yet.');
        }
    }

    public function testALateChangeOnAPublishedLineIsInTheNextPoll(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $former = $this->holderOn($this->currentCalendar($s['planningId']), '2027-01-05');
        $newcomer = array_values(array_diff(['admin@example.com', 'alice@example.com', 'bob@example.com'], [$former]))[0];
        $formerAddress = $this->addressOf($client, $s[strstr($former, '@', true)]);
        $newcomerAddress = $this->addressOf($client, $s[strstr($newcomer, '@', true)]);
        $uid = $this->uidOn($this->fetchIcs($client, $formerAddress), '2027-01-05');
        self::assertNotNull($uid, 'In the former holder\'s calendar before the change.');
        self::assertNull($this->uidOn($this->fetchIcs($client, $newcomerAddress), '2027-01-05'));

        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-05'), $newcomer);
        self::assertResponseIsSuccessful();

        self::assertNull($this->uidOn($this->fetchIcs($client, $formerAddress), '2027-01-05'), 'Gone from the former holder\'s calendar.');
        self::assertSame($uid, $this->uidOn($this->fetchIcs($client, $newcomerAddress), '2027-01-05'), 'Same UID in the newcomer\'s: the same duty, moved.');
    }

    public function testDutiesStayAfterLeavingTheTeam(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $holder = $this->holderOn($this->currentCalendar($s['planningId']), '2027-01-05');
        $address = $this->addressOf($client, $s[strstr($holder, '@', true)]);
        $before = $this->eventsOf($this->fetchIcs($client, $address));

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/teams/{$s['teamId']}/members/{$this->memberIdOf($client, $s, $holder)}/end", ['membershipEnd' => '2027-02-01'], $s['creator']);
        self::assertResponseIsSuccessful();

        self::assertSame($before, $this->eventsOf($this->fetchIcs($client, $address)));
    }

    public function testAnOutsiderGetsAnEmptyButValidCalendar(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);

        $ics = $this->icsOf($client, $s['outsider']);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertSame([], $this->eventsOf($ics));
    }

    public function testUnknownTokensAreNotFound(): void
    {
        $client = static::createClient();

        $this->fetchIcs($client, str_repeat('a', 64));
        self::assertResponseStatusCodeSame(404);
    }

    public function testAMalformedTokenIsNeverPublic(): void
    {
        $client = static::createClient();

        foreach (['abc', str_repeat('A', 64), str_repeat('a', 65)] as $token) {
            $client->request('GET', "/api/calendar-feeds/{$token}.ics");
            self::assertContains($client->getResponse()->getStatusCode(), [401, 404], $token);
        }
    }

    public function testADeactivatedAccountsFeedIsNotFound(): void
    {
        $client = static::createClient();
        $address = $this->addressOf($client, $this->userToken($client, 'alice@example.com'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get(UserRepository::class)->findOneByEmail('alice@example.com')?->setActive(false);
        $em->flush();

        $this->fetchIcs($client, $address);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheLastFetchIsRecordedAtMostHourly(): void
    {
        $client = static::createClient();
        $token = $this->userToken($client, 'alice@example.com');
        $address = $this->addressOf($client, $token);

        $this->fetchIcs($client, $address);
        $first = $this->api($client, 'GET', '/api/me/calendar-feed', token: $token)['feed']['lastFetchedAt'];
        self::assertSame('2026-12-10 09:00', (new \DateTimeImmutable($first))->setTimezone(new \DateTimeZone('Europe/Brussels'))->format('Y-m-d H:i'));

        self::mockTime('2026-12-10 09:30:00 Europe/Brussels');
        $this->fetchIcs($client, $address);
        self::assertSame($first, $this->api($client, 'GET', '/api/me/calendar-feed', token: $token)['feed']['lastFetchedAt'], 'Not rewritten within the hour.');

        self::mockTime('2026-12-10 10:05:00 Europe/Brussels');
        $this->fetchIcs($client, $address);
        self::assertNotSame($first, $this->api($client, 'GET', '/api/me/calendar-feed', token: $token)['feed']['lastFetchedAt']);
    }

    public function testATokenIsAlways64LowercaseHex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CalendarFeed(new User('x@example.com', 'X', 'Y', 'hash'), 'not-a-token', new \DateTimeImmutable());
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

    private function addressOf(KernelBrowser $client, string $jwt): string
    {
        $feed = $this->api($client, 'POST', '/api/me/calendar-feed', token: $jwt)['feed'];
        self::assertResponseIsSuccessful();

        return $feed['token'];
    }

    private function icsOf(KernelBrowser $client, string $jwt): string
    {
        return $this->fetchIcs($client, $this->addressOf($client, $jwt));
    }

    /** No Authorization header: exactly what a calendar app sends. */
    private function fetchIcs(KernelBrowser $client, string $address): string
    {
        $client->request('GET', "/api/calendar-feeds/{$address}.ics");

        return (string) $client->getResponse()->getContent();
    }

    private function unfold(string $ics): string
    {
        return str_replace("\r\n ", '', $ics);
    }

    /**
     * @return list<array{uid: string, first: string, end: string}> end exclusive
     */
    private function eventsOf(string $ics): array
    {
        preg_match_all('/BEGIN:VEVENT\r\n(.*?)END:VEVENT\r\n/s', $this->unfold($ics), $blocks);
        $events = [];
        foreach ($blocks[1] as $block) {
            preg_match('/^UID:(.+)\r$/m', $block, $uid);
            preg_match('/^DTSTART;VALUE=DATE:(\d{8})\r$/m', $block, $start);
            preg_match('/^DTEND;VALUE=DATE:(\d{8})\r$/m', $block, $end);
            $events[] = [
                'uid' => $uid[1],
                'first' => \DateTimeImmutable::createFromFormat('!Ymd', $start[1])->format('Y-m-d'),
                'end' => \DateTimeImmutable::createFromFormat('!Ymd', $end[1])->format('Y-m-d'),
            ];
        }

        return $events;
    }

    private function uidOn(string $ics, string $date): ?string
    {
        foreach ($this->eventsOf($ics) as $event) {
            if ($event['first'] <= $date && $date < $event['end']) {
                return $event['uid'];
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
