<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DutyType;
use App\Entity\PlanningCycle;
use App\Entity\PlanningJob;
use App\Entity\PlanningJobKind;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use App\Entity\User;
use App\Entity\UserAvailabilityType;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\UserRepository;
use App\Service\DutyMaterializationService;
use App\Service\DutyReassignmentService;
use App\Service\PlanningGenerationService;
use App\Service\PlanningJobRecovery;
use App\Service\PlanningRuleSetService;
use App\Service\UserAvailabilityService;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\FaultInjectingPlanningSolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Generation by period, lot L3 (docs/planning-cycles.md §5.4, I8, I11,
 * docs/decisions.md D189), through the real endpoints, the real job runner
 * and the real OR-Tools solver (FaultInjectingPlanningSolver only injects a
 * callback during a solve).
 *
 * Fixture (pilotScenario): planning 2027-01-01 → 2027-05-01 (period 1, ends
 * on a Saturday), primary line "Seniors" with admin/alice/bob, Europe/Brussels.
 * The rest policy, when used, is LEGAL_MIN_REST 11 h.
 */
final class PlanningCycleGenerationTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private const REST = ['legalMinRestEnabled' => true, 'legalMinRestHours' => 11];
    private const MEMBERS = ['admin@example.com', 'alice@example.com', 'bob@example.com'];

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    /** @var array<int, true> lines whose rule set is active */
    private array $prepared = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
    }

    protected function tearDown(): void
    {
        FaultInjectingPlanningSolver::reset();
        parent::tearDown();
    }

    // --- helpers ---------------------------------------------------------------------------------

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function periods(): array
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'];
    }

    private function periodId(int $number): string
    {
        foreach ($this->periods() as $period) {
            if ($number === $period['number']) {
                return $period['stableId'];
            }
        }

        self::fail("No period {$number}.");
    }

    private function createPeriod(string $endsAt): string
    {
        $periods = $this->periods();
        $created = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", [
            'endsAt' => $endsAt,
            'previousPeriodStableId' => $periods[\count($periods) - 1]['stableId'],
        ], $this->s['creator']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $created['stableId'];
    }

    private function line(int $index): PlanningLine
    {
        $this->em()->clear();
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);

        return static::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[$index];
    }

    /** Read by id after a fresh clear: an HTTP request in between resets the EntityManager. */
    private function cycle(int $number): PlanningCycle
    {
        $id = $this->db()->fetchOne('SELECT c.id FROM planning_cycles c JOIN plannings p ON p.id = c.planning_id WHERE p.stable_id = ? AND c.number = ?', [$this->s['planningId'], $number]);
        self::assertNotFalse($id, "No period {$number}.");
        $this->em()->clear();

        return $this->em()->find(PlanningCycle::class, $id) ?? self::fail("No period {$number}.");
    }

    private function periodOf(int $lineIndex, int $number): PlanningPeriod
    {
        $id = $this->db()->fetchOne(<<<'SQL'
            SELECT pp.id FROM planning_periods pp
            JOIN planning_lines l ON l.id = pp.planning_line_id JOIN planning_cycles c ON c.id = pp.planning_cycle_id JOIN plannings p ON p.id = l.planning_id
            WHERE p.stable_id = ? AND c.number = ? AND l.id = (SELECT l2.id FROM planning_lines l2 WHERE l2.planning_id = p.id ORDER BY l2.position, l2.id OFFSET ? LIMIT 1)
        SQL, [$this->s['planningId'], $number, $lineIndex]);
        self::assertNotFalse($id, "Line {$lineIndex} has no period in period {$number}.");
        $this->em()->clear();

        return $this->em()->find(PlanningPeriod::class, $id) ?? self::fail("Line {$lineIndex} has no period in period {$number}.");
    }

    /** Alice on a second line too (D160), with a junior. */
    private function addJuniorsWithAlice(): void
    {
        $teamId = $this->addSecondaryLine($this->client, $this->s, ['junior@example.com']);
        $this->addMemberAs($this->client, $this->s['creator'], $this->s['planningId'], $teamId, 'alice@example.com', 'MEMBER');
    }

    /**
     * Standalone duties ([local start, local end], Europe/Brussels wall clock) on line $lineIndex in period $number.
     *
     * @param list<array{0: string, 1: string}> $duties
     */
    private function addDuties(int $number, array $duties, int $lineIndex = 0): void
    {
        $period = $this->periodOf($lineIndex, $number);
        $team = $period->getTeam();
        if (!isset($this->prepared[$lineIndex])) {
            $this->activateRuleSet(static::getContainer()->get(PlanningRuleSetService::class), $team);
            $this->prepared[$lineIndex] = true;
        }
        $dutyType = $this->em()->getRepository(DutyType::class)->findOneBy(['team' => $team, 'code' => 'ONCALL']) ?? $this->createDutyType($this->em(), $team);
        $period = $this->em()->find(PlanningPeriod::class, $period->getId());
        foreach ($duties as [$from, $to]) {
            static::getContainer()->get(DutyMaterializationService::class)->createStandaloneDuty($period, $dutyType, new \DateTimeImmutable($from), new \DateTimeImmutable($to));
        }
    }

    /**
     * Queues a generation of period $number and runs it (real worker handler, real OR-Tools).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> the job, as GET .../jobs/latest returns it
     */
    private function generatePeriod(int $number, array $body = []): array
    {
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", ['period' => $this->periodId($number)] + $body, $this->s['creator']);
        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->runQueuedPlanningJobs());

        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/jobs/latest", token: $this->s['creator'])['job'];
    }

    /** The current holder (email) of the duty starting on $localDate, or null when uncovered. */
    private function holderOn(int $number, string $localDate, int $lineIndex = 0): ?string
    {
        $period = $this->periodOf($lineIndex, $number);
        $generation = static::getContainer()->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($period);
        $duty = null;
        foreach (static::getContainer()->get(DutyRepository::class)->findByPlanningPeriod($period) as $candidate) {
            if ($candidate->getLocalDate()->format('Y-m-d') === $localDate) {
                $duty = $candidate;
            }
        }
        self::assertNotNull($duty, "No duty on {$localDate} in period {$number}.");
        if (null === $generation) {
            return null;
        }
        foreach (static::getContainer()->get(DutyAssignmentRepository::class)->findForGenerations([$generation]) as $assignment) {
            if ($assignment->getDuty()->getId() === $duty->getId()) {
                return $assignment->getTeamMember()->getUser()->getEmail();
            }
        }

        return null;
    }

    /** Unavailable for whole UTC instants (the availability calendar stores instants). */
    private function unavailable(string $email, string $fromUtc, string $toUtc): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
        $this->addAvailability(static::getContainer()->get(UserAvailabilityService::class), $user, UserAvailabilityType::UNAVAILABLE, $fromUtc.' UTC', $toUtc.' UTC');
    }

    /**
     * Everything a period holds, hashed (same as PlanningCycleControllerTest::fingerprint()).
     */
    private function fingerprint(int $number): string
    {
        return (string) $this->db()->fetchOne(<<<'SQL'
            SELECT md5(string_agg(x, '|' ORDER BY x)) FROM (
              SELECT 'y' || ROW(cy.*)::text AS x FROM planning_cycles cy WHERE cy.id = :c
              UNION ALL SELECT 'p' || ROW(pp.*)::text FROM planning_periods pp WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'd' || ROW(d.*)::text FROM duties d JOIN planning_periods pp ON pp.id = d.planning_period_id WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'g' || ROW(g.*)::text FROM planning_generations g JOIN planning_periods pp ON pp.id = g.planning_period_id WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'a' || ROW(a.*)::text FROM duty_assignments a JOIN duties d ON d.id = a.duty_id JOIN planning_periods pp ON pp.id = d.planning_period_id WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'u' || ROW(u.*)::text FROM planning_publications u WHERE u.planning_cycle_id = :c
              UNION ALL SELECT 'c' || ROW(ac.*)::text FROM availability_collections ac WHERE ac.planning_cycle_id = :c
            ) s
        SQL, ['c' => $this->cycle($number)->getId()]);
    }

    private function assertError(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());
        self::assertSame($code, json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function preflight(int $number): array
    {
        $preflight = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/generation-preflight?period={$this->periodId($number)}", token: $this->s['creator']);
        self::assertResponseIsSuccessful();

        return $preflight;
    }

    /**
     * The people of the primary line other than $email, unavailable over [$fromUtc, $toUtc).
     */
    private function everybodyElseUnavailable(string $email, string $fromUtc, string $toUtc): void
    {
        foreach (self::MEMBERS as $other) {
            if ($other !== $email) {
                $this->unavailable($other, $fromUtc, $toUtc);
            }
        }
    }

    // --- periods 1, 2 and 3 ---------------------------------------------------------------------

    public function testPeriodsOneTwoAndThreeAreGeneratedEachOnItsOwnLeavingTheOthersUnchanged(): void
    {
        $this->createPeriod('2027-08-01');
        $this->createPeriod('2027-11-01');
        $this->addDuties(1, [['2027-01-05 08:00', '2027-01-05 20:00'], ['2027-02-10 08:00', '2027-02-10 20:00']]);
        $this->addDuties(2, [['2027-05-04 08:00', '2027-05-04 20:00'], ['2027-06-10 08:00', '2027-06-10 20:00']]);
        $this->addDuties(3, [['2027-08-03 08:00', '2027-08-03 20:00']]);

        $job1 = $this->generatePeriod(1);
        self::assertSame('SUCCEEDED', $job1['status']);
        self::assertSame(1, $job1['period']['number']);
        self::assertSame('COMPLETE', $job1['outcome']['coverage']);
        $period1 = $this->fingerprint(1);
        $period3Before = $this->fingerprint(3);

        $job2 = $this->generatePeriod(2);
        self::assertSame('SUCCEEDED', $job2['status']);
        self::assertSame(2, $job2['period']['number']);
        self::assertSame($period1, $this->fingerprint(1), 'generating period 2 never touches period 1');
        self::assertSame($period3Before, $this->fingerprint(3), 'nor period 3');
        self::assertNull($this->holderOn(3, '2027-08-03'), 'period 3 is still not generated');
        $period2 = $this->fingerprint(2);

        $job3 = $this->generatePeriod(3);
        self::assertSame('SUCCEEDED', $job3['status']);
        self::assertSame($period1, $this->fingerprint(1));
        self::assertSame($period2, $this->fingerprint(2));
        self::assertNotNull($this->holderOn(3, '2027-08-03'));

        // Each generation belongs to its own period only.
        self::assertSame(
            [1 => 1, 2 => 1, 3 => 1],
            array_map('intval', array_column($this->db()->fetchAllAssociative(
                "SELECT c.number, COUNT(*) AS n FROM planning_generations g JOIN planning_periods pp ON pp.id = g.planning_period_id JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE g.status = 'COMPLETED' GROUP BY c.number ORDER BY c.number",
            ), 'n', 'number')),
        );

        // An unpublished period is regenerated at will; the others stay as they are.
        $period3 = $this->fingerprint(3);
        self::assertSame('SUCCEEDED', $this->generatePeriod(2)['status']);
        self::assertSame($period1, $this->fingerprint(1));
        self::assertSame($period3, $this->fingerprint(3));
    }

    public function testThePeriodMustBeNamedOnAPlanningWithSeveralPeriods(): void
    {
        $p = $this->s['planningId'];
        // A single period: unchanged, the period is optional.
        $this->api($this->client, 'GET', "/api/plannings/{$p}/generation-preflight", token: $this->s['creator']);
        self::assertResponseIsSuccessful();

        $this->createPeriod('2027-08-01');
        $this->api($this->client, 'GET', "/api/plannings/{$p}/generation-preflight", token: $this->s['creator']);
        $this->assertError(422, 'period_required');
        $this->api($this->client, 'POST', "/api/plannings/{$p}/generations", [], $this->s['creator']);
        $this->assertError(422, 'period_required');
        $this->api($this->client, 'POST', "/api/plannings/{$p}/complete", [], $this->s['creator']);
        $this->assertError(422, 'period_required');
        $this->api($this->client, 'GET', "/api/plannings/{$p}/generation-preflight?period=not-a-period", token: $this->s['creator']);
        $this->assertError(404, 'period_not_found');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_jobs'), 'nothing was queued');

        $preflight = $this->preflight(2);
        self::assertSame(2, $preflight['period']['number']);
    }

    // --- rest and overlap across a boundary (real OR-Tools) -------------------------------------

    public function testTheRestWithThePreviousPeriodIsRespected(): void
    {
        $this->createPeriod('2027-08-01');
        // The night of the last day of period 1 ends at 08:00 Brussels on 1 May — the first day of period 2. The
        // January day is far from period 2: it can constrain nothing there, and is never frozen.
        $this->addDuties(1, [['2027-04-30 20:00', '2027-05-01 08:00'], ['2027-01-05 08:00', '2027-01-05 20:00']]);
        $this->addDuties(2, [['2027-05-01 08:00', '2027-05-01 20:00']]);

        self::assertSame('SUCCEEDED', $this->generatePeriod(1, self::REST)['status']);
        $night = $this->holderOn(1, '2027-04-30');
        self::assertNotNull($night);
        $period1 = $this->fingerprint(1);
        // Only the night's holder could take the 1 May day.
        $this->everybodyElseUnavailable($night, '2027-05-01 06:30', '2027-05-01 23:00');

        $job = $this->generatePeriod(2, self::REST);

        self::assertNull($this->holderOn(2, '2027-05-01'), 'never right after a night of period 1 with an 11-hour rest');
        self::assertSame('INCOMPLETE', $job['outcome']['lines'][0]['coverageStatus']);
        $diagnostics = json_encode($job['outcome']['lines'][0]['diagnostics'], \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('CROSS_LINE_LEGAL_MIN_REST', $diagnostics);
        self::assertStringContainsString('"otherPeriodNumber":1', $diagnostics, 'the explanation names the neighbouring period');
        self::assertStringContainsString('"otherPeriodEndsAt":"2027-05-01"', $diagnostics);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_snapshot_external_commitments WHERE source_cycle_number = 1'), 'the night was frozen in the snapshot of period 2, with its period');
        self::assertSame($period1, $this->fingerprint(1), 'period 1 is only read');
    }

    public function testWithoutRestPolicyTheSamePersonMayFollowOnTheNextPeriod(): void
    {
        $this->createPeriod('2027-08-01');
        $this->addDuties(1, [['2027-04-30 20:00', '2027-05-01 08:00']]);
        $this->addDuties(2, [['2027-05-01 08:00', '2027-05-01 20:00']]);

        $this->generatePeriod(1);
        $night = $this->holderOn(1, '2027-04-30');
        self::assertNotNull($night);
        $this->everybodyElseUnavailable($night, '2027-05-01 06:30', '2027-05-01 23:00');

        $this->generatePeriod(2);

        // Back to back, no overlap, no rest rule: allowed — the exclusion of the previous test is the rest's.
        self::assertSame($night, $this->holderOn(2, '2027-05-01'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_snapshot_external_commitments'), 'without any rest rule, only an overlap could constrain: nothing frozen');
    }

    public function testTheRestIsRespectedWhenTheNextPeriodIsGeneratedFirst(): void
    {
        $this->createPeriod('2027-08-01');
        $this->addDuties(1, [['2027-04-30 20:00', '2027-05-01 08:00']]);
        $this->addDuties(2, [['2027-05-01 08:00', '2027-05-01 20:00']]);

        self::assertSame('SUCCEEDED', $this->generatePeriod(2, self::REST)['status']);
        $morning = $this->holderOn(2, '2027-05-01');
        self::assertNotNull($morning);
        $period2 = $this->fingerprint(2);
        $this->everybodyElseUnavailable($morning, '2027-04-30 17:00', '2027-05-01 06:30');

        $job = $this->generatePeriod(1, self::REST);

        self::assertNull($this->holderOn(1, '2027-04-30'), 'period 2, generated first, constrains period 1 just as much');
        self::assertStringContainsString('"otherPeriodNumber":2', json_encode($job['outcome']['lines'][0]['diagnostics'], \JSON_THROW_ON_ERROR));
        self::assertSame($period2, $this->fingerprint(2));
    }

    public function testANightCrossingTheBoundaryBelongsToItsDayAndNeverOverlapsTheNextPeriod(): void
    {
        $this->createPeriod('2027-08-01');
        $this->addDuties(1, [['2027-04-30 20:00', '2027-05-01 08:00']]);
        // Starts before the night of period 1 ends: an overlap, a HARD conflict even without any rest rule.
        $this->addDuties(2, [['2027-05-01 06:00', '2027-05-01 14:00']]);

        $this->generatePeriod(1);
        $night = $this->holderOn(1, '2027-04-30');
        self::assertNotNull($night, 'the night of the last day is a duty of period 1, generated with it');
        $this->everybodyElseUnavailable($night, '2027-05-01 03:00', '2027-05-01 23:00');

        $job = $this->generatePeriod(2);

        self::assertNull($this->holderOn(2, '2027-05-01'));
        self::assertStringContainsString('CROSS_LINE_CONFLICT', json_encode($job['outcome']['lines'][0]['diagnostics'], \JSON_THROW_ON_ERROR));
    }

    public function testAPersonOnTwoLinesIsNeverOnDutyTwiceAcrossTwoPeriods(): void
    {
        // Alice is on both lines (D160); the other line's period 2 day overlaps her night of period 1.
        $this->addJuniorsWithAlice();
        $this->createPeriod('2027-08-01');
        $this->addDuties(1, [['2027-04-30 20:00', '2027-05-01 08:00']]);
        $this->addDuties(1, [['2027-02-01 08:00', '2027-02-01 20:00']], lineIndex: 1);
        $this->addDuties(2, [['2027-06-01 08:00', '2027-06-01 20:00']]);
        $this->addDuties(2, [['2027-05-01 06:00', '2027-05-01 14:00']], lineIndex: 1);
        $this->unavailable('admin@example.com', '2027-04-29 00:00', '2027-05-02 00:00');
        $this->unavailable('bob@example.com', '2027-04-29 00:00', '2027-05-02 00:00');
        $this->unavailable('junior@example.com', '2027-04-29 00:00', '2027-05-02 00:00');

        $this->generatePeriod(1);
        self::assertSame('alice@example.com', $this->holderOn(1, '2027-04-30'));

        $this->generatePeriod(2);

        self::assertNull($this->holderOn(2, '2027-05-01', lineIndex: 1), 'another line, another period: still the same person');
    }

    // --- concurrency: what changed during the solve ------------------------------------------------

    public function testAResultSolvedAgainstANeighbouringCalendarThatChangedIsNeverWritten(): void
    {
        $this->createPeriod('2027-08-01');
        $this->addDuties(1, [['2027-04-30 20:00', '2027-05-01 08:00']]);
        $this->addDuties(2, [['2027-05-01 08:00', '2027-05-01 20:00'], ['2027-06-01 08:00', '2027-06-01 20:00']]);
        $this->generatePeriod(1, self::REST);
        $night = $this->holderOn(1, '2027-04-30');
        self::assertNotNull($night);

        // During the solve of period 2, the night of period 1 loses its holder (a write the snapshot froze).
        $db = $this->db();
        FaultInjectingPlanningSolver::$duringSolve = static function () use ($db): void {
            $db->executeStatement("UPDATE duty_assignments SET current = false WHERE duty_id IN (SELECT d.id FROM duties d WHERE d.local_date = '2027-04-30')");
        };

        $job = $this->generatePeriod(2, self::REST);

        self::assertSame('FAILED', $job['status']);
        self::assertSame('neighbour_calendar_changed', $job['outcome']['lines'][0]['error']);
        self::assertSame('FAILED', $job['outcome']['lines'][0]['status']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM duty_assignments a JOIN duties d ON d.id = a.duty_id JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE c.number = 2'), 'nothing of the solve was written');
        self::assertStringContainsString('other periods changed', (string) $this->db()->fetchOne("SELECT failure_reason FROM planning_generations WHERE status = 'FAILED'"));
    }

    public function testAResultOfAPeriodPublishedDuringTheSolveIsNeverWritten(): void
    {
        $this->createPeriod('2027-08-01');
        $this->addDuties(2, [['2027-05-04 08:00', '2027-05-04 20:00']]);
        $periodId = $this->periodOf(0, 2)->getId();
        $db = $this->db();
        FaultInjectingPlanningSolver::$duringSolve = static function () use ($db, $periodId): void {
            $db->executeStatement("UPDATE planning_periods SET status = 'PUBLISHED' WHERE id = ?", [$periodId]);
        };

        $job = $this->generatePeriod(2);

        self::assertSame('period_locked', $job['outcome']['lines'][0]['error']);
        self::assertNull($this->holderOn(2, '2027-05-04'));
    }

    /**
     * A real concurrent write on a real path — a manual removal, under the
     * calendar lock — on an earlier line's calendar while the next line is
     * being solved: the case D161 left open. Since D191 nothing of a launch
     * is written before its end: the removal hits the calendar about to be
     * replaced, the next line was solved against what the earlier one is
     * about to hold, and the final calendar is coherent.
     */
    public function testAWriteOnAnEarlierLineDuringALaunchNeverLeavesAnIncoherentCalendar(): void
    {
        $this->addJuniorsWithAlice();
        $this->addDuties(1, [['2027-01-05 08:00', '2027-01-05 20:00']]);
        $this->addDuties(1, [['2027-01-05 21:00', '2027-01-06 07:00']], lineIndex: 1);
        $this->unavailable('admin@example.com', '2027-01-04 00:00', '2027-01-07 00:00');
        $this->unavailable('bob@example.com', '2027-01-04 00:00', '2027-01-07 00:00');
        $this->generatePeriod(1, self::REST);
        self::assertSame('alice@example.com', $this->holderOn(1, '2027-01-05'));
        $creatorId = static::getContainer()->get(UserRepository::class)->findOneByEmail('creator@example.com')->getId();
        $container = static::getContainer();

        // The callback runs during the FIRST solve (Seniors): it arms itself again for the second one (Juniors) —
        // then removes Alice from the Seniors day of the current calendar, through the real service.
        FaultInjectingPlanningSolver::$duringSolve = static function () use ($container, $creatorId): void {
            FaultInjectingPlanningSolver::$duringSolve = static function () use ($container, $creatorId): void {
                $em = $container->get(EntityManagerInterface::class);
                $duty = null;
                foreach ($container->get(DutyRepository::class)->findAll() as $candidate) {
                    if ('2027-01-05' === $candidate->getLocalDate()->format('Y-m-d') && 'Seniors' === $candidate->getPlanningPeriod()->getName()) {
                        $duty = $candidate;
                    }
                }
                self::assertNotNull($duty);
                $generation = $container->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($duty->getPlanningPeriod());
                self::assertNotNull($generation);
                $holder = $container->get(DutyAssignmentRepository::class)->findCurrentByGenerationAndDuties($generation, [$duty])[(int) $duty->getId()]->getTeamMember();
                self::assertSame('alice@example.com', $holder->getUser()->getEmail());
                $container->get(DutyReassignmentService::class)->unassign($duty, (string) $holder->getStableId(), $em->find(User::class, $creatorId));
            };
        };

        $job = $this->generatePeriod(1, self::REST);

        self::assertSame('SUCCEEDED', $job['status'], json_encode($job['outcome'], \JSON_THROW_ON_ERROR));
        self::assertSame('alice@example.com', $this->holderOn(1, '2027-01-05'), 'Seniors: the new result, written at the end');
        self::assertSame('junior@example.com', $this->holderOn(1, '2027-01-05', lineIndex: 1), 'Juniors: solved against the planned Seniors day, never the removed one');
    }

    // --- jobs -----------------------------------------------------------------------------------

    public function testAJobAbandonedOnAFuturePeriodFailsOnlyThatPeriodsGenerations(): void
    {
        $this->createPeriod('2027-08-01');
        $this->addDuties(1, [['2027-01-05 08:00', '2027-01-05 20:00']]);
        $this->addDuties(2, [['2027-05-04 08:00', '2027-05-04 20:00']]);
        $creatorId = static::getContainer()->get(UserRepository::class)->findOneByEmail('creator@example.com')->getId();
        $generations = static::getContainer()->get(PlanningGenerationService::class);
        $period1 = $this->periodOf(0, 1);
        $current = $generations->create($period1, $this->em()->find(User::class, $creatorId));
        $period2 = $this->periodOf(0, 2);
        $future = $generations->create($period2, $this->em()->find(User::class, $creatorId));
        $this->db()->executeStatement("UPDATE planning_generations SET status = 'SOLVING' WHERE id IN (?, ?)", [$current->getId(), $future->getId()]);

        $cycle2 = $this->cycle(2);
        $planning = $cycle2->getPlanning();
        $job = new PlanningJob($planning, PlanningJobKind::GENERATE, $this->em()->find(User::class, $creatorId));
        $job->assignToCycle($cycle2);
        $this->em()->persist($job);
        $this->em()->flush();
        $this->db()->executeStatement("UPDATE planning_jobs SET status = 'RUNNING', started_at = LOCALTIMESTAMP - interval '1 hour', heartbeat_at = LOCALTIMESTAMP - interval '1 hour' WHERE id = ?", [$job->getId()]);

        self::assertSame(1, static::getContainer()->get(PlanningJobRecovery::class)->recover((int) $planning->getId()));

        self::assertSame(['FAILED', 'worker_lost'], array_values($this->db()->fetchNumeric('SELECT status, failure_code FROM planning_jobs WHERE id = ?', [$job->getId()])));
        self::assertSame('FAILED', $this->db()->fetchOne('SELECT status FROM planning_generations WHERE id = ?', [$future->getId()]), 'the job\'s period');
        self::assertSame('SOLVING', $this->db()->fetchOne('SELECT status FROM planning_generations WHERE id = ?', [$current->getId()]), 'never another period\'s');
    }

    // --- I8: a published period is never generated again ----------------------------------------

    public function testAPublishedPeriodIsNeverRegeneratedNorGetsNewDuties(): void
    {
        $this->addDuties(1, [['2027-01-05 08:00', '2027-01-05 20:00']]);
        $this->generatePeriod(1);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $this->createPeriod('2027-08-01');
        $period1 = $this->fingerprint(1);

        // A weekly structure set after the publication applies to the next periods only.
        $line = $this->line(0);
        $this->api($this->client, 'PUT', "/api/planning-lines/{$line->getStableId()}/week-structure", [
            'blocks' => [], 'solo' => ['MAR'], 'soloFamily' => '', 'excluded' => ['LUN', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'],
        ], $this->s['creator']);
        self::assertResponseIsSuccessful();

        $preflight = $this->preflight(1);
        self::assertFalse($preflight['canGenerate']);
        self::assertSame(['PERIOD_LOCKED'], array_column($preflight['blockers'], 'code'));
        self::assertSame($period1, $this->fingerprint(1), 'no Tuesday materialized in the published period');
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", ['period' => $this->periodId(1)], $this->s['creator']);
        $this->assertError(409, 'not_launchable');

        // The next period gets them, and is generated.
        $preflight2 = $this->preflight(2);
        self::assertTrue($preflight2['canGenerate'], json_encode($preflight2['blockers'], \JSON_THROW_ON_ERROR));
        self::assertSame(13, $preflight2['lines'][0]['dutyCount'], 'every Tuesday of 2027-05-01 → 2027-08-01');
        self::assertSame('SUCCEEDED', $this->generatePeriod(2)['status']);
        self::assertSame($period1, $this->fingerprint(1));

        // The technical synchronous path is closed to a planning with several periods.
        $this->api($this->client, 'POST', "/api/planning-periods/{$this->periodOf(0, 1)->getStableId()}/generations", [], $this->s['admin']);
        $this->assertError(409, 'multiple_periods_not_supported');
    }

    public function testAPublishedPeriodIsStillCompletedRespectingTheNextPeriod(): void
    {
        $this->addDuties(1, [['2027-04-30 20:00', '2027-05-01 08:00']]);
        $this->generatePeriod(1, self::REST);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
        // "Retirer l'affectation" while the planning still has a single period: a hole in the published period.
        $this->unassignDuty($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-04-30'));
        self::assertResponseIsSuccessful();
        $this->createPeriod('2027-08-01');
        $this->addDuties(2, [['2027-05-01 08:00', '2027-05-01 20:00']]);
        // Alice takes 1 May in period 2.
        $this->everybodyElseUnavailable('alice@example.com', '2027-05-01 06:30', '2027-05-01 23:00');
        $this->generatePeriod(2, self::REST);
        self::assertSame('alice@example.com', $this->holderOn(2, '2027-05-01'));
        $period2 = $this->fingerprint(2);

        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/complete", ['period' => $this->periodId(1)], $this->s['creator']);
        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->runQueuedPlanningJobs());
        $job = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/jobs/latest", token: $this->s['creator'])['job'];

        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        self::assertSame(1, $job['period']['number']);
        $holder = $this->holderOn(1, '2027-04-30');
        self::assertNotNull($holder, 'a published period stays completable (I8)');
        self::assertNotSame('alice@example.com', $holder, 'never the person on duty right after, in period 2');
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM duty_assignment_events WHERE was_published AND new_assignment_id IS NOT NULL'), 'recorded as a change of the published calendar');
        self::assertSame($period2, $this->fingerprint(2), 'completing period 1 never touches period 2');
    }

    /**
     * Only the live check across periods can exclude Alice here: period 1
     * was generated (snapshot: everybody free, nothing in period 2 yet)
     * before period 2 existed; the others became unavailable that night
     * since. Alice is free that night — but works right after, in period 2.
     */
    public function testACompletionLeavesAHoleRatherThanBreakTheRestWithTheNextPeriod(): void
    {
        $this->addDuties(1, [['2027-04-30 20:00', '2027-05-01 08:00']]);
        $this->generatePeriod(1, self::REST);
        $this->unassignDuty($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-04-30'));
        self::assertResponseIsSuccessful();
        $this->createPeriod('2027-08-01');
        $this->addDuties(2, [['2027-05-01 08:00', '2027-05-01 20:00']]);
        $this->everybodyElseUnavailable('alice@example.com', '2027-05-01 06:30', '2027-05-01 23:00');
        $this->generatePeriod(2, self::REST);
        self::assertSame('alice@example.com', $this->holderOn(2, '2027-05-01'));
        $this->everybodyElseUnavailable('alice@example.com', '2027-04-30 17:00', '2027-05-01 06:15');

        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/complete", ['period' => $this->periodId(1)], $this->s['creator']);
        self::assertResponseStatusCodeSame(202);
        $this->runQueuedPlanningJobs();
        $job = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/jobs/latest", token: $this->s['creator'])['job'];

        self::assertSame('INCOMPLETE', $job['outcome']['coverage']);
        self::assertNull($this->holderOn(1, '2027-04-30'), 'a hole rather than a rest broken with period 2');
    }
}
