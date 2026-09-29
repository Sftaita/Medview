<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use App\Tests\CalendarWorkflowTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The candidates of a reassignment follow the membership stints' own
 * calendar convention — dates, half-open [membershipStart, membershipEnd) —
 * exactly like the write path (PlanningTeamMember::isActiveAt on the duty's
 * local date). Regression: the list used to be looked up by the duty's UTC
 * instants, so on the first day of a stint (a duty starting the evening
 * before in UTC, Europe/Brussels) the person was missing from the list
 * although the save accepted them.
 *
 * Planning 2027-01-01 → 2027-05-01; the pilot team (admin, alice, bob)
 * joined on 2027-01-01, the first day of the period.
 */
final class ReassignmentCandidateBoundariesTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    private function userId(string $email): string
    {
        return (string) static::getContainer()->get(UserRepository::class)->findOneByEmail($email)->getStableId();
    }

    /**
     * Who is offered for the main line's duty of $date, plus its holder: stint ids by user.
     *
     * @return array{candidates: array<string, list<string>>, holder: ?string}
     */
    private function offered(KernelBrowser $client, array $s, string $date): array
    {
        $view = $this->candidatesFor($client, $s, $this->dutyOn($s['planningId'], $date));
        self::assertResponseIsSuccessful();
        $members = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/teams/{$s['teamId']}/members", token: $s['creator']);
        $userOfStint = array_column($members, 'userStableId', 'stableId');
        $candidates = [];
        foreach ($view['candidates'] as $candidate) {
            $candidates[$userOfStint[$candidate['teamMemberStableId']]][] = $candidate['teamMemberStableId'];
        }

        return [
            'candidates' => $candidates,
            'holder' => null !== $view['currentTeamMemberStableId'] ? $userOfStint[$view['currentTeamMemberStableId']] : null,
        ];
    }

    /** Offered, or already holding it (the holder is never among the replacements). */
    private function assertAvailable(array $offered, string $email, string $why): void
    {
        $user = $this->userId($email);
        self::assertTrue(isset($offered['candidates'][$user]) || $offered['holder'] === $user, $why);
    }

    private function assertNotOffered(array $offered, string $email, string $why): void
    {
        $user = $this->userId($email);
        self::assertArrayNotHasKey($user, $offered['candidates'], $why);
        self::assertNotSame($user, $offered['holder'], $why);
    }

    public function testFirstDayOfAStintAtTheStartOfThePeriod(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-01', '2027-01-02'], ['2027-01-02', '2027-01-03']]);
        $this->generate($client, $s);

        // 2027-01-01 is the first day of every stint and of the period: all three can take it.
        $first = $this->offered($client, $s, '2027-01-01');
        foreach (['admin@example.com', 'alice@example.com', 'bob@example.com'] as $email) {
            $this->assertAvailable($first, $email, "{$email} on the first day of their membership.");
        }
        self::assertCount(2, $first['candidates'], 'Everybody but the holder, each once.');
    }

    public function testAStintStartingMidPeriodCountsFromItsFirstDayOnly(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $s['carol'] = $this->userToken($client, 'carol@example.com');
        $carol = static::getContainer()->get(UserRepository::class)->findOneByEmail('carol@example.com');
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/teams/{$s['teamId']}/members", [
            'userStableId' => (string) $carol->getStableId(),
            'role' => 'MEMBER',
            'membershipStart' => '2027-01-06',
        ], $s['creator']);
        self::assertResponseStatusCodeSame(201);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07']]);
        $this->generate($client, $s);

        $this->assertNotOffered($this->offered($client, $s, '2027-01-05'), 'carol@example.com', 'The day before her stint.');
        $this->assertAvailable($this->offered($client, $s, '2027-01-06'), 'carol@example.com', 'The first day of her stint.');
    }

    public function testTheEndOfAStintIsExclusiveAndASuccessiveStintTakesOverWithoutDuplicates(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        // bob leaves on 2027-01-08 (exclusive: his last day is 2027-01-07) and rejoins on 2027-01-08 — two stints.
        $bobStint = $this->memberIdOf($client, $s, 'bob@example.com');
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/teams/{$s['teamId']}/members/{$bobStint}/end", ['membershipEnd' => '2027-01-08'], $s['creator']);
        self::assertResponseIsSuccessful();
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/teams/{$s['teamId']}/members", [
            'userStableId' => $this->userId('bob@example.com'),
            'role' => 'MEMBER',
            'membershipStart' => '2027-01-08',
        ], $s['creator']);
        self::assertResponseStatusCodeSame(201);
        $this->prepareLine($s['planningId'], [['2027-01-07', '2027-01-08'], ['2027-01-08', '2027-01-09']]);
        $this->generate($client, $s);

        $members = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/teams/{$s['teamId']}/members", token: $s['creator']);
        $bobStints = array_values(array_filter($members, fn (array $m): bool => $m['userStableId'] === $this->userId('bob@example.com')));
        self::assertCount(2, $bobStints);
        $secondStint = $bobStints[0]['stableId'] === $bobStint ? $bobStints[1]['stableId'] : $bobStints[0]['stableId'];

        // The last day of the first stint, then the first day of the second: bob each time, through ONE stint.
        foreach (['2027-01-07' => $bobStint, '2027-01-08' => $secondStint] as $date => $expectedStint) {
            $offered = $this->offered($client, $s, $date);
            $this->assertAvailable($offered, 'bob@example.com', "bob on {$date}.");
            $stints = $offered['candidates'][$this->userId('bob@example.com')] ?? [];
            self::assertLessThanOrEqual(1, \count($stints), "Never twice the same person on {$date}.");
            if ([] !== $stints) {
                self::assertSame([$expectedStint], $stints, 'The stint active that day.');
            }
        }
    }

    public function testNoCandidateOutsideTheStintOnTheLastDayOfThePeriodBoundary(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        // alice leaves on 2027-04-30: her last day is 2027-04-29; 2027-04-30 is the last day of the period.
        $aliceStint = $this->memberIdOf($client, $s, 'alice@example.com');
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/teams/{$s['teamId']}/members/{$aliceStint}/end", ['membershipEnd' => '2027-04-30'], $s['creator']);
        self::assertResponseIsSuccessful();
        $this->prepareLine($s['planningId'], [['2027-04-29', '2027-04-30'], ['2027-04-30', '2027-05-01']]);
        $this->generate($client, $s);

        $this->assertAvailable($this->offered($client, $s, '2027-04-29'), 'alice@example.com', 'Her last day.');
        $this->assertNotOffered($this->offered($client, $s, '2027-04-30'), 'alice@example.com', 'The day her stint ends (exclusive).');
        $this->assertAvailable($this->offered($client, $s, '2027-04-30'), 'admin@example.com', 'The last day of the period, open stint.');
    }
}
