<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\RestPolicyOptions;
use App\Entity\UserAvailabilityType;
use App\Exception\PlanningGenerationInProgressException;
use App\Fairness\OptimizationProblem;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\UserRepository;
use App\Service\PlanningGenerationLauncher;
use App\Service\PlanningLineFillService;
use App\Service\PreflightIssueCode;
use App\Service\UserAvailabilityService;
use App\Tests\FaultInjectingPlanningSolver;
use App\Tests\LineFillTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D222 — the safety of "Générer les postes non pourvus" /
 * "Générer cette ligne": published lines, failures, impossibilities,
 * changes during the computation, concurrency, and the cross-line rule of a
 * first generation. Real OR-Tools solves; FaultInjectingPlanningSolver only
 * where a failure or a concurrent change must be provoked.
 */
final class PlanningLineFillSafetyTest extends WebTestCase
{
    use LineFillTestHelpers;

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

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

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /** @param list<array{0: string, 1: string}> $nights */
    private function addJuniors(array $nights, array $emails = ['junior1@example.com', 'junior2@example.com'], array $existing = []): string
    {
        $teamId = $this->addSecondaryLine($this->client, $this->s, $emails);
        foreach ($existing as $email) {
            $this->addMemberAs($this->client, $this->s['creator'], $this->s['planningId'], $teamId, $email, 'MEMBER');
        }
        $this->prepareLine($this->s['planningId'], $nights, lineIndex: 1);

        return $this->lineIds($this->client, $this->s)[1];
    }

    private function unavailable(string $email, string $fromUtc, string $toUtc): void
    {
        static::getContainer()->get(UserAvailabilityService::class)->create(
            static::getContainer()->get(UserRepository::class)->findOneByEmail($email),
            UserAvailabilityType::UNAVAILABLE,
            new \DateTimeImmutable($fromUtc.' UTC'),
            new \DateTimeImmutable($toUtc.' UTC'),
        );
    }

