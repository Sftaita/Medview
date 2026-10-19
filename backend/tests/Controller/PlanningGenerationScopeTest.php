<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Fairness\OptimizationProblem;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Service\DutyMaterializationService;
use App\Service\PlanningRuleSetService;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\FaultInjectingPlanningSolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot L4b (docs/decisions.md D195): a period holding published lines, lines
 * still to generate and lines with nothing demanded — `scope:
 * "unlocked_lines"` generates the second ones only, never touching the
 * others. Real OR-Tools generations (FaultInjectingPlanningSolver only when a
 * test injects a failure).
 *
 * Fixture (pilotScenario): planning 2027-01-01 → 2027-05-01, primary line
 * "Seniors" (admin/alice/bob), its first collection open. "Juniors" is a
 * secondary line added by the test (carol, plus alice when a test shares
 * her).
 */
final class PlanningGenerationScopeTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private const SCOPE = ['scope' => 'unlocked_lines'];

    private const STRUCTURE = [
        'blocks' => [['name' => 'Ven · Sam · Dim', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => 'Week End']],
        'solo' => ['LUN', 'MAR', 'MER', 'JEU'],
        'soloFamily' => 'Jours de semaine',
        'excluded' => [],
    ];

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

    // --- helpers ---------------------------------------------------------------------------------

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function lineId(int $index): string
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][$index]['stableId'];
    }

    /**
     * @return array<string, mixed>
     */
    private function preflight(?string $scope = null, ?string $period = null): array
    {
        $query = array_filter(['scope' => $scope, 'period' => $period]);
        $preflight = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/generation-preflight".([] !== $query ? '?'.http_build_query($query) : ''), token: $this->s['creator']);
        self::assertResponseIsSuccessful();

        return $preflight;
    }

    /**
     * @return array<string, string> line name → decision
     */
    private function decisions(array $preflight): array
    {
        return array_column($preflight['lines'], 'decision', 'name');
    }

    private function jobCount(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_jobs');
    }

    private function periodStatusOf(int $lineIndex, int $cycle = 1): string
    {
        return (string) $this->db()->fetchOne('SELECT pp.status FROM planning_periods pp JOIN planning_lines l ON l.id = pp.planning_line_id JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE l.stable_id = ? AND c.number = ?', [$this->lineId($lineIndex), $cycle]);
    }

    /** Everything a line holds in a period: its period, duties, groups, generations and every assignment row. */
    private function lineFingerprint(int $lineIndex, int $cycle = 1): string
    {
        return (string) $this->db()->fetchOne(<<<'SQL'
            SELECT md5(string_agg(x, '|' ORDER BY x)) FROM (
              SELECT 'p' || ROW(pp.*)::text AS x FROM planning_periods pp WHERE pp.id = :p
              UNION ALL SELECT 'd' || ROW(d.*)::text FROM duties d WHERE d.planning_period_id = :p
              UNION ALL SELECT 'i' || ROW(gi.*)::text FROM duty_group_instances gi WHERE gi.planning_period_id = :p
              UNION ALL SELECT 'g' || ROW(g.*)::text FROM planning_generations g WHERE g.planning_period_id = :p
              UNION ALL SELECT 'a' || ROW(a.*)::text FROM duty_assignments a JOIN planning_generations g ON g.id = a.generation_id WHERE g.planning_period_id = :p
            ) s
        SQL, ['p' => $this->periodId($lineIndex, $cycle)]);
    }

    private function periodId(int $lineIndex, int $cycle = 1): int
    {
        return (int) $this->db()->fetchOne('SELECT pp.id FROM planning_periods pp JOIN planning_lines l ON l.id = pp.planning_line_id JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE l.stable_id = ? AND c.number = ?', [$this->lineId($lineIndex), $cycle]);
    }

    private function generationCount(int $lineIndex): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_generations WHERE planning_period_id = ?', [$this->periodId($lineIndex)]);
    }

    private function publish(): void
    {
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<string, string|null> date → holder email, the current calendar of a line
     */
    private function holders(int $lineIndex): array
    {
        $rows = $this->db()->fetchAllAssociative(<<<'SQL'
            SELECT d.local_date::text AS day, u.email
            FROM duties d
            LEFT JOIN duty_assignments a ON a.duty_id = d.id AND a.current
            LEFT JOIN planning_team_members tm ON tm.id = a.team_member_id
            LEFT JOIN users u ON u.id = tm.user_id
            WHERE d.planning_period_id = ?
            ORDER BY d.local_date
        SQL, [$this->periodId($lineIndex)]);

        return array_column($rows, 'email', 'day');
    }

    /** Juniors: carol (and whoever else), its rule set, its own duties. */
    private function juniorsWithDuties(array $days, array $extraMembers = []): void
    {
        $team = $this->addSecondaryLine($this->client, $this->s, ['carol@example.com']);
        foreach ($extraMembers as $email) {
            $this->addMemberAs($this->client, $this->s['creator'], $this->s['planningId'], $team, $email, 'MEMBER');
        }
        if ([] !== $days) {
            $this->prepareLine($this->s['planningId'], $days, lineIndex: 1);
        }
    }

    /** Every membership of Juniors' team ended before the period (2027-01-01): nobody takes part in it any more. */
    private function juniorsMembershipsEndedBeforeThePeriod(): void
    {
        $this->db()->executeStatement(<<<'SQL'
            UPDATE planning_team_members SET membership_start = '2026-06-01', membership_end = '2026-12-01'
            WHERE planning_team_id = (SELECT l.planning_team_id FROM planning_lines l WHERE l.stable_id = ?)
        SQL, [$this->lineId(1)]);
    }

    /** Seniors generated and published while Juniors is empty (neutral), then Juniors gets a real demand. */
    private function publishedSeniorsAndDraftJuniors(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']]);
        $this->juniorsWithDuties([]);
        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        $this->publish();
        $this->prepareLine($this->s['planningId'], [['2027-01-06', '2027-01-07'], ['2027-01-13', '2027-01-14']], lineIndex: 1);
    }

    // --- 1. the historical behaviour ---------------------------------------------------------------

    public function testASinglePeriodPlanningWithoutLockedLinesGeneratesAsBefore(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->juniorsWithDuties([['2027-01-06', '2027-01-07']]);

        $preflight = $this->preflight();
        self::assertTrue($preflight['canGenerate']);
        self::assertSame(['Seniors' => 'GENERATE', 'Juniors' => 'GENERATE'], $this->decisions($preflight));
        self::assertNull($preflight['scope']);
        self::assertNull($preflight['requiredScope']);

        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        self::assertSame('SUCCEEDED', $job['status']);
        self::assertSame(['Seniors', 'Juniors'], array_column($job['outcome']['lines'], 'lineName'));
        self::assertNull($job['outcome']['scope']);
        self::assertSame([], $job['outcome']['keptLines']);
        self::assertSame([], $job['outcome']['excludedLines']);
        self::assertSame('GENERATED', $this->periodStatusOf(1));
    }

    public function testAnUnknownScopeIsRefused(): void
    {
        $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/generation-preflight?scope=everything", token: $this->s['creator']);
        self::assertResponseStatusCodeSame(422);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", ['scope' => 'everything'], $this->s['creator']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->jobCount());
    }

    // --- 2. lines without demand -------------------------------------------------------------------

    public function testALineWithoutDemandIsExcludedNeverBlockingNeverGeneratedNeverDeactivated(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->juniorsWithDuties([]);

        $preflight = $this->preflight();
        self::assertTrue($preflight['canGenerate'], json_encode($preflight['blockers'], \JSON_THROW_ON_ERROR));
        self::assertSame(['Seniors' => 'GENERATE', 'Juniors' => 'EXCLUDE'], $this->decisions($preflight));
        self::assertSame('NO_DEMAND', $preflight['lines'][1]['applicability']['state']);

        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        self::assertSame('SUCCEEDED', $job['status'], 'an excluded line never fails a launch');
        self::assertSame(['Seniors'], array_column($job['outcome']['lines'], 'lineName'));
        self::assertSame('COMPLETE', $job['outcome']['coverage']);
        self::assertSame([$this->lineId(1)], array_column($job['outcome']['excludedLines'], 'lineStableId'));
        self::assertSame('DRAFT', $this->periodStatusOf(1));
        self::assertSame(0, $this->generationCount(1), 'no artificial generation');
        self::assertTrue($this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][1]['active']);
    }

    /** Trauma Delta (D194): the primary published, the secondary active, DRAFT, empty — nothing to generate, no job. */
    public function testTraumaDeltaLeavesNothingToGenerateAndQueuesNoJob(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->juniorsWithDuties([]);
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        $this->publish();
        $seniors = $this->lineFingerprint(0);
        $jobs = $this->jobCount();

        $preflight = $this->preflight();
        self::assertSame(['PERIOD_LOCKED'], array_column($preflight['blockers'], 'code'), 'as before, without the scope');
        self::assertNull($preflight['requiredScope'], 'the scope would generate nothing either');
        self::assertSame(['Seniors' => 'KEEP', 'Juniors' => 'EXCLUDE'], $this->decisions($preflight));

        $scoped = $this->preflight('unlocked_lines');
        self::assertFalse($scoped['canGenerate']);
        self::assertSame(['NO_LINE_TO_GENERATE'], array_column($scoped['blockers'], 'code'));
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", self::SCOPE, $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($jobs, $this->jobCount(), 'no job for nothing');
        self::assertSame($seniors, $this->lineFingerprint(0));
        self::assertSame('DRAFT', $this->periodStatusOf(1));
    }

    // --- 3-5. published lines next to lines to generate ----------------------------------------

    public function testTheScopeIsRequiredExplicitlyNextToAPublishedLine(): void
    {
        $this->publishedSeniorsAndDraftJuniors();
        $jobs = $this->jobCount();

        $preflight = $this->preflight();
        self::assertFalse($preflight['canGenerate']);
        self::assertSame(['PERIOD_LOCKED'], array_column($preflight['blockers'], 'code'));
        self::assertSame('unlocked_lines', $preflight['requiredScope']);
        self::assertSame(['Seniors' => 'KEEP', 'Juniors' => 'GENERATE'], $this->decisions($preflight));

        $refused = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", [], $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_launchable', $refused['error']);
        self::assertSame('unlocked_lines', $refused['requiredScope']);
        self::assertSame($jobs, $this->jobCount());
        self::assertSame('DRAFT', $this->periodStatusOf(1));
    }

    public function testUnlockedLinesGeneratesTheDraftLineAndNeverTouchesThePublishedOne(): void
    {
        $this->publishedSeniorsAndDraftJuniors();
        $seniors = $this->lineFingerprint(0);
        $publications = (string) $this->db()->fetchOne("SELECT md5(string_agg(ROW(p.*)::text, '|' ORDER BY p.id)) FROM planning_publications p");

        $preflight = $this->preflight('unlocked_lines');
        self::assertTrue($preflight['canGenerate'], json_encode($preflight['blockers'], \JSON_THROW_ON_ERROR));
        self::assertSame('unlocked_lines', $preflight['scope']);
        self::assertSame(['Seniors' => 'KEEP', 'Juniors' => 'GENERATE'], $this->decisions($preflight));

        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], self::SCOPE);
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        self::assertSame('unlocked_lines', $job['outcome']['scope']);
        self::assertSame(['Juniors'], array_column($job['outcome']['lines'], 'lineName'));
        self::assertSame([$this->lineId(0)], array_column($job['outcome']['keptLines'], 'lineStableId'));
        self::assertSame('COMPLETE', $job['outcome']['coverage']);

        self::assertSame($seniors, $this->lineFingerprint(0), 'the published line: same duties, generations, assignments, status');
        self::assertSame($publications, (string) $this->db()->fetchOne("SELECT md5(string_agg(ROW(p.*)::text, '|' ORDER BY p.id)) FROM planning_publications p"));
        self::assertSame('GENERATED', $this->periodStatusOf(1));
        self::assertSame(['2027-01-06', '2027-01-13'], array_keys(array_filter($this->holders(1))));
    }

    /**
     * D161 priorities kept, D195: a published line LATER in the resolution order is a commitment of the line
     * generated before it. Alice holds Juniors' 12 January (published; Carol away); Seniors gets a demand that day
     * afterwards, only Alice could take it (Admin and Bob away) — she never does: the day stays uncovered, and the
     * result is written (not discarded by the final check).
     */
    public function testAPublishedLineLaterInTheOrderIsACommitmentOfTheLineGenerated(): void
    {
        $this->juniorsWithDuties([['2027-01-12', '2027-01-13']], ['alice@example.com']);
        $carol = $this->loginUser($this->client, 'carol@example.com', 'correct-horse-battery');
        $this->declareRange($this->client, $carol, '2027-01-12', '2027-01-13');
        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        self::assertSame(['Juniors'], array_column($job['outcome']['lines'], 'lineName'), 'Seniors had nothing to do');
        self::assertSame(['2027-01-12' => 'alice@example.com'], $this->holders(1));
        $this->publish();
        $juniors = $this->lineFingerprint(1);

        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']]);
        $this->declareRange($this->client, $this->s['admin'], '2027-01-12', '2027-01-13');
        $this->declareRange($this->client, $this->s['bob'], '2027-01-12', '2027-01-13');
        self::assertSame(['Seniors' => 'GENERATE', 'Juniors' => 'KEEP'], $this->decisions($this->preflight('unlocked_lines')));

        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], self::SCOPE);

        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        self::assertNull($job['outcome']['lines'][0]['error']);
        self::assertSame('INCOMPLETE', $job['outcome']['coverage']);
        $seniors = $this->holders(0);
        self::assertNull($seniors['2027-01-12'], 'never Alice: she holds Juniors that day');
        self::assertNotNull($seniors['2027-01-05']);
        self::assertSame($juniors, $this->lineFingerprint(1));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_snapshot_external_commitments c JOIN planning_snapshots s ON s.id = c.snapshot_id JOIN planning_generations g ON g.id = s.generation_id WHERE g.planning_period_id = ? AND c.source_line_stable_id = ?', [$this->periodId(0), $this->lineId(1)]), 'frozen in the snapshot of Seniors');
    }

    // --- 6. incomplete configuration -------------------------------------------------------------

    public function testAnIncompleteLineNextToAPublishedOneBlocksItsGenerationExplicitly(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->juniorsWithDuties([]);
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        $this->publish();
        // A ponctual duty, no rule set: a real demand, an incomplete configuration.
        $container = static::getContainer();
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']))[1];
        $this->createDuty($container->get(DutyMaterializationService::class), $line->requireSingleCyclePeriod(), $this->createDutyType($container->get(EntityManagerInterface::class), $line->getPlanningTeam()), '2027-01-20', '2027-01-21');
        $jobs = $this->jobCount();

        $preflight = $this->preflight('unlocked_lines');
        self::assertFalse($preflight['canGenerate']);
        self::assertSame([['NO_ACTIVE_RULE_SET', $this->lineId(1)]], array_map(static fn (array $b): array => [$b['code'], $b['lineStableId']], $preflight['blockers']));
        self::assertSame(['Seniors' => 'KEEP', 'Juniors' => 'GENERATE'], $this->decisions($preflight));
        self::assertSame(['DUTIES_MATERIALIZED', 'NO_ACTIVE_RULE_SET'], $preflight['lines'][1]['applicability']['reasons']);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", self::SCOPE, $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($jobs, $this->jobCount());
    }

    // --- 7. a line without members (D195, case B) and without candidates (case C) ---------------

    /** Case B: duties demanded, no membership intersecting the period — INCOMPLETE, a blocker, never a warning. */
    public function testALineWithDutiesAndNoMemberBlocksTheLaunchExplicitly(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->juniorsWithDuties([['2027-01-06', '2027-01-07']]);
        $this->juniorsMembershipsEndedBeforeThePeriod();

        $preflight = $this->preflight();

        self::assertFalse($preflight['canGenerate']);
        self::assertSame([['NO_MEMBER_IN_PERIOD', $this->lineId(1)]], array_map(static fn (array $b): array => [$b['code'], $b['lineStableId']], $preflight['blockers']));
        self::assertNotContains('LINE_WITHOUT_MEMBERS', array_column($preflight['warnings'], 'code'), 'no longer a mere warning');
        self::assertSame(['Seniors' => 'GENERATE', 'Juniors' => 'GENERATE'], $this->decisions($preflight));
        self::assertSame('INCOMPLETE', $preflight['lines'][1]['applicability']['state']);
        self::assertContains('NO_MEMBER_IN_PERIOD', $preflight['lines'][1]['applicability']['reasons']);

        $refused = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", [], $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_launchable', $refused['error']);
        self::assertSame(0, $this->jobCount());
        self::assertSame(0, $this->generationCount(0), 'a refusal never leaves a part of the lines generated (D129)');
        self::assertSame(0, $this->generationCount(1));
    }

    /** Case B next to a published line, with the scope: refused the same way, the published line untouched. */
    public function testALineWithoutMemberNextToAPublishedOneIsRefusedWithTheScope(): void
    {
        $this->publishedSeniorsAndDraftJuniors();
        $this->juniorsMembershipsEndedBeforeThePeriod();
        $seniors = $this->lineFingerprint(0);
        $jobs = $this->jobCount();

        $preflight = $this->preflight('unlocked_lines');

        self::assertSame(['NO_MEMBER_IN_PERIOD'], array_column($preflight['blockers'], 'code'));
        self::assertSame(['Seniors' => 'KEEP', 'Juniors' => 'GENERATE'], $this->decisions($preflight));
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", self::SCOPE, $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($jobs, $this->jobCount());
        self::assertSame($seniors, $this->lineFingerprint(0));
        self::assertSame('DRAFT', $this->periodStatusOf(1));
    }

    /**
     * Case B on a line generated before: its own applicability says GENERATED (its prerequisites held then), yet
     * nobody is a member over the period any more — generating it again is refused, never a calendar nobody holds.
     */
    public function testALineGeneratedBeforeWhoseMembersAllLeftIsRefusedAgain(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->juniorsWithDuties([['2027-01-06', '2027-01-07']]);
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'])['status']);
        $this->juniorsMembershipsEndedBeforeThePeriod();
        $juniors = $this->lineFingerprint(1);
        $jobs = $this->jobCount();

        $preflight = $this->preflight();

        self::assertSame([['NO_MEMBER_IN_PERIOD', $this->lineId(1)]], array_map(static fn (array $b): array => [$b['code'], $b['lineStableId']], $preflight['blockers']));
        self::assertContains('GENERATED', $preflight['lines'][1]['applicability']['reasons']);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", [], $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($jobs, $this->jobCount());
        self::assertSame($juniors, $this->lineFingerprint(1), 'its previous generation stays as it was');
    }

    /**
     * Case C: a member exists but cannot take a duty (away) — not an incomplete configuration: the line is
     * APPLICABLE and generated, the duty reported uncovered, the coverage never COMPLETE.
     */
    public function testAMemberWhoCannotTakeADutyIsAnUncoveredDutyNeverAConfigurationBlocker(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->juniorsWithDuties([['2027-01-06', '2027-01-07'], ['2027-01-13', '2027-01-14']]);
        $carol = $this->loginUser($this->client, 'carol@example.com', 'correct-horse-battery');
        $this->declareRange($this->client, $carol, '2027-01-13', '2027-01-14');

        $preflight = $this->preflight();
        self::assertTrue($preflight['canGenerate'], json_encode($preflight['blockers'], \JSON_THROW_ON_ERROR));
        self::assertSame('APPLICABLE', $preflight['lines'][1]['applicability']['state']);

        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);

        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        self::assertSame('INCOMPLETE', $job['outcome']['coverage'], 'never presented as a complete generation');
        $juniors = array_values(array_filter($job['outcome']['lines'], static fn (array $l): bool => 'Juniors' === $l['lineName']))[0];
        self::assertSame('INCOMPLETE', $juniors['coverageStatus']);
        self::assertSame(['2027-01-06' => 'carol@example.com', '2027-01-13' => null], $this->holders(1));
    }

    // --- 12. a partial failure -------------------------------------------------------------------

    public function testAFailedLineLeavesThePublishedOneAndTheCalendarAsTheyWere(): void
    {
        $this->publishedSeniorsAndDraftJuniors();
        $seniors = $this->lineFingerprint(0);
        $juniorsPeriod = $this->periodId(1);
        FaultInjectingPlanningSolver::$errorWhen = static fn (OptimizationProblem $p): bool => [] !== $p->getRequiredDutyUnits()
            && $p->getRequiredDutyUnits()[0]->getDuties()[0]->getPlanningPeriod()->getId() === $juniorsPeriod;

        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], self::SCOPE);

        self::assertSame('FAILED', $job['status']);
        self::assertSame('generation_failed', $job['failureCode']);
        self::assertSame('FAILED', $job['outcome']['lines'][0]['status']);
        self::assertSame([$this->lineId(0)], array_column($job['outcome']['keptLines'], 'lineStableId'));
        self::assertSame($seniors, $this->lineFingerprint(0));
        self::assertSame([], array_filter($this->holders(1)), 'nothing written for Juniors');
        self::assertSame('DRAFT', $this->periodStatusOf(1));
    }

    // --- 13. the historical block of D192, Trauma Delta's period 2 -------------------------------

    /**
     * Trauma Delta end to end: period 1 ends on Saturday 30 January, the primary's Friday → Sunday weekend is cut,
     * period 1 is published with its empty secondary. Period 2 (from Sunday 31) is generated — the primary with its
     * remainder block, the secondary excluded and still DRAFT — period 1 strictly unchanged.
     */
    public function testTraumaDeltaPeriodTwoIsGeneratedWithItsRemainderAndWithoutTheEmptySecondary(): void
    {
        $period = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'][0];
        $this->db()->executeStatement("UPDATE availability_collections SET ends_at = '2027-01-31' WHERE stable_id = ?", [$this->s['collectionId']]);
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period['stableId']}", ['endsAt' => '2027-01-31', 'expectedEndsAt' => $period['endsAt']], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $this->api($this->client, 'PUT', "/api/planning-lines/{$this->lineId(0)}/week-structure", self::STRUCTURE, $this->s['creator']);
        self::assertResponseIsSuccessful();
        $container = static::getContainer();
        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $container->get(PlanningLineRepository::class)->findByPlanning($container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']))[0]->getPlanningTeam());
        $this->juniorsWithDuties([]);
        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        self::assertSame([$this->lineId(1)], array_column($job['outcome']['excludedLines'], 'lineStableId'));
        $this->publish();
        $seniorsP1 = $this->lineFingerprint(0);
        $juniorsP1 = $this->lineFingerprint(1);

        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", [
            'endsAt' => '2027-04-30', 'previousPeriodStableId' => $period['stableId'], 'acceptHistoricalBlockCuts' => true,
        ], $this->s['creator']);
        self::assertResponseStatusCodeSame(201);
        $p2 = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'][1]['stableId'];

        $preflight = $this->preflight(period: $p2);
        self::assertTrue($preflight['canGenerate'], json_encode($preflight['blockers'], \JSON_THROW_ON_ERROR));
        self::assertSame(['Seniors' => 'GENERATE', 'Juniors' => 'EXCLUDE'], $this->decisions($preflight));
        self::assertNull($preflight['requiredScope'], 'nothing locked in period 2');
        self::assertContains('HISTORICAL_BLOCK_REMAINDER', array_column($preflight['warnings'], 'code'));

        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], ['period' => $p2]);
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        self::assertSame(['Seniors'], array_column($job['outcome']['lines'], 'lineName'));
        self::assertSame('COMPLETE', $job['outcome']['coverage']);
        self::assertSame('GENERATED', $this->periodStatusOf(0, 2));
        self::assertSame('DRAFT', $this->periodStatusOf(1, 2));
        self::assertNotNull($this->db()->fetchOne(<<<'SQL'
            SELECT u.email FROM duties d
            JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_cycles c ON c.id = pp.planning_cycle_id
            JOIN duty_assignments a ON a.duty_id = d.id AND a.current
            JOIN planning_team_members tm ON tm.id = a.team_member_id JOIN users u ON u.id = tm.user_id
            WHERE c.number = 2 AND d.local_date = '2027-01-31' AND d.group_instance_id IS NOT NULL
        SQL), 'the remainder block (D192) is held');
        self::assertSame($seniorsP1, $this->lineFingerprint(0));
        self::assertSame($juniorsP1, $this->lineFingerprint(1));
        self::assertSame('PUBLISHED', $this->periodStatusOf(0));
        self::assertSame('DRAFT', $this->periodStatusOf(1));
    }
}
