<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\ConditionalLineTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * "Mes gardes" (docs/decisions.md D168) with a conditional line: a
 * reinforcement is flagged as such and carries the live state computed by
 * CurrentCalendarReader (D166) — a superfluous one is still a real duty of
 * its holder, never hidden.
 *
 * Same scenario as ConditionalPublicationTest: Dr B (alice) on Tuesday
 * triggers the Tuesday reinforcement, then is replaced by Dr A (admin, no
 * trigger).
 */
final class MyDutiesConditionalTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;
    use ConditionalLineTestHelpers;

    private const TUE = '2027-01-05';

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    public function testAReinforcementIsFlaggedWithItsLiveState(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $declared = $this->forceHolders($client, $s, [self::TUE => 'alice@example.com', '2027-01-08' => 'admin@example.com', '2027-01-09' => 'admin@example.com', '2027-01-10' => 'admin@example.com']);
        self::assertSame('SUCCEEDED', $this->launch($client, $s)['status']);
        $this->liftUnavailabilities($client, $declared);
        $holder = $this->renfortCalendar($s)[self::TUE];
        self::assertNotNull($holder);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();

        $renfort = $this->renfortDutiesOf($client, $s[strstr($holder, '@', true)]);
        self::assertCount(1, $renfort, 'Only Tuesday: the weekend block is not required and nobody holds it.');
        self::assertSame([self::TUE], $renfort[0]['dates']);
        self::assertTrue($renfort[0]['conditional']);
        self::assertSame('REQUIRED_ASSIGNED', $renfort[0]['coverageState']);

        // Dr B replaced by Dr A: the reinforcement is no longer required, but it is still held — still listed.
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], self::TUE), 'admin@example.com');
        self::assertResponseIsSuccessful();
        $renfort = $this->renfortDutiesOf($client, $s[strstr($holder, '@', true)]);
        self::assertCount(1, $renfort);
        self::assertSame('NOT_REQUIRED_ASSIGNED', $renfort[0]['coverageState']);

        // The main line's duties are never flagged.
        $main = array_values(array_filter($this->api($client, 'GET', '/api/me/duties', token: $s['admin'])['duties'], static fn (array $u): bool => 'Renfort' !== $u['lineName']));
        self::assertNotEmpty($main);
        foreach ($main as $unit) {
            self::assertFalse($unit['conditional']);
            self::assertNull($unit['coverageState']);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function renfortDutiesOf(KernelBrowser $client, string $token): array
    {
        $body = $this->api($client, 'GET', '/api/me/duties', token: $token);
        self::assertResponseIsSuccessful();

        return array_values(array_filter($body['duties'], static fn (array $u): bool => 'Renfort' === $u['lineName']));
    }
}
