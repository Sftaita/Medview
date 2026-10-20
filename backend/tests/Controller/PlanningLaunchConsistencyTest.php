<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\UserAvailabilityPeriod;
use App\Entity\UserAvailabilityType;
use App\Fairness\OptimizationProblem;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\UserRepository;
use App\Service\UserAvailabilityService;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\FaultInjectingPlanningSolver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A launch whose later line fails never leaves calendars incompatible with
 * each other (docs/decisions.md D191, the risk D161 left open — D190): every
 * line is solved first, nothing is written before the end, and the final
 * calendar — the results that would be written, the calendars that stay —
 * is checked under the calendar lock. Real OR-Tools solves;
 * FaultInjectingPlanningSolver only makes the Juniors solve end in error.
 *
 * Fixture: planning 2027-01-01 → 2027-05-01, Seniors (admin, alice, bob)
 * and Juniors (junior, alice — D160). Seniors' day on 5 January
 * 08:00 → 20:00, Juniors' night 5 January 21:00 → 6 January 07:00: with an
 * 11-hour legal rest, nobody can hold both.
 */
final class PlanningLaunchConsistencyTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private const REST = ['legalMinRestEnabled' => true, 'legalMinRestHours' => 11];

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
        $teamId = $this->addSecondaryLine($this->client, $this->s, ['junior@example.com']);
        $this->addMemberAs($this->client, $this->s['creator'], $this->s['planningId'], $teamId, 'alice@example.com', 'MEMBER');
        $this->prepareLine($this->s['planningId'], [['2027-01-05 08:00', '2027-01-05 20:00']]);
        $this->prepareLine($this->s['planningId'], [['2027-01-05 21:00', '2027-01-06 07:00']], lineIndex: 1);
    }

    protected function tearDown(): void
    {
        FaultInjectingPlanningSolver::reset();
        parent::tearDown();
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function unavailable(string $email, string $fromUtc, string $toUtc): UserAvailabilityPeriod
    {
        return static::getContainer()->get(UserAvailabilityService::class)->create(
            static::getContainer()->get(UserRepository::class)->findOneByEmail($email),
            UserAvailabilityType::UNAVAILABLE,
            new \DateTimeImmutable($fromUtc.' UTC'),
            new \DateTimeImmutable($toUtc.' UTC'),
        );
    }

    private function juniorsFail(): void
    {
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $juniors = (int) static::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[1]->requireSingleCyclePeriod()->getId();
        FaultInjectingPlanningSolver::$errorWhen = static fn (OptimizationProblem $p): bool => [] !== $p->getRequiredDutyUnits()
            && $p->getRequiredDutyUnits()[0]->getDuties()[0]->getPlanningPeriod()->getId() === $juniors;
    }

    /**
     * @return array<string, mixed> the job
     */
    private function launch(): array
    {
        return $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], self::REST);
    }

    /**
     * @return array<string, array<string, mixed>> the job's lines by line name
     */
    private function lines(array $job): array
    {
        return array_column($job['outcome']['lines'], null, 'lineName');
    }

    /** Every current assignment of the planning, hashed (which generation, which duty, whom). */
    private function calendarFingerprint(): string
    {
        return (string) $this->db()->fetchOne(<<<'SQL'
            SELECT md5(string_agg(x, '|' ORDER BY x)) FROM (
              SELECT g.stable_id::text || ':' || a.duty_id || ':' || a.team_member_id AS x
              FROM duty_assignments a JOIN planning_generations g ON g.id = a.generation_id WHERE a.current
            ) s
        SQL);
    }

    /** Line 0 = Seniors, line 1 = Juniors: the current holder of its only duty. */
    private function holder(int $lineIndex): ?string
    {
        return $this->currentCalendar($this->s['planningId'])[$lineIndex.'|2027-01-05|ONCALL'] ?? null;
    }

    /**
     * Launch 1: Alice cannot do the Seniors day, Junior cannot do the Juniors
     * night — Alice holds the night, Adam or Bob the day.
     */
    private function firstLaunchGivesAliceTheNight(): UserAvailabilityPeriod
    {
        $aliceAway = $this->unavailable('alice@example.com', '2027-01-05 06:00', '2027-01-05 19:30');
        $this->unavailable('junior@example.com', '2027-01-04 00:00', '2027-01-07 00:00');
        self::assertSame('SUCCEEDED', $this->launch()['status']);
        self::assertSame('alice@example.com', $this->holder(1));
        self::assertNotSame('alice@example.com', $this->holder(0));

        return $aliceAway;
    }

    /**
     * The scenario that reproduced D161 (D190): Seniors regenerated gives
     * Alice the day, Juniors fails and keeps Alice on the night. Written,
     * it would break the 11-hour rest: nothing is written.
     */
    public function testALaterLineThatFailsNeverLeavesAnIncompatibleCalendar(): void
    {
        $aliceAway = $this->firstLaunchGivesAliceTheNight();
        $seniorsBefore = $this->holder(0);
        $calendar = $this->calendarFingerprint();
        $this->db()->executeStatement('DELETE FROM user_availability_periods WHERE id = ?', [$aliceAway->getId()]);
        $this->unavailable('admin@example.com', '2027-01-05 06:00', '2027-01-05 19:30');
        $this->unavailable('bob@example.com', '2027-01-05 06:00', '2027-01-05 19:30');
        $this->juniorsFail();

        $job = $this->launch();

        $lines = $this->lines($job);
        self::assertSame('FAILED', $job['status']);
        self::assertSame('FAILED', $lines['Seniors']['status']);
        self::assertSame('launch_inconsistent', $lines['Seniors']['error'], 'solved (Alice on the day), never written');
        self::assertSame('CROSS_LINE_LEGAL_MIN_REST', $lines['Seniors']['conflicts'][0]['reason']);
        self::assertSame(['Seniors', '2027-01-05', 'Juniors', '2027-01-05', true], [
            $lines['Seniors']['conflicts'][0]['lineName'], $lines['Seniors']['conflicts'][0]['day'],
            $lines['Seniors']['conflicts'][0]['otherLineName'], $lines['Seniors']['conflicts'][0]['otherDay'],
            $lines['Seniors']['conflicts'][0]['otherWritten'],
        ]);
        self::assertSame('FAILED', $lines['Juniors']['status']);
        self::assertSame($calendar, $this->calendarFingerprint(), 'no current assignment changed, none removed');
        self::assertSame($seniorsBefore, $this->holder(0));
        self::assertSame('alice@example.com', $this->holder(1));
        self::assertStringContainsString('Not written', (string) $this->db()->fetchOne("SELECT failure_reason FROM planning_generations WHERE status = 'FAILED' AND failure_reason LIKE 'Not written%'"));
        $preflight = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-preflight", token: $this->s['creator']);
        self::assertSame([], $preflight['conflicts'], 'the calendars stay compatible');
    }

    /**
     * Three lines: the first is not written (incompatible with the night the
     * failed third line keeps); the second was solved against what the first
     * was about to hold — its snapshot no longer describes the calendar it
     * would be written into, so it is not written either (never valid merely
     * because the final calendar would look coherent).
     */
    public function testALineSolvedAgainstAResultThatIsNotWrittenIsNotWrittenEither(): void
    {
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/lines", ['name' => 'Nuits'], $this->s['creator']);
        self::assertResponseStatusCodeSame(201);
        $nights = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][2]['team']['stableId'];
        $this->userToken($this->client, 'carol@example.com');
        foreach (['alice@example.com', 'carol@example.com'] as $email) {
            $this->addMemberAs($this->client, $this->s['creator'], $this->s['planningId'], $nights, $email, 'MEMBER');
        }
        $this->prepareLine($this->s['planningId'], [['2027-01-04 21:00', '2027-01-05 06:00']], lineIndex: 2);
        // The Nuits night before the Seniors day ends at 06:00: two hours before it — never both with an 11-hour rest.
        // Launch 1: Alice holds the Nuits night (Carol away), the Seniors day goes to Adam or Bob, Juniors to Junior.
        $aliceAway = $this->unavailable('alice@example.com', '2027-01-05 06:00', '2027-01-05 19:30');
        $this->unavailable('carol@example.com', '2027-01-04 00:00', '2027-01-07 00:00');
        $this->unavailable('junior@example.com', '2027-01-05 06:00', '2027-01-05 19:30');
        self::assertSame('SUCCEEDED', $this->launch()['status']);
        self::assertSame('alice@example.com', $this->currentCalendar($this->s['planningId'])['2|2027-01-04|ONCALL']);
        $calendar = $this->calendarFingerprint();
        // Launch 2: only Alice for the Seniors day; the Nuits solve fails (its night stays Alice's).
        $this->db()->executeStatement('DELETE FROM user_availability_periods WHERE id = ?', [$aliceAway->getId()]);
        $this->unavailable('admin@example.com', '2027-01-05 06:00', '2027-01-05 19:30');
        $this->unavailable('bob@example.com', '2027-01-05 06:00', '2027-01-05 19:30');
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $nightsPeriod = (int) static::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[2]->requireSingleCyclePeriod()->getId();
        FaultInjectingPlanningSolver::$errorWhen = static fn (OptimizationProblem $p): bool => [] !== $p->getRequiredDutyUnits()
            && $p->getRequiredDutyUnits()[0]->getDuties()[0]->getPlanningPeriod()->getId() === $nightsPeriod;

        $job = $this->launch();

        $lines = $this->lines($job);
        self::assertSame('launch_inconsistent', $lines['Seniors']['error']);
        self::assertSame('neighbour_calendar_changed', $lines['Juniors']['error'], 'the planned Seniors day of Alice was frozen in its snapshot');
        self::assertSame('FAILED', $lines['Nuits']['status']);
        self::assertSame($calendar, $this->calendarFingerprint(), 'nothing written, nothing removed');
    }

    /** A first generation: the failed line has no calendar to contradict — the other one is written (partial, as before). */
    public function testAFirstGenerationKeepsTheLinesThatSucceeded(): void
    {
        $this->juniorsFail();

        $job = $this->launch();

        $lines = $this->lines($job);
        self::assertSame('FAILED', $job['status'], 'a line failed: the job says so');
        self::assertSame('COMPLETED', $lines['Seniors']['status']);
        self::assertNull($lines['Seniors']['error']);
        self::assertSame([], $lines['Seniors']['conflicts']);
        self::assertNotNull($this->holder(0), 'written');
        self::assertNull($this->holder(1));
    }

    /** A regeneration whose result is compatible with the calendar the failed line keeps: written. */
    public function testARegenerationCompatibleWithTheFailedLinesCalendarIsWritten(): void
    {
        $this->firstLaunchGivesAliceTheNight();
        $seniorsGeneration = $this->db()->fetchOne("SELECT g.stable_id FROM planning_generations g JOIN planning_periods pp ON pp.id = g.planning_period_id WHERE pp.name = 'Seniors' AND g.status = 'COMPLETED'");
        $this->juniorsFail();

        $job = $this->launch();

        $lines = $this->lines($job);
        self::assertSame('COMPLETED', $lines['Seniors']['status'], 'Alice is still away that day: the new day is compatible with her night');
        self::assertNotSame($seniorsGeneration, $lines['Seniors']['generationStableId']);
        self::assertSame('FAILED', $lines['Juniors']['status']);
        self::assertSame('alice@example.com', $this->holder(1), 'the failed line keeps its calendar');
        self::assertNotSame('alice@example.com', $this->holder(0));
    }
}
