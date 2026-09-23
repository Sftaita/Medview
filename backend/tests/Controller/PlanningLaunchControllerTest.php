<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PlanningPeriodStatus;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Service\PlanningPeriodLifecycleService;
use App\Tests\PlanningPilotTestHelpers;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Planning-level "Générer le planning" (docs/decisions.md D129): the
 * preflight and the launch over the existing per-line pipeline. A real
 * OR-Tools solve runs, as in PlanningGenerationControllerTest.
 */
final class PlanningLaunchControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use PlanningPilotTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function preflight(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, array $s, ?string $token = null): array
    {
        $result = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/generation-preflight", token: $token ?? $s['creator']);
        self::assertResponseIsSuccessful();

        return $result;
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function launch(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, array $s, ?string $token = null): array
    {
        return $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $token ?? $s['creator']);
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function snapshotOf(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, array $s, string $generationStableId): array
    {
        $snapshot = $this->api($client, 'GET', "/api/planning-generations/{$generationStableId}/snapshot", token: $s['admin']);
        self::assertResponseIsSuccessful();

        return $snapshot;
    }

    /** Moves the primary line's period through its lifecycle, with services resolved fresh (the kernel reboots around each request). */
    private function transitionPeriod(string $planningStableId, PlanningPeriodStatus $target): void
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $period = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningPeriod();
        $container->get(PlanningPeriodLifecycleService::class)->transition($period, $target);
    }

    // --- preflight ---------------------------------------------------------------

    public function testPreflightReportsTheCollectionAndTheDeadline(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", ['noUnavailability' => true], $s['alice']);
        $this->declareRange($client, $s['bob'], '2027-02-10', '2027-02-12');
        $this->declareRange($client, $s['bob'], '2027-03-01', '2027-03-02');
        $this->declareRange($client, $s['bob'], '2027-06-01', '2027-06-05');   // outside the period: not counted
        $this->api($client, 'PATCH', "/api/plannings/{$s['planningId']}/settings", ['availabilityDeadline' => '2026-12-25'], $s['creator']);

        $preflight = $this->preflight($client, $s, $s['admin']);

        self::assertSame('2027-01-01', $preflight['planning']['startsAt']);
        self::assertSame('2027-04-30', $preflight['planning']['lastDay']);
        self::assertSame(3, $preflight['participantCount']);
        self::assertSame(1, $preflight['confirmedCount']);
        self::assertSame(2, $preflight['pendingCount']);
        self::assertSame(2, $preflight['unavailabilityCount']);
        self::assertSame('2026-12-25', $preflight['availabilityDeadline']);
        self::assertNull($preflight['deadlineOverdueDays']);
        self::assertTrue($preflight['canGenerate']);
        self::assertSame([], $preflight['blockers']);
        self::assertSame(['PENDING_MEMBERS'], array_column($preflight['warnings'], 'code'));
        self::assertCount(1, $preflight['lines']);
        self::assertSame(2, $preflight['lines'][0]['dutyCount']);
        self::assertSame(3, $preflight['lines'][0]['memberCount']);
        self::assertTrue($preflight['lines'][0]['hasActiveRuleSet']);
    }

    public function testEverybodyConfirmedMeansNoWarning(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        foreach (['admin', 'alice', 'bob'] as $who) {
            $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", [], $s[$who]);
        }

        $preflight = $this->preflight($client, $s);

        self::assertSame(3, $preflight['confirmedCount']);
        self::assertSame(0, $preflight['pendingCount']);
        self::assertSame([], $preflight['warnings']);
    }

    public function testAPassedDeadlineIsAWarningNotABlocker(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $this->api($client, 'PATCH', "/api/plannings/{$s['planningId']}/settings", ['availabilityDeadline' => '2026-12-20'], $s['creator']);
        self::mockTime('2026-12-22 09:00:00 Europe/Brussels');

        $preflight = $this->preflight($client, $s);

        self::assertSame(2, $preflight['deadlineOverdueDays']);
        self::assertContains('DEADLINE_PASSED', array_column($preflight['warnings'], 'code'));
        self::assertTrue($preflight['canGenerate']);
    }

    public function testPreflightAndLaunchAreReservedToManagers(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);

        foreach (['alice', 'outsider'] as $who) {
            $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/generation-preflight", token: $s[$who]);
            self::assertResponseStatusCodeSame(403);
            $this->launch($client, $s, $s[$who]);
            self::assertResponseStatusCodeSame(403);
        }
        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/generation-preflight");
        self::assertResponseStatusCodeSame(401);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations");
        self::assertResponseStatusCodeSame(401);

        $detail = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator']);
        $this->api($client, 'GET', "/api/planning-periods/{$detail['lines'][0]['planningPeriodStableId']}/generations", token: $s['admin']);
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true), 'Nothing was generated by the refused calls.');
    }

    public function testPlanningExposesCanGenerate(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        self::assertTrue($this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['admin'])['canGenerate']);
        self::assertFalse($this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['alice'])['canGenerate']);
    }

    // --- launch: never blocked by the organisational state -----------------------

    public function testGenerationIsPossibleWithPendingMembersAndAPassedDeadline(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $this->api($client, 'PATCH', "/api/plannings/{$s['planningId']}/settings", ['availabilityDeadline' => '2026-12-20'], $s['creator']);
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", [], $s['alice']);
        self::mockTime('2027-01-15 09:00:00 Europe/Brussels');

        // bob and the admin never answered, and the deadline passed almost a month ago.
        $status = $this->collectionStatus($client, $s);
        self::assertSame(2, $status['summary']['pendingCount']);
        self::assertGreaterThan(0, $status['deadlineOverdueDays']);

        $result = $this->launch($client, $s, $s['admin']);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $result['lines']);
        $line = $result['lines'][0];
        self::assertSame('COMPLETED', $line['status']);
        self::assertNull($line['error']);
        self::assertSame('COMPLETE', $line['coverageStatus']);
        self::assertSame(2, $line['assignmentCount']);
        self::assertSame(0, $line['unassignedDutyCount']);
        self::assertSame(3, $line['snapshot']['memberCount'], 'Pending members are part of the snapshot: their calendar is what it is.');

        // The existing pipeline's records: generation + snapshot are real and readable.
        $generation = $this->api($client, 'GET', "/api/planning-generations/{$line['generationStableId']}", token: $s['admin']);
        self::assertSame('COMPLETED', $generation['status']);
        self::assertNotNull($generation['solverRun']['snapshotHash']);
        self::assertSame(PlanningPeriodStatus::GENERATED, $this->periodStatusOf($s['planningId']));
        self::assertSame('GENERATED', $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['admin'])['lines'][0]['periodStatus'], 'The planning page shows the line\'s lifecycle.');
        $this->snapshotOf($client, $s, $line['generationStableId']);
    }

    // --- snapshot: current state at launch, immutable afterwards ------------------

    public function testTheSnapshotIsTheStateAtTheMomentOfTheLaunchAndNeverChangesAfterwards(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $bobUserId = (string) $this->userOf('bob@example.com')->getStableId();

        // Deadline 25/09-style scenario: an absence is added *after* the deadline, before the launch.
        $this->api($client, 'PATCH', "/api/plannings/{$s['planningId']}/settings", ['availabilityDeadline' => '2026-12-20'], $s['creator']);
        self::mockTime('2026-12-28 09:00:00 Europe/Brussels');
        $late = $this->declareRange($client, $s['bob'], '2027-02-10', '2027-02-12');

        $first = $this->launch($client, $s);
        self::assertResponseStatusCodeSame(201);
        $firstGeneration = $first['lines'][0]['generationStableId'];
        self::assertSame(1, $first['lines'][0]['snapshot']['unavailableCount'], 'The absence added after the deadline is in the snapshot.');

        $bobIn = static fn (array $snapshot): array => array_values(array_filter($snapshot['members'], static fn (array $m): bool => $m['sourceUserStableId'] === $bobUserId))[0];
        $before = $this->snapshotOf($client, $s, $firstGeneration);
        self::assertCount(1, $bobIn($before)['availabilityPeriods']);
        self::assertSame($late['stableId'], $bobIn($before)['availabilityPeriods'][0]['sourceAvailabilityStableId']);

        // Afterwards bob edits the absence and adds another one.
        self::mockTime('2026-12-29 09:00:00 Europe/Brussels');
        $tz = new \DateTimeZone('Europe/Brussels');
        $this->api($client, 'PATCH', "/api/me/calendar/{$late['stableId']}", [
            'type' => 'UNAVAILABLE',
            'startsAt' => (new \DateTimeImmutable('2027-02-10 00:00:00', $tz))->format(\DATE_ATOM),
            'endsAt' => (new \DateTimeImmutable('2027-02-20 00:00:00', $tz))->format(\DATE_ATOM),
        ], $s['bob']);
        self::assertResponseIsSuccessful();
        $this->declareRange($client, $s['bob'], '2027-03-15', '2027-03-16');

        // The first snapshot is exactly what it was.
        $after = $this->snapshotOf($client, $s, $firstGeneration);
        self::assertSame($before['members'], $after['members']);
        self::assertSame($before['summary'], $after['summary']);
        self::assertSame($before['capturedAt'], $after['capturedAt']);

        // A new generation reflects the new state; the old one stays, untouched.
        $second = $this->launch($client, $s);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $second['lines'][0]['snapshot']['unavailableCount']);
        self::assertNotSame($firstGeneration, $second['lines'][0]['generationStableId']);
        self::assertSame($before['members'], $this->snapshotOf($client, $s, $firstGeneration)['members']);
        self::assertSame('COMPLETED', $this->api($client, 'GET', "/api/planning-generations/{$firstGeneration}", token: $s['admin'])['status']);
    }

    // --- refusals ----------------------------------------------------------------

    public function testATechnicalPrerequisiteBlocksAndCreatesNothing(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        // No rule set, no duty: the pipeline could not even snapshot.

        $preflight = $this->preflight($client, $s);
        self::assertFalse($preflight['canGenerate']);
        self::assertEqualsCanonicalizing(['NO_ACTIVE_RULE_SET', 'NO_DUTIES'], array_column($preflight['blockers'], 'code'));
        self::assertNotNull($preflight['blockers'][0]['lineStableId']);

        $result = $this->launch($client, $s);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_launchable', $result['error']);

        $detail = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator']);
        $generations = $this->api($client, 'GET', "/api/planning-periods/{$detail['lines'][0]['planningPeriodStableId']}/generations", token: $s['admin']);
        self::assertSame([], $generations, 'A refusal never leaves an orphan DRAFT generation.');
    }

    public function testAPublishedPeriodBlocksANewGeneration(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $this->launch($client, $s);
        self::assertResponseStatusCodeSame(201);

        $this->transitionPeriod($s['planningId'], PlanningPeriodStatus::VALIDATED);
        // While VALIDATED, generating again is allowed but warned about.
        self::assertContains('VALIDATION_WILL_BE_INVALIDATED', array_column($this->preflight($client, $s)['warnings'], 'code'));
        $this->transitionPeriod($s['planningId'], PlanningPeriodStatus::PUBLISHED);

        $preflight = $this->preflight($client, $s);
        self::assertFalse($preflight['canGenerate']);
        self::assertSame(['PERIOD_LOCKED'], array_column($preflight['blockers'], 'code'));
        $this->launch($client, $s);
        self::assertResponseStatusCodeSame(409);
    }

    public function testASecondLaunchWhileOneIsRunningIsRefused(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $planningId = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($s['planningId'])->getId();

        // Another session (another request, another admin) holds the planning's launch lock.
        $params = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->getParams();
        $other = DriverManager::getConnection($params);
        try {
            self::assertTrue((bool) $other->fetchOne('SELECT pg_try_advisory_lock(7351, ?)', [$planningId]));

            $refused = $this->launch($client, $s);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('generation_in_progress', $refused['error']);
        } finally {
            $other->fetchOne('SELECT pg_advisory_unlock(7351, ?)', [$planningId]);
            $other->close();
        }

        // Once it is released, the launch goes through — the lock was released by the refused call's owner only.
        $this->launch($client, $s);
        self::assertResponseStatusCodeSame(201);
        // …and is released again after a successful launch: a later launch is not stuck.
        $this->launch($client, $s);
        self::assertResponseStatusCodeSame(201);
    }
}
