<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PlanningGenerationStatus;
use App\Entity\PlanningPeriodStatus;
use App\Message\RunPlanningJob;
use App\MessageHandler\RunPlanningJobHandler;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Service\DutyReassignmentService;
use App\Service\PlanningJobRecovery;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\FaultInjectingPlanningSolver;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Worker;

/**
 * Engine jobs run outside the HTTP request (docs/decisions.md D149):
 * "Générer le planning" and "Compléter automatiquement" answer 202 with a
 * QUEUED job, the worker (run explicitly here, the real handler over the
 * real pipeline and a real OR-Tools solve) takes it to SUCCEEDED or
 * FAILED, the state is readable at any time, and no job ever stays RUNNING
 * — nor any generation SOLVING — once its worker is gone.
 */
final class PlanningJobControllerTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;

    private const STANDALONE = [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07'], ['2027-01-07', '2027-01-08']];

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    protected function tearDown(): void
    {
        FaultInjectingPlanningSolver::reset();
        parent::tearDown();
    }

    // --- generation -------------------------------------------------------------------

    public function testLaunchingAnswersAtOnceWithAQueuedJobAndRunsNoSolverInTheRequest(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);

        $response = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        self::assertResponseStatusCodeSame(202);
        self::assertSame('QUEUED', $response['job']['status']);
        self::assertSame('GENERATE', $response['job']['kind']);
        self::assertNull($response['job']['startedAt']);
        self::assertSame(1, $this->queuedPlanningJobMessageCount(), 'Exactly one message waits for the worker.');
        self::assertSame([], $this->generationsOf($s['planningId']), 'Nothing of the pipeline ran in the request: no generation, a fortiori none SOLVING.');
    }

    public function testTheWorkerRunsThePipelineToSucceededWithACompleteCoverage(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);

        $job = $this->generateNow($client, $s['planningId'], $s['creator']);

        self::assertSame('SUCCEEDED', $job['status']);
        self::assertSame('COMPLETE', $job['outcome']['coverage']);
        self::assertSame('COMPLETED', $job['outcome']['lines'][0]['status']);
        self::assertNotNull($job['startedAt']);
        self::assertNotNull($job['finishedAt']);
        self::assertNotContains(null, $this->currentCalendar($s['planningId']), 'The calendar is really generated.');
        self::assertSame([PlanningGenerationStatus::COMPLETED], $this->generationsOf($s['planningId']));
    }

    public function testAnIncompleteCoverageSucceedsWithItsRealDiagnostics(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']]);
        foreach (['admin', 'alice', 'bob'] as $who) {
            $this->declareRange($client, $s[$who], '2027-01-05', '2027-01-06');
        }

        $job = $this->generateNow($client, $s['planningId'], $s['creator']);

        self::assertSame('SUCCEEDED', $job['status'], 'An uncovered duty is a result, not a technical failure.');
        self::assertSame('INCOMPLETE', $job['outcome']['coverage']);
        self::assertSame('INCOMPLETE', $job['outcome']['lines'][0]['coverageStatus']);
        self::assertNotEmpty($job['outcome']['lines'][0]['diagnostics']['unassignedDuties']);
        $result = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/result", token: $s['creator']);
        self::assertNotEmpty($result['lines'][0]['duties'][0]['reasons'], 'The persisted diagnostics still explain the uncovered duty.');
    }

    public function testASolverExceptionFailsTheJobAndLeavesNoGenerationSolving(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        FaultInjectingPlanningSolver::$failWith = new \RuntimeException('CP-SAT exploded (test)');

        $job = $this->generateNow($client, $s['planningId'], $s['creator']);

        self::assertSame('FAILED', $job['status']);
        self::assertSame('unexpected_error', $job['failureCode']);
        self::assertArrayNotHasKey('failureDetail', $job, 'The internal detail never reaches the API…');
        self::assertStringNotContainsString('CP-SAT exploded', (string) json_encode($job));
        self::assertStringContainsString('CP-SAT exploded', (string) $this->jobRow($s['planningId'])['failure_detail'], '…it stays server-side.');
        self::assertSame([PlanningGenerationStatus::FAILED], $this->generationsOf($s['planningId']), 'Never an eternal SOLVING.');
    }

    public function testAFailedGenerationCanBeRelaunched(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        FaultInjectingPlanningSolver::$failWith = new \RuntimeException('boom');
        self::assertSame('FAILED', $this->generateNow($client, $s['planningId'], $s['creator'])['status']);

        FaultInjectingPlanningSolver::reset();
        $job = $this->generateNow($client, $s['planningId'], $s['creator']);

        self::assertSame('SUCCEEDED', $job['status']);
        self::assertNotContains(null, $this->currentCalendar($s['planningId']));
    }

    public function testADataChangeBetweenTheClickAndTheWorkerIsCaughtByThePreflightAgain(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        self::assertResponseStatusCodeSame(202);

        // Published (by another path) before the worker picked the job up.
        $this->transitionPrimaryPeriod($s['planningId'], PlanningPeriodStatus::GENERATED, PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED);
        $this->runQueuedPlanningJobs();

        $job = $this->latestJob($client, $s);
        self::assertSame('FAILED', $job['status']);
        self::assertSame('not_launchable', $job['failureCode']);
        self::assertSame([], $this->generationsOf($s['planningId']));
    }

    // --- concurrency ------------------------------------------------------------------

    public function testADoubleClickQueuesOneJobOnly(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);

        $first = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        self::assertResponseStatusCodeSame(202);
        $second = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('job_in_progress', $second['error']);
        self::assertSame($first['job']['stableId'], $second['job']['stableId'], 'The refusal names the job that is already running.');
    }

    public function testTwoManagersCannotStartTwoEngineRuns(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        self::assertResponseStatusCodeSame(202);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['admin']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testTheDatabaseItselfRefusesASecondActiveJob(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        self::assertResponseStatusCodeSame(202);

        // Two requests that both passed the "any active job?" read at the same instant: the partial
        // unique index is what stops the second one.
        $row = $this->jobRow($s['planningId']);
        $this->expectException(UniqueConstraintViolationException::class);
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "INSERT INTO planning_jobs (stable_id, kind, status, created_at, planning_id, requested_by_id) VALUES (gen_random_uuid(), 'COMPLETE', 'QUEUED', now(), :planning, :user)",
            ['planning' => $row['planning_id'], 'user' => $row['requested_by_id']],
        );
    }

    public function testGenerationAndCompletionNeverRunTogether(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        self::assertResponseStatusCodeSame(202);
        $refused = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/complete", [], $s['admin']);
        self::assertResponseStatusCodeSame(409, 'No completion while a generation is queued or running.');
        self::assertSame('GENERATE', $refused['job']['kind']);
        $this->runQueuedPlanningJobs();

        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'));
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/complete", [], $s['creator']);
        self::assertResponseStatusCodeSame(202);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['admin']);
        self::assertResponseStatusCodeSame(409, 'No generation while a completion is queued or running.');
    }

    // --- completion -------------------------------------------------------------------

    public function testCompletionIsQueuedAndOnlyTheWorkerFillsTheHoles(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'));
        $withHole = $this->currentCalendar($s['planningId']);

        $response = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/complete", [], $s['creator']);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(['COMPLETE', 'QUEUED'], [$response['job']['kind'], $response['job']['status']]);
        self::assertSame($withHole, $this->currentCalendar($s['planningId']), 'Nothing is written by the request itself.');

        $this->runQueuedPlanningJobs();
        $job = $this->latestJob($client, $s);
        self::assertSame('SUCCEEDED', $job['status']);
        self::assertSame(1, $job['outcome']['lines'][0]['filledUnitCount']);

        $after = $this->currentCalendar($s['planningId']);
        self::assertNotNull($after['0|2027-01-06|ONCALL']);
        unset($withHole['0|2027-01-06|ONCALL'], $after['0|2027-01-06|ONCALL']);
        self::assertSame($withHole, $after, 'Every existing assignment stayed exactly as it was.');
    }

    public function testACalendarEditDuringTheCompletionMakesItsResultObsolete(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'));
        $monday = $this->dutyEntityOn($s['planningId'], '2027-01-05');

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/complete", [], $s['creator']);
        self::assertResponseStatusCodeSame(202);

        // A manager removes Monday's holder while the solver is computing the completion.
        FaultInjectingPlanningSolver::$duringSolve = function () use ($monday): void {
            $container = static::getContainer();
            $duty = $container->get(\App\Repository\DutyRepository::class)->find($monday->getId());
            $generation = $container->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($duty->getPlanningPeriod());
            $current = $container->get(\App\Repository\DutyAssignmentRepository::class)->findCurrentByGenerationAndDuty($generation, $duty);
            $container->get(DutyReassignmentService::class)->unassign($duty, (string) $current->getTeamMember()->getStableId(), $this->userOf('creator@example.com'), false);
        };
        $this->runQueuedPlanningJobs();

        $job = $this->latestJob($client, $s);
        self::assertSame('FAILED', $job['status']);
        self::assertSame('calendar_changed', $job['failureCode']);
        $calendar = $this->currentCalendar($s['planningId']);
        self::assertNull($calendar['0|2027-01-06|ONCALL'], 'The obsolete result was not applied.');
        self::assertNull($calendar['0|2027-01-05|ONCALL'], 'The concurrent edit stands.');
    }

    // --- following a job ----------------------------------------------------------------

    public function testTheStateOfARunningJobIsFoundAgainByAnyViewer(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/jobs/latest", token: $s['creator']);
        self::assertNull($this->api($client, 'GET', "/api/plannings/{$s['planningId']}/jobs/latest", token: $s['creator'])['job']);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        $jobId = (int) $this->jobRow($s['planningId'])['id'];

        // "Coming back to the page": another request, another person.
        self::assertSame('QUEUED', $this->latestJob($client, $s, $s['alice'])['status']);
        static::getContainer()->get(\App\Service\PlanningJobStore::class)->claim($jobId);
        $running = $this->latestJob($client, $s, $s['admin']);
        self::assertSame('RUNNING', $running['status']);
        self::assertSame('GENERATE', $running['kind']);

        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/jobs/latest", token: $s['outsider']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testOnlyManagersStartJobs(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['alice']);
        self::assertResponseStatusCodeSame(403);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/complete", [], $s['alice']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->queuedPlanningJobMessageCount());
    }

    public function testAPublishedPlanningStillRefusesAGenerationAndCreatesNoJob(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();

        $refused = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_launchable', $refused['error']);
        self::assertContains('PERIOD_LOCKED', array_column($refused['blockers'], 'code'));
        self::assertSame(0, $this->queuedPlanningJobMessageCount());
        self::assertSame('GENERATE', $this->latestJob($client, $s)['kind'], 'Still the first generation job — nothing new was queued.');
        self::assertSame('SUCCEEDED', $this->latestJob($client, $s)['status']);

        // …while completing a published planning is still possible.
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'));
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/complete", [], $s['creator']);
        self::assertResponseStatusCodeSame(202);
    }

    // --- abandoned jobs -------------------------------------------------------------------

    public function testARunningJobWhoseWorkerDiedIsFailedAndFreesThePlanning(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        $row = $this->jobRow($s['planningId']);

        // The worker claimed it, put a generation in SOLVING, then was killed (no catch/finally ran).
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("UPDATE planning_jobs SET status = 'RUNNING', started_at = now() - interval '2 hours', heartbeat_at = now() - interval '10 minutes' WHERE id = :id", ['id' => $row['id']]);
        $generation = $this->primaryGenerationEntity($s['planningId']);
        $connection->executeStatement("UPDATE planning_generations SET status = 'SOLVING' WHERE id = :id", ['id' => $generation->getId()]);

        $job = $this->latestJob($client, $s);
        self::assertSame('FAILED', $job['status']);
        self::assertSame(PlanningJobRecovery::WORKER_LOST, $job['failureCode']);
        self::assertSame('FAILED', $connection->fetchOne('SELECT status FROM planning_generations WHERE id = :id', ['id' => $generation->getId()]), 'No eternal SOLVING.');

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        self::assertResponseStatusCodeSame(202, 'The manager can relaunch at once.');
    }

    public function testALongJobThatKeepsBeatingIsNeverFailedForItsDuration(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        $row = $this->jobRow($s['planningId']);

        // Started two hours ago, but its heartbeat is recent: a legitimately long solve.
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "UPDATE planning_jobs SET status = 'RUNNING', started_at = now() - interval '2 hours', heartbeat_at = now() - interval '30 seconds' WHERE id = :id",
            ['id' => $row['id']],
        );

        self::assertSame('RUNNING', $this->latestJob($client, $s)['status']);
        self::assertSame(0, static::getContainer()->get(PlanningJobRecovery::class)->recover());
    }

    public function testAJobNoWorkerEverStartedIsFailedAfterItsDelay(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        $row = $this->jobRow($s['planningId']);
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "UPDATE planning_jobs SET created_at = now() - interval '2 hours' WHERE id = :id",
            ['id' => $row['id']],
        );

        $job = $this->latestJob($client, $s);
        self::assertSame('FAILED', $job['status']);
        self::assertSame(PlanningJobRecovery::NEVER_STARTED, $job['failureCode']);
    }

    public function testAStartingWorkerRecoversDeadJobsAndARedeliveredMessageDoesNothing(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        $row = $this->jobRow($s['planningId']);
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("UPDATE planning_jobs SET status = 'RUNNING', started_at = now() - interval '1 hour', heartbeat_at = now() - interval '1 hour' WHERE id = :id", ['id' => $row['id']]);

        $worker = new Worker([], static::getContainer()->get('messenger.default_bus'));
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch(new WorkerStartedEvent($worker));
        self::assertSame('FAILED', $connection->fetchOne('SELECT status FROM planning_jobs WHERE id = :id', ['id' => $row['id']]));

        // The dead worker's message comes back after the redelivery timeout: it must not run a second time.
        static::getContainer()->get(RunPlanningJobHandler::class)(new RunPlanningJob((int) $row['id']));
        self::assertSame('FAILED', $connection->fetchOne('SELECT status FROM planning_jobs WHERE id = :id', ['id' => $row['id']]));
        self::assertSame([], $this->generationsOf($s['planningId']));
    }

    public function testTheTechnicalSynchronousSolveIsRefusedWhileAJobIsActive(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $detail = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator']);
        $generation = $this->api($client, 'POST', "/api/planning-periods/{$detail['lines'][0]['planningPeriodStableId']}/generations", [], $s['admin']);
        $this->api($client, 'POST', "/api/planning-generations/{$generation['stableId']}/snapshot", [], $s['admin']);
        self::assertResponseIsSuccessful();

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        self::assertResponseStatusCodeSame(202);

        $refused = $this->api($client, 'POST', "/api/planning-generations/{$generation['stableId']}/solve", [], $s['admin']);
        self::assertResponseStatusCodeSame(409, 'Never a second engine run next to the queued job.');
        self::assertSame('job_in_progress', $refused['error']);
    }

    public function testAnOrphanSolvingGenerationFromADeadSynchronousRequestIsFailed(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $generationId = $this->primaryGenerationEntity($s['planningId'])->getId();

        // SOLVING for 20 minutes, no job running on the planning: a synchronous request killed at 30 s.
        $connection->executeStatement("UPDATE planning_generations SET status = 'SOLVING', updated_at = LOCALTIMESTAMP - interval '20 minutes' WHERE id = :id", ['id' => $generationId]);
        static::getContainer()->get(PlanningJobRecovery::class)->recover();
        self::assertSame('FAILED', $connection->fetchOne('SELECT status FROM planning_generations WHERE id = :id', ['id' => $generationId]));

        // Only 5 minutes old: possibly still alive (a synchronous request cannot be that old — leave it).
        $connection->executeStatement("UPDATE planning_generations SET status = 'SOLVING', updated_at = LOCALTIMESTAMP - interval '5 minutes' WHERE id = :id", ['id' => $generationId]);
        static::getContainer()->get(PlanningJobRecovery::class)->recover();
        self::assertSame('SOLVING', $connection->fetchOne('SELECT status FROM planning_generations WHERE id = :id', ['id' => $generationId]));
    }

    public function testASolvingGenerationOfALiveJobIsNeverTreatedAsOrphan(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        $jobId = $this->jobRow($s['planningId'])['id'];
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $generationId = $this->primaryGenerationEntity($s['planningId'])->getId();

        // A long, live job (fresh heartbeat) whose generation has been SOLVING for an hour.
        $connection->executeStatement("UPDATE planning_jobs SET status = 'RUNNING', started_at = LOCALTIMESTAMP - interval '1 hour', heartbeat_at = LOCALTIMESTAMP WHERE id = :id", ['id' => $jobId]);
        $connection->executeStatement("UPDATE planning_generations SET status = 'SOLVING', updated_at = LOCALTIMESTAMP - interval '1 hour' WHERE id = :id", ['id' => $generationId]);
        static::getContainer()->get(PlanningJobRecovery::class)->recover();

        self::assertSame('SOLVING', $connection->fetchOne('SELECT status FROM planning_generations WHERE id = :id', ['id' => $generationId]));
        self::assertSame('RUNNING', $connection->fetchOne('SELECT status FROM planning_jobs WHERE id = :id', ['id' => $jobId]));
    }

    public function testOnlyManagersSeeAJobsDetailedOutcome(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generateNow($client, $s['planningId'], $s['creator']);

        $asMember = $this->latestJob($client, $s, $s['alice']);
        self::assertSame('SUCCEEDED', $asMember['status'], 'A member sees the job\'s state…');
        self::assertNull($asMember['outcome'], '…never the solver\'s diagnostics.');
        self::assertNotNull($this->latestJob($client, $s, $s['admin'])['outcome']);
    }

    public function testTheHeartbeatRecordsThatTheWorkerIsAlive(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        $jobId = (int) $this->jobRow($s['planningId'])['id'];
        $container = static::getContainer();
        $connection = $container->get(EntityManagerInterface::class)->getConnection();
        $container->get(\App\Service\PlanningJobStore::class)->claim($jobId);
        $connection->executeStatement("UPDATE planning_jobs SET heartbeat_at = LOCALTIMESTAMP - interval '1 hour' WHERE id = :id", ['id' => $jobId]);

        $heartbeat = $container->get(\App\Service\PlanningJobHeartbeat::class);
        $heartbeat->start($jobId);
        $heartbeat->beat();
        $afterFirstBeat = $connection->fetchOne('SELECT heartbeat_at FROM planning_jobs WHERE id = :id', ['id' => $jobId]);
        $connection->executeStatement("UPDATE planning_jobs SET heartbeat_at = LOCALTIMESTAMP - interval '1 hour' WHERE id = :id", ['id' => $jobId]);
        $heartbeat->beat(); // within INTERVAL_SECONDS of the previous beat: throttled, nothing written
        $heartbeat->stop();

        self::assertTrue((bool) $connection->fetchOne("SELECT :at::timestamp > LOCALTIMESTAMP - interval '1 minute'", ['at' => $afterFirstBeat]), 'The beat recorded "now".');
        self::assertTrue((bool) $connection->fetchOne("SELECT heartbeat_at < LOCALTIMESTAMP - interval '50 minutes' FROM planning_jobs WHERE id = :id", ['id' => $jobId]), 'A second beat right after is throttled.');
    }

    // --- helpers ---------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function latestJob(KernelBrowser $client, array $s, ?string $token = null): array
    {
        $job = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/jobs/latest", token: $token ?? $s['creator'])['job'];
        self::assertResponseIsSuccessful();
        self::assertNotNull($job);

        return $job;
    }

    /**
     * @return array<string, mixed> the most recent planning_jobs row of the planning
     */
    private function jobRow(string $planningStableId): array
    {
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($planningStableId);

        return static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT * FROM planning_jobs WHERE planning_id = :planning ORDER BY id DESC LIMIT 1',
            ['planning' => $planning->getId()],
        );
    }

    /**
     * @return list<PlanningGenerationStatus> every generation of the primary line, oldest first
     */
    private function generationsOf(string $planningStableId): array
    {
        $container = static::getContainer();
        $container->get(EntityManagerInterface::class)->clear();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0];

        return array_map(
            static fn ($g) => $g->getStatus(),
            array_reverse($container->get(PlanningGenerationRepository::class)->findByPlanningPeriod($line->getPlanningPeriod())),
        );
    }

    private function primaryGenerationEntity(string $planningStableId): \App\Entity\PlanningGeneration
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0];

        return $container->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($line->getPlanningPeriod());
    }

    private function transitionPrimaryPeriod(string $planningStableId, PlanningPeriodStatus ...$statuses): void
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $period = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningPeriod();
        // Bypasses the lifecycle's own checks on purpose: the test only needs the status the worker's preflight will see.
        foreach ($statuses as $status) {
            $container->get(EntityManagerInterface::class)->getConnection()->executeStatement(
                'UPDATE planning_periods SET status = :status WHERE id = :id',
                ['status' => $status->value, 'id' => $period->getId()],
            );
        }
        $container->get(EntityManagerInterface::class)->clear();
    }
}