    private function lineEntity(int $index): \App\Entity\PlanningLine
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);

        return $container->get(PlanningLineRepository::class)->findByPlanning($planning)[$index];
    }

    /**
     * A published line with a hole: never completed unless explicitly
     * selected; completing it is an unpublished change — never a
     * republication, never an email, the stored PDF untouched. And it never
     * prevents a draft line from being generated.
     */
    public function testAPublishedLineIsOnlyCompletedWhenSelectedAndNothingIsRepublished(): void
    {
        // A line added once its period is published only takes part from the next period: the draft line exists
        // before the publication, and only the Seniors are generated ("Générer cette ligne") and published.
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 4));
        $juniors = $this->addJuniors([['2027-01-04 21:00', '2027-01-05 07:00'], ['2027-01-05 21:00', '2027-01-06 07:00']]);
        $seniors = $this->lineIds($this->client, $this->s)[0];
        self::assertSame('done', $this->fillLines($this->fillNow($this->client, $this->s, [$seniors]))['Seniors']['status']);
        self::assertSame([], array_filter($this->visibleLine($this->client, $this->s, 1)), 'The Juniors were not generated.');
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", ['lines' => [$seniors]], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_publication_documents'));
        self::assertSame('PUBLISHED', $this->lineEntity(0)->requireSingleCyclePeriod()->getStatus()->value);
        $this->unassignDuty($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-01-05'));
        self::assertResponseIsSuccessful();
        $before = $this->protectedRows();

        $preview = array_column($this->fillPreview($this->client, $this->s)['lines'], null, 'lineName');
        self::assertSame(['COMPLETE', 'published', true, false], [$preview['Seniors']['action'], $preview['Seniors']['reason'], $preview['Seniors']['selectable'], $preview['Seniors']['selectedByDefault']]);
        self::assertSame(['GENERATE', true], [$preview['Juniors']['action'], $preview['Juniors']['selectedByDefault']]);

        // The default selection: the draft line only.
        $job = $this->fillNow($this->client, $this->s, [$juniors]);
        $lines = $this->fillLines($job);
        self::assertSame('not_selected', $lines['Seniors']['status']);
        self::assertSame(2, $lines['Juniors']['addedCount']);
        self::assertNull($this->currentCalendar($this->s['planningId'])['0|2027-01-05|ONCALL'], 'The published line was not touched.');
        $this->assertNoExistingRowChanged($before);

        // Explicitly selected: the hole is completed, journaled as a change made after publication.
        $before = $this->protectedRows();
        $job = $this->fillNow($this->client, $this->s, [$preview['Seniors']['lineStableId']]);
        self::assertSame(['done', 3, 0, 1], [$this->fillLines($job)['Seniors']['status'], $this->fillLines($job)['Seniors']['keptCount'], $this->fillLines($job)['Seniors']['modifiedCount'], $this->fillLines($job)['Seniors']['addedCount']]);
        $this->assertNoExistingRowChanged($before);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM duty_assignment_events WHERE id > ? AND was_published', [max(array_keys($before['duty_assignment_events']))]));
        foreach (['planning_publications', 'planning_publication_documents', 'planning_publication_notifications', 'planning_publication_deliveries'] as $table) {
            self::assertSame(0, $this->newRowCount($before, $table), "No republication: nothing new in {$table}.");
        }
        $state = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-state", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertNotEmpty(array_filter(array_map('json_encode', $state)), 'The publication state still answers.');
    }

    /**
     * The solver fails on the new line: that line writes nothing; the other
     * line, completed in the same run, keeps what it got; nothing existing
     * changes; the job says it is incomplete.
     */
    public function testASolverFailureOnOneLineWritesNothingForItAndSparesTheOthers(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 4));
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        $this->unassignDuty($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-01-06'));
        $juniors = $this->addJuniors([['2027-01-04 21:00', '2027-01-05 07:00'], ['2027-01-05 21:00', '2027-01-06 07:00']]);
        $juniorsPeriod = (int) $this->lineEntity(1)->requireSingleCyclePeriod()->getId();
        FaultInjectingPlanningSolver::$errorWhen = static fn (OptimizationProblem $p): bool => [] !== $p->getRequiredDutyUnits()
            && $p->getRequiredDutyUnits()[0]->getDuties()[0]->getPlanningPeriod()->getId() === $juniorsPeriod;
        $before = $this->protectedRows();

        $job = $this->fillNow($this->client, $this->s, $this->lineIds($this->client, $this->s));

        self::assertSame('FAILED', $job['status']);
        self::assertSame('fill_incomplete', $job['failureCode']);
        $lines = $this->fillLines($job);
        self::assertSame(['done', 1], [$lines['Seniors']['status'], $lines['Seniors']['addedCount']]);
        self::assertSame(['failed', 0], [$lines['Juniors']['status'], $lines['Juniors']['addedCount']]);
        self::assertSame(0, $job['outcome']['modifiedExistingCount']);
        self::assertSame([], array_filter($this->visibleLine($this->client, $this->s, 1)), 'Not one Juniors assignment written.');
        self::assertSame(0, (int) $this->db()->fetchOne(
            'SELECT COUNT(*) FROM duty_assignments a JOIN planning_generations g ON g.id = a.generation_id WHERE g.planning_period_id = ?',
            [$juniorsPeriod],
        ));
        $this->assertNoExistingRowChanged($before);
        self::assertNotNull($juniors);
    }

    /**
     * Nobody can take the new line's duties: a diagnosis (duties left
     * uncovered, counted), never a change to anything that exists.
     */
    public function testNobodyEligibleLeavesTheDutiesUncoveredAndChangesNothing(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 3));
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        $juniors = $this->addJuniors([['2027-01-04 21:00', '2027-01-05 07:00'], ['2027-01-05 21:00', '2027-01-06 07:00']]);
        $this->unavailable('junior1@example.com', '2027-01-03 00:00', '2027-01-08 00:00');
        $this->unavailable('junior2@example.com', '2027-01-03 00:00', '2027-01-08 00:00');
        $before = $this->protectedRows();

        $job = $this->fillNow($this->client, $this->s, [$juniors]);

        $line = $this->fillLines($job)['Juniors'];
        self::assertSame(['done', 0, 2, 0], [$line['status'], $line['addedCount'], $line['remainingUncoveredCount'], $line['modifiedCount']]);
        self::assertSame('INCOMPLETE', $job['outcome']['coverage']);
        $this->assertNoExistingRowChanged($before);
    }

    /**
     * Somebody declares an unavailability while the hole is being computed:
     * the result is checked again under the calendar lock before writing and
     * refused — nothing written, the reason said.
     */
    public function testAnUnavailabilityDeclaredDuringTheComputationIsCaughtBeforeWriting(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 3));
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        $this->unassignDuty($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-01-05'));
        $seniors = $this->lineIds($this->client, $this->s)[0];
        FaultInjectingPlanningSolver::$duringSolve = function (): void {
            FaultInjectingPlanningSolver::$duringSolve = null;
            foreach (['admin@example.com', 'alice@example.com', 'bob@example.com'] as $email) {
                $this->unavailable($email, '2027-01-05 00:00', '2027-01-06 00:00');
            }
        };
        $before = $this->protectedRows();

        $job = $this->fillNow($this->client, $this->s, [$seniors]);

        self::assertSame('FAILED', $job['status']);
        $line = $this->fillLines($job)['Seniors'];
        self::assertSame(['calendar_changed', 0, 0], [$line['status'], $line['addedCount'], $line['modifiedCount']]);
        self::assertNull($this->currentCalendar($this->s['planningId'])['0|2027-01-05|ONCALL']);
        $this->assertNoExistingRowChanged($before);
        self::assertSame(0, $this->newRowCount($before, 'duty_assignments'));
    }

    /**
     * Two runs, two sessions, two managers: the engine lock of the planning
     * is held by another session — the run is refused before reading
     * anything, and nothing is written.
     */
    public function testARunIsRefusedWhileAnotherSessionHoldsThePlanningsEngineLock(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 2));
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $other = DriverManager::getConnection($this->db()->getParams());
        self::assertTrue((bool) $other->fetchOne('SELECT pg_try_advisory_lock(7351, ?)', [$planning->getId()]));
        $before = $this->protectedRows();

        try {
            static::getContainer()->get(PlanningLineFillService::class)->fill(
                $planning,
                $planning->getCreator(),
                $planning->getOnlyCycle(),
                [$this->lineIds($this->client, $this->s)[0]],
                RestPolicyOptions::none(),
            );
            self::fail('A second concurrent run must be refused.');
        } catch (PlanningGenerationInProgressException) {
        } finally {
            $other->fetchOne('SELECT pg_advisory_unlock(7351, ?)', [$planning->getId()]);
            $other->close();
        }
        $this->assertNoExistingRowChanged($before);
        self::assertSame(0, $this->newRowCount($before, 'duty_assignments'));
    }

    /**
     * The cross-line rule of a first generation (D161/D222): the Juniors are
     * generated first, alone — Alice holds the night of 5 January. The
     * Seniors, generated afterwards and FIRST in the resolution order, must
     * still see that night as a commitment (it overlaps the day, 19:00-20:00 —
     * a conflict, always HARD, no rest rule involved): Alice never gets
     * the day before it, the day stays uncovered, her night stays hers.
     * Without it, the Seniors' result would conflict and be thrown away.
     */
    public function testAFirstGenerationRespectsALineThatComesAfterItInTheResolutionOrder(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05 08:00', '2027-01-05 20:00']]);
        $juniors = $this->addJuniors([['2027-01-05 19:00', '2027-01-06 07:00']], ['junior1@example.com'], ['alice@example.com']);
        $this->unavailable('junior1@example.com', '2027-01-05 00:00', '2027-01-07 00:00');
        $seniors = $this->lineIds($this->client, $this->s)[0];

        $first = $this->fillNow($this->client, $this->s, [$juniors]);
        self::assertSame('done', $this->fillLines($first)['Juniors']['status']);
        self::assertSame('alice@example.com', $this->currentCalendar($this->s['planningId'])['1|2027-01-05|ONCALL']);

        $this->unavailable('admin@example.com', '2027-01-05 00:00', '2027-01-06 00:00');
        $this->unavailable('bob@example.com', '2027-01-05 00:00', '2027-01-06 00:00');
        $before = $this->protectedRows();

        $second = $this->fillNow($this->client, $this->s, [$seniors]);

        $line = $this->fillLines($second)['Seniors'];
        self::assertSame(['done', 0, 1], [$line['status'], $line['addedCount'], $line['remainingUncoveredCount']]);
        self::assertSame('not_selected', $this->fillLines($second)['Juniors']['status']);
        self::assertNull($this->currentCalendar($this->s['planningId'])['0|2027-01-05|ONCALL']);
        self::assertSame('alice@example.com', $this->currentCalendar($this->s['planningId'])['1|2027-01-05|ONCALL']);
        $this->assertNoExistingRowChanged($before);
    }

    /**
     * The launcher's own guard (D222): restricted to some lines, it only ever
     * gives a FIRST generation — a generated line is refused, so no path of
     * this feature can replace a calendar.
     */
    public function testTheLauncherRefusesToRegenerateALineInTheRestrictedMode(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 2));
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);

        $preflight = static::getContainer()->get(PlanningGenerationLauncher::class)->preflight($planning, $planning->getOnlyCycle(), null, [$this->lineEntity(0)]);

        self::assertFalse($preflight->canGenerate());
        self::assertContains(PreflightIssueCode::LINE_ALREADY_GENERATED, array_map(static fn ($issue) => $issue->code, $preflight->blockers));
    }
}
