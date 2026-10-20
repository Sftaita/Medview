<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\ConditionalLineTestHelpers;
use App\Tests\FaultInjectingPlanningSolver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot L4b (docs/decisions.md D195), conditional lines (D162-D165): a
 * reinforcement line generated next to its published source reads the
 * source's CURRENT calendar; a source whose reinforcement line is published
 * is never regenerated (SOURCE_OF_PUBLISHED_LINE). Real OR-Tools.
 *
 * Fixture (ConditionalLineTestHelpers::scenario): main line Seniors (admin,
 * alice, bob) on 5, 8, 9, 10 January 2027; "Renfort" (carol, dave) depending
 * on it — alice triggers every day, bob Friday to Sunday, admin never.
 */
final class PlanningGenerationScopeConditionalTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ConditionalLineTestHelpers;

    private const SCOPE = ['scope' => 'unlocked_lines'];

    /** A weekly structure with every day excluded: the reinforcement line demands nothing yet. */
    private const NOTHING = ['blocks' => [], 'solo' => [], 'soloFamily' => '', 'excluded' => ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM']];

    private const RENFORT = [
        'blocks' => [['name' => 'Week-end', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => '']],
        'solo' => ['MAR'],
        'soloFamily' => '',
        'excluded' => ['LUN', 'MER', 'JEU'],
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
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

    private function periodIdOf(string $lineStableId): int
    {
        return (int) $this->db()->fetchOne('SELECT pp.id FROM planning_periods pp JOIN planning_lines l ON l.id = pp.planning_line_id WHERE l.stable_id = ?', [$lineStableId]);
    }

    private function statusOf(string $lineStableId): string
    {
        return (string) $this->db()->fetchOne('SELECT status FROM planning_periods WHERE id = ?', [$this->periodIdOf($lineStableId)]);
    }

    private function fingerprint(string $lineStableId): string
    {
        return (string) $this->db()->fetchOne(<<<'SQL'
            SELECT md5(string_agg(x, '|' ORDER BY x)) FROM (
              SELECT 'p' || ROW(pp.*)::text AS x FROM planning_periods pp WHERE pp.id = :p
              UNION ALL SELECT 'd' || ROW(d.*)::text FROM duties d WHERE d.planning_period_id = :p
              UNION ALL SELECT 'g' || ROW(g.*)::text FROM planning_generations g WHERE g.planning_period_id = :p
              UNION ALL SELECT 'a' || ROW(a.*)::text FROM duty_assignments a JOIN planning_generations g ON g.id = a.generation_id WHERE g.planning_period_id = :p
            ) s
        SQL, ['p' => $this->periodIdOf($lineStableId)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function preflight(array $s, ?string $scope = null): array
    {
        $preflight = $this->api($this->client, 'GET', "/api/plannings/{$s['planningId']}/generation-preflight".(null !== $scope ? '?scope='.$scope : ''), token: $s['creator']);
        self::assertResponseIsSuccessful();

        return $preflight;
    }

    /** The current holder (email) of the main line's duty on $date. */
    private function primaryHolder(string $date): ?string
    {
        $email = $this->db()->fetchOne(<<<'SQL'
            SELECT u.email FROM duties d
            JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_lines l ON l.id = pp.planning_line_id
            JOIN duty_assignments a ON a.duty_id = d.id AND a.current
            JOIN planning_team_members tm ON tm.id = a.team_member_id JOIN users u ON u.id = tm.user_id
            WHERE l.position = (SELECT MIN(position) FROM planning_lines) AND d.local_date = ?
        SQL, [$date]);

        return false === $email ? null : (string) $email;
    }

    /**
     * The main line published while Renfort demands nothing (every day excluded), then Renfort gets its structure.
     *
     * @return array<string, mixed>
     */
    private function publishedSourceAndDraftRenfort(): array
    {
        $s = $this->scenario($this->client, renfortStructure: self::NOTHING);
        self::assertSame('NO_DEMAND', $this->preflight($s)['lines'][1]['applicability']['state']);
        $job = $this->generateNow($this->client, $s['planningId'], $s['creator']);
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        $this->api($this->client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame('DRAFT', $this->statusOf($s['renfortLineId']));

        return $s;
    }

    private function setRenfortStructure(array $s): void
    {
        $this->api($this->client, 'PUT', "/api/planning-lines/{$s['renfortLineId']}/week-structure", self::RENFORT, $s['creator']);
        self::assertResponseIsSuccessful();
    }

    // --- 7. a reinforcement line next to its published source -----------------------------------

    public function testAReinforcementLineIsGeneratedFromThePublishedSourceCurrentCalendar(): void
    {
        $s = $this->publishedSourceAndDraftRenfort();
        // The published calendar changes after publication: the Friday goes to someone else.
        $before = $this->primaryHolder('2027-01-08');
        $after = 'admin@example.com' === $before ? 'alice@example.com' : 'admin@example.com';
        $this->reassignTo($this->client, $s, $this->dutyOn($s['planningId'], '2027-01-08'), $after);
        self::assertResponseIsSuccessful();
        self::assertSame($after, $this->primaryHolder('2027-01-08'));
        $this->setRenfortStructure($s);
        $source = $this->fingerprint($s['primaryLineId']);

        $preflight = $this->preflight($s, 'unlocked_lines');
        self::assertTrue($preflight['canGenerate'], json_encode($preflight['blockers'], \JSON_THROW_ON_ERROR));
        self::assertSame(['KEEP', 'GENERATE'], array_column($preflight['lines'], 'decision'));
        self::assertSame('unlocked_lines', $this->preflight($s)['requiredScope']);

        $job = $this->generateNow($this->client, $s['planningId'], $s['creator'], self::SCOPE);

        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        self::assertSame(['Renfort'], array_column($job['outcome']['lines'], 'lineName'));
        self::assertSame($source, $this->fingerprint($s['primaryLineId']), 'the published source is never touched');
        // Its demand was decided from the source's published generation, as its holders are NOW.
        $snapshot = $this->snapshotOf($this->currentGeneration($s, 1));
        self::assertSame((string) $this->currentGeneration($s, 0)->getStableId(), (string) $snapshot->getDemandPolicy()->getSourceGenerationStableId());
        $friday = (string) static::getContainer()->get(\App\Repository\DutyRepository::class)->findOneBy(['stableId' => $this->dutyOn($s['planningId'], '2027-01-08')])?->getStableId();
        $decisions = array_values(array_filter($snapshot->getDemandDecisions()->toArray(), static fn ($d): bool => (string) $d->getSourceDutyStableId() === $friday));
        self::assertNotEmpty($decisions);
        foreach ($decisions as $decision) {
            self::assertSame($this->userId($after), (string) $decision->getSourceUserStableId(), 'the current holder, never the one first generated');
        }
    }

    // --- 8. a source whose reinforcement line is published -----------------------------------------

    public function testASourceWhoseReinforcementLineIsPublishedIsNeverRegenerated(): void
    {
        $s = $this->scenario($this->client);
        $job = $this->generateNow($this->client, $s['planningId'], $s['creator']);
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        $this->api($this->client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();
        // The state a per-line publication (lot L4c) will make possible: the reinforcement line published, its source
        // not. Set directly — no flow produces it yet.
        $this->db()->executeStatement("UPDATE planning_periods SET status = 'GENERATED' WHERE id = ?", [$this->periodIdOf($s['primaryLineId'])]);
        $renfort = $this->fingerprint($s['renfortLineId']);
        $jobs = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_jobs');

        $scoped = $this->preflight($s, 'unlocked_lines');
        self::assertFalse($scoped['canGenerate']);
        self::assertSame([['SOURCE_OF_PUBLISHED_LINE', $s['primaryLineId']]], array_map(static fn (array $b): array => [$b['code'], $b['lineStableId']], $scoped['blockers']));
        self::assertSame(['GENERATE', 'KEEP'], array_column($scoped['lines'], 'decision'));

        $unscoped = $this->preflight($s);
        self::assertEqualsCanonicalizing(['PERIOD_LOCKED', 'SOURCE_OF_PUBLISHED_LINE'], array_column($unscoped['blockers'], 'code'));

        $this->api($this->client, 'POST', "/api/plannings/{$s['planningId']}/generations", self::SCOPE, $s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($jobs, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_jobs'));
        self::assertSame($renfort, $this->fingerprint($s['renfortLineId']));
    }

    // --- 9. several lines depending on one source --------------------------------------------------

    /**
     * Seniors → Renfort (published) and Seniors → Nuit (a second reinforcement line, still to generate). Nuit is
     * generated from the published source; the source and the published reinforcement line are kept as they are, and
     * Dave — in both reinforcement lines — never holds two duties at once.
     */
    public function testASecondReinforcementLineIsGeneratedNextToThePublishedSourceAndFirstReinforcement(): void
    {
        $s = $this->scenario($this->client);
        $this->api($this->client, 'POST', "/api/plannings/{$s['planningId']}/lines", ['name' => 'Nuit'], $s['creator']);
        self::assertResponseStatusCodeSame(201);
        $nuit = $this->api($this->client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'][2];
        $this->userToken($this->client, 'eve@example.com');
        foreach (['dave@example.com', 'eve@example.com'] as $email) {
            $this->addMemberAs($this->client, $s['creator'], $s['planningId'], $nuit['team']['stableId'], $email, 'MEMBER');
        }
        $this->activateRuleSet(static::getContainer()->get(\App\Service\PlanningRuleSetService::class), $this->line($s['planningId'], 2)->getPlanningTeam());
        $this->api($this->client, 'PUT', "/api/planning-lines/{$nuit['stableId']}/demand-policy", [
            'schemaVersion' => 1,
            'mode' => 'CONDITIONAL_ON_SOURCE_ASSIGNMENT',
            'source' => ['lineStableId' => $s['primaryLineId']],
            'triggers' => [['userStableId' => $this->userId('alice@example.com'), 'weekdays' => self::ALL_DAYS, 'increment' => 1]],
        ], $s['creator']);
        self::assertResponseIsSuccessful();

        // Nuit demands nothing yet: Seniors and Renfort are generated and published, Nuit excluded.
        $job = $this->generateNow($this->client, $s['planningId'], $s['creator']);
        self::assertSame(['Seniors', 'Renfort'], array_column($job['outcome']['lines'], 'lineName'));
        self::assertSame([$nuit['stableId']], array_column($job['outcome']['excludedLines'], 'lineStableId'));
        $this->api($this->client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();
        $seniors = $this->fingerprint($s['primaryLineId']);
        $renfort = $this->fingerprint($s['renfortLineId']);

        $this->api($this->client, 'PUT', "/api/planning-lines/{$nuit['stableId']}/week-structure", self::RENFORT, $s['creator']);
        self::assertResponseIsSuccessful();
        $preflight = $this->preflight($s, 'unlocked_lines');
        self::assertSame(['KEEP', 'KEEP', 'GENERATE'], array_column($preflight['lines'], 'decision'));
        self::assertTrue($preflight['canGenerate'], json_encode($preflight['blockers'], \JSON_THROW_ON_ERROR));

        $job = $this->generateNow($this->client, $s['planningId'], $s['creator'], self::SCOPE);

        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        self::assertSame(['Nuit'], array_column($job['outcome']['lines'], 'lineName'));
        self::assertSame([$s['primaryLineId'], $s['renfortLineId']], array_column($job['outcome']['keptLines'], 'lineStableId'));
        self::assertSame($seniors, $this->fingerprint($s['primaryLineId']));
        self::assertSame($renfort, $this->fingerprint($s['renfortLineId']));
        self::assertSame('GENERATED', $this->statusOf($nuit['stableId']));
        self::assertSame(0, (int) $this->db()->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM duty_assignments a1
            JOIN duties d1 ON d1.id = a1.duty_id JOIN planning_team_members t1 ON t1.id = a1.team_member_id
            JOIN duty_assignments a2 ON a2.current AND a2.id <> a1.id
            JOIN duties d2 ON d2.id = a2.duty_id JOIN planning_team_members t2 ON t2.id = a2.team_member_id
            WHERE a1.current AND t1.user_id = t2.user_id AND d1.starts_at < d2.ends_at AND d2.starts_at < d1.ends_at
        SQL), 'nobody holds two duties at once');
    }

    // --- 11. a concurrent change of the source ---------------------------------------------------

    /**
     * While Renfort is solved, a duty of its published source changes holder — between two people who are not
     * members of Renfort, so no commitment of Renfort's people moved. Its demand was decided from a calendar that no
     * longer exists: the result is discarded (`source_calendar_changed`), nothing written, the source untouched.
     */
    public function testAReinforcementSolvedAgainstASourceCalendarThatChangedIsNeverWritten(): void
    {
        $s = $this->publishedSourceAndDraftRenfort();
        $this->setRenfortStructure($s);
        $holder = $this->primaryHolder('2027-01-08');
        $other = 'admin@example.com' === $holder ? 'bob@example.com' : 'admin@example.com';
        $db = $this->db();
        FaultInjectingPlanningSolver::$duringSolve = static function () use ($db, $other): void {
            $db->executeStatement(<<<'SQL'
                UPDATE duty_assignments SET team_member_id = (
                  SELECT tm.id FROM planning_team_members tm JOIN users u ON u.id = tm.user_id
                  WHERE u.email = ? AND tm.planning_team_id = (SELECT x.team_id FROM duties x WHERE x.local_date = '2027-01-08' AND x.coverage_source_id IS NULL)
                )
                WHERE current AND duty_id = (SELECT id FROM duties WHERE local_date = '2027-01-08' AND coverage_source_id IS NULL)
                SQL, [$other]);
        };

        $job = $this->generateNow($this->client, $s['planningId'], $s['creator'], self::SCOPE);

        self::assertSame('FAILED', $job['status']);
        self::assertSame('source_calendar_changed', $job['outcome']['lines'][0]['error']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM duty_assignments a JOIN planning_generations g ON g.id = a.generation_id WHERE g.planning_period_id = ?', [$this->periodIdOf($s['renfortLineId'])]));
        self::assertSame('DRAFT', $this->statusOf($s['renfortLineId']));
        self::assertSame('PUBLISHED', $this->statusOf($s['primaryLineId']));
    }
}
