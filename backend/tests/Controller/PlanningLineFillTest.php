<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Tests\LineFillTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Générer les postes non pourvus" / "Générer cette ligne"
 * (docs/decisions.md D222) — the acceptance criterion and the main paths,
 * real OR-Tools solves, real worker handler.
 *
 * Fixture (pilotScenario): Seniors = admin, alice, bob; planning
 * 2027-01-01 → 2027-05-01. "Juniors" is added AFTER the Seniors are
 * generated, exactly like a manager adding a second line.
 */
final class PlanningLineFillTest extends WebTestCase
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

    /** Juniors (junior1, junior2), with $count nights 21:00 → 07:00 from 4 January. */
    private function addJuniors(int $count): string
    {
        $this->addSecondaryLine($this->client, $this->s, ['junior1@example.com', 'junior2@example.com']);
        $nights = [];
        foreach (self::days('2027-01-04', $count) as [$from]) {
            $date = substr($from, 0, 10);
            $nights[] = ["{$date} 21:00", (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d').' 07:00'];
        }
        $this->prepareLine($this->s['planningId'], $nights, lineIndex: 1);

        return $this->lineIds($this->client, $this->s)[1];
    }

    private function seniorsGenerationId(): int
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0];

        return (int) $container->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($line->requireSingleCyclePeriod())?->getId();
    }

    /**
     * THE acceptance criterion: 30 duties already assigned (one of them
     * reassigned by hand afterwards), a new line of 30 duties is added and
     * completed — not one of the first 30 assignments is modified, lost or
     * made invisible, in the database, in the API and in the statistics.
     */
    public function testThirtyAssignedDutiesSurviveANewLineOfThirtyBeingGenerated(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 30));
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'])['status']);
        // A manual change after the generation: the CURRENT holder must be kept, never the snapshot's.
        $firstDuty = $this->dutyOn($this->s['planningId'], '2027-01-04');
        $holder = $this->currentCalendar($this->s['planningId'])['0|2027-01-04|ONCALL'];
        $other = 'alice@example.com' === $holder ? 'bob@example.com' : 'alice@example.com';
        $this->reassignTo($this->client, $this->s, $firstDuty, $other);
        self::assertResponseIsSuccessful();

        $juniors = $this->addJuniors(30);
        $seniorsVisible = $this->visibleLine($this->client, $this->s, 0);
        self::assertCount(30, array_filter($seniorsVisible));
        $seniorsGeneration = $this->seniorsGenerationId();
        $before = $this->protectedRows();

        $preview = array_column($this->fillPreview($this->client, $this->s)['lines'], null, 'lineName');
        self::assertSame('NOTHING', $preview['Seniors']['action']);
        self::assertSame(30, $preview['Seniors']['protectedAssignmentCount']);
        self::assertSame('GENERATE', $preview['Juniors']['action']);
        self::assertSame(30, $preview['Juniors']['requiredDutyCount']);

        $job = $this->fillNow($this->client, $this->s, $this->lineIds($this->client, $this->s));

        self::assertSame('SUCCEEDED', $job['status']);
        self::assertSame(0, $job['outcome']['modifiedExistingCount']);
        self::assertSame(30, $job['outcome']['keptExistingCount']);
        self::assertSame(30, $job['outcome']['addedCount']);
        $lines = $this->fillLines($job);
        self::assertSame(['status' => 'nothing_to_do', 'keptCount' => 30, 'modifiedCount' => 0, 'addedCount' => 0], array_intersect_key($lines['Seniors'], array_flip(['status', 'keptCount', 'modifiedCount', 'addedCount'])));
        self::assertSame(['status' => 'done', 'keptCount' => 0, 'modifiedCount' => 0, 'addedCount' => 30, 'remainingUncoveredCount' => 0], array_intersect_key($lines['Juniors'], array_flip(['status', 'keptCount', 'modifiedCount', 'addedCount', 'remainingUncoveredCount'])));

        // Database: every existing row byte-for-byte the same; the Seniors' generation is still the visible one.
        $this->assertNoExistingRowChanged($before);
        self::assertSame($seniorsGeneration, $this->seniorsGenerationId(), 'The Seniors line was never regenerated.');
        self::assertSame(0, $this->duplicateCurrentAssignments());
        // API, after a "page refresh": the same 30 assignments (same ids, same holders) and the 30 new ones.
        self::assertSame($seniorsVisible, $this->visibleLine($this->client, $this->s, 0));
        self::assertSame($other, $this->currentCalendar($this->s['planningId'])['0|2027-01-04|ONCALL']);
        self::assertCount(30, array_filter($this->visibleLine($this->client, $this->s, 1)));
        $result = array_column($this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/result", token: $this->s['creator'])['lines'], null, 'lineName');
        self::assertSame([30, 30, 0], [$result['Seniors']['requiredDutyCount'], $result['Seniors']['coveredRequiredDutyCount'], $result['Seniors']['uncoveredRequiredDutyCount']]);
        self::assertSame([30, 30, 0], [$result['Juniors']['requiredDutyCount'], $result['Juniors']['coveredRequiredDutyCount'], $result['Juniors']['uncoveredRequiredDutyCount']]);

        // Statistics: each person's count is exactly what the database holds, old and new together.
        $statistics = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/statistics", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        $counted = 0;
        foreach ($statistics['currentPeriod']['groups'] as $group) {
            foreach ($group['members'] as $member) {
                $counted += $member['total'];
            }
        }
        self::assertSame(60, $counted);
        self::assertSame($juniors, $this->lineIds($this->client, $this->s)[1]);
    }

    /**
     * "Générer cette ligne" on the new line only: the first line, generated
     * but still a draft, is neither regenerated nor even read for holes —
     * where "Générer le planning" would have replaced its calendar.
     */
    public function testGeneratingOneLineNeverRegeneratesTheDraftFirstLine(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 6));
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        $juniors = $this->addJuniors(6);
        $seniorsGeneration = $this->seniorsGenerationId();
        $generationsBefore = (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM planning_generations');
        $before = $this->protectedRows();

        $job = $this->fillNow($this->client, $this->s, [$juniors]);

        self::assertSame('SUCCEEDED', $job['status']);
        $lines = $this->fillLines($job);
        self::assertSame('not_selected', $lines['Seniors']['status']);
        self::assertSame(6, $lines['Seniors']['keptCount']);
        self::assertSame(6, $lines['Juniors']['addedCount']);
        $this->assertNoExistingRowChanged($before);
        self::assertSame($seniorsGeneration, $this->seniorsGenerationId());
        self::assertSame($generationsBefore + 1, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM planning_generations'), 'One generation: the Juniors\' first.');
    }

    /**
     * A partly assigned line (two holes, one of them an atomic two-day
     * block): only the holes are completed, the block by one person for both
     * days, every other assignment kept.
     */
    public function testOnlyTheHolesOfAPartlyAssignedLineAreCompleted(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 6), ['2027-01-16 08:00', '2027-01-17 08:00', '2027-01-18 08:00']);
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        $this->unassignDuty($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-01-05'));
        self::assertResponseIsSuccessful();
        $this->unassignDuty($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-01-16'));
        self::assertResponseIsSuccessful();
        $calendar = $this->currentCalendar($this->s['planningId']);
        self::assertNull($calendar['0|2027-01-05|ONCALL']);
        $before = $this->protectedRows();
        $kept = array_filter($this->visibleLine($this->client, $this->s, 0));

        $preview = $this->fillPreview($this->client, $this->s)['lines'][0];
        self::assertSame('COMPLETE', $preview['action']);
        self::assertTrue($preview['selectedByDefault']);
        self::assertSame(3, $preview['uncoveredRequiredDutyCount']);
        self::assertSame(5, $preview['protectedAssignmentCount']);

        $job = $this->fillNow($this->client, $this->s, [$preview['lineStableId']]);

        $line = $this->fillLines($job)['Seniors'];
        self::assertSame(['done', 5, 0, 3, 0], [$line['status'], $line['keptCount'], $line['modifiedCount'], $line['addedCount'], $line['remainingUncoveredCount']]);
        $this->assertNoExistingRowChanged($before);
        $after = $this->visibleLine($this->client, $this->s, 0);
        self::assertSame($kept, array_intersect_key($after, $kept), 'Every assignment held before is still visible, identical.');
        $calendar = $this->currentCalendar($this->s['planningId']);
        self::assertNotNull($calendar['0|2027-01-05|ONCALL']);
        $block = static::getContainer()->get(Connection::class)->fetchNumeric(<<<'SQL'
            SELECT COUNT(*), COUNT(DISTINCT a.team_member_id) FROM duty_assignments a JOIN duties d ON d.id = a.duty_id
            WHERE a.current AND d.local_date IN ('2027-01-16', '2027-01-17')
            SQL);
        self::assertSame([2, 1], array_map('intval', $block), 'The block is held by one person, both days.');
        // Every completed duty is journaled as an addition (previous = null), never as a replacement.
        self::assertSame(3, $this->newRowCount($before, 'duty_assignment_events'));
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM duty_assignment_events WHERE id > ? AND previous_assignment_id IS NOT NULL',
            [max(array_keys($before['duty_assignment_events']) ?: [0])],
        ));
    }

    /**
     * Nothing to do (everything assigned): refused before any job, nothing
     * written; asking twice after a run creates nothing either.
     */
    public function testAFullyAssignedPlanningIsLeftAloneAndARerunCreatesNothing(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 4));
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        $before = $this->protectedRows();
        $jobs = (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM planning_jobs');

        $refusal = $this->fillNow($this->client, $this->s, $this->lineIds($this->client, $this->s));

        self::assertSame(409, $refusal['httpStatus']);
        self::assertSame('nothing_to_fill', $refusal['error']);
        self::assertSame('NOTHING', $refusal['preview']['lines'][0]['action']);
        self::assertSame($jobs, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM planning_jobs'), 'No job queued.');
        $this->assertNoExistingRowChanged($before);
        self::assertSame(0, $this->newRowCount($before, 'duty_assignments'));
    }

    /**
     * Double click: the second request is refused while the first is queued;
     * the run creates each assignment once, and running again finds nothing.
     */
    public function testADoubleClickRunsOnceAndNeverDuplicates(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 4));
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        $juniors = $this->addJuniors(4);

        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/fill", ['lines' => [$juniors]], $this->s['creator']);
        self::assertResponseStatusCodeSame(202);
        $second = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/fill", ['lines' => [$juniors]], $this->s['admin']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('job_in_progress', $second['error']);
        self::assertSame(1, $this->runQueuedPlanningJobs());

        self::assertSame(0, $this->duplicateCurrentAssignments());
        self::assertCount(4, array_filter($this->visibleLine($this->client, $this->s, 1)));
        $before = $this->protectedRows();
        $again = $this->fillNow($this->client, $this->s, [$juniors]);
        self::assertSame('nothing_to_fill', $again['error']);
        $this->assertNoExistingRowChanged($before);
        self::assertSame(0, $this->newRowCount($before, 'duty_assignments'));
    }

    /** A plain member, or someone outside the planning, is refused by the server — preview and run. */
    public function testOnlyThePlanningsManagersMayPreviewOrRun(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 2));
        $lines = $this->lineIds($this->client, $this->s);
        $before = $this->protectedRows();

        foreach (['alice', 'outsider'] as $who) {
            $this->fillPreview($this->client, $this->s, $this->s[$who]);
            self::assertResponseStatusCodeSame(403);
            $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/fill", ['lines' => $lines], $this->s[$who]);
            self::assertResponseStatusCodeSame(403);
        }
        self::assertSame(0, $this->queuedPlanningJobMessageCount());
        $this->assertNoExistingRowChanged($before);

        // An unknown line, or none: a validation error, never a partial run.
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/fill", ['lines' => ['00000000-0000-0000-0000-000000000000']], $this->s['creator']);
        self::assertResponseStatusCodeSame(422);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/fill", ['lines' => []], $this->s['creator']);
        self::assertResponseStatusCodeSame(422);
    }
}
