<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Service\DutyMaterializationService;
use App\Service\PlanningRuleSetService;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot L4a (docs/decisions.md D194): whether a line takes part in a period
 * (LineApplicabilityService), the period status derived from the lines that
 * count, the publication preflight that leaves a line with nothing demanded
 * aside, and the calendar read by period — through the real endpoints.
 *
 * Fixture (pilotScenario): planning 2027-01-01 → 2027-05-01, primary line
 * "Seniors" (admin/alice/bob), its first collection open. "Juniors" is a
 * secondary line added by the test, its own team, carol/dave.
 */
final class LineApplicabilityTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

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

    // --- helpers ---------------------------------------------------------------------------------

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function periods(): array
    {
        $periods = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator']);
        self::assertResponseIsSuccessful();

        return $periods['periods'];
    }

    private function lineId(int $index): string
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][$index]['stableId'];
    }

    /**
     * The applicability of a line in a period, as the periods endpoint shows it.
     *
     * @return array<string, mixed>
     */
    private function applicabilityOf(int $lineIndex, int $periodIndex = 0): array
    {
        $lineId = $this->lineId($lineIndex);
        foreach ($this->periods()[$periodIndex]['lines'] as $line) {
            if ($line['lineStableId'] === $lineId) {
                return $line['applicability'];
            }
        }

        self::fail("line {$lineIndex} has no period {$periodIndex}");
    }

    private function addJuniors(): void
    {
        $this->addSecondaryLine($this->client, $this->s, ['carol@example.com', 'dave@example.com']);
    }

    /** A ponctual duty on a line — no weekly structure, no rule set unless asked. */
    private function ponctualDuty(int $lineIndex, string $day, bool $withRuleSet = false): void
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[$lineIndex];
        if ($withRuleSet) {
            $this->activateRuleSet($container->get(PlanningRuleSetService::class), $line->getPlanningTeam());
        }
        $type = $this->createDutyType($container->get(EntityManagerInterface::class), $line->getPlanningTeam(), 'PONCT');
        $this->createDuty($container->get(DutyMaterializationService::class), $line->requireSingleCyclePeriod(), $type, $day, (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d'));
    }

    private function setStructure(int $lineIndex, array $structure = self::STRUCTURE): void
    {
        $this->api($this->client, 'PUT', "/api/planning-lines/{$this->lineId($lineIndex)}/week-structure", $structure, $this->s['creator']);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    private function publicationPreflight(): array
    {
        $preflight = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-preflight", token: $this->s['creator']);
        self::assertResponseIsSuccessful();

        return $preflight;
    }

    private function periodStatusOf(int $lineIndex): string
    {
        return (string) $this->db()->fetchOne('SELECT pp.status FROM planning_periods pp JOIN planning_lines l ON l.id = pp.planning_line_id WHERE l.stable_id = ?', [$this->lineId($lineIndex)]);
    }

    // --- applicability ---------------------------------------------------------------------------

    public function testAnActiveLineWithNothingDemandedIsNeutralAndNeverDeactivated(): void
    {
        $this->addJuniors();

        $juniors = $this->applicabilityOf(1);
        self::assertSame('NO_DEMAND', $juniors['state']);
        self::assertSame(['NO_DUTY', 'NO_WEEKLY_STRUCTURE'], $juniors['reasons']);
        self::assertFalse($juniors['conditional']);
        self::assertTrue($this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][1]['active']);

        // No line counts at all: the period is never "published" — it is in preparation (here: collecting).
        $period = $this->periods()[0];
        self::assertSame(['counting' => 0, 'published' => 0, 'incomplete' => 0, 'withoutDemand' => 2], $period['lineCounts']);
        self::assertSame('collecting', $period['status']);
        $preflight = $this->publicationPreflight();
        self::assertFalse($preflight['publishable']);
        self::assertSame([], $preflight['lines']);
        self::assertCount(2, $preflight['neutralLines']);
    }

    public function testAPonctualDutyAloneIsADemand(): void
    {
        $this->addJuniors();
        $this->ponctualDuty(1, '2027-02-10');

        // No weekly structure, no rule set: a real demand that cannot be generated yet — never hidden as "no demand".
        $juniors = $this->applicabilityOf(1);
        self::assertSame('INCOMPLETE', $juniors['state']);
        self::assertSame(['DUTIES_MATERIALIZED', 'NO_ACTIVE_RULE_SET'], $juniors['reasons']);
        self::assertSame(1, $juniors['dutyCount']);
        self::assertSame(0, $juniors['recurringPatternCount']);
        self::assertSame(1, $this->periods()[0]['lineCounts']['incomplete']);
    }

    public function testAPonctualDutyWithItsPrerequisitesIsApplicable(): void
    {
        $this->addJuniors();
        $this->ponctualDuty(1, '2027-02-10', withRuleSet: true);

        self::assertSame(['state' => 'APPLICABLE', 'reasons' => ['DUTIES_MATERIALIZED'], 'dutyCount' => 1, 'recurringPatternCount' => 0, 'conditional' => false], $this->applicabilityOf(1));
    }

    public function testAConditionalLineWithoutStructureNorDutyDemandsNothing(): void
    {
        $this->addJuniors();
        $alice = (string) $this->db()->fetchOne("SELECT stable_id FROM users WHERE email = 'alice@example.com'");
        $this->api($this->client, 'PUT', "/api/planning-lines/{$this->lineId(1)}/demand-policy", [
            'schemaVersion' => 1,
            'mode' => 'CONDITIONAL_ON_SOURCE_ASSIGNMENT',
            'source' => ['lineStableId' => $this->lineId(0)],
            'triggers' => [['userStableId' => $alice, 'weekdays' => ['MONDAY'], 'increment' => 1]],
        ], $this->s['creator']);
        self::assertResponseIsSuccessful();

        $juniors = $this->applicabilityOf(1);
        self::assertSame('NO_DEMAND', $juniors['state']);
        self::assertTrue($juniors['conditional'], 'the policy is reported, it does not make a demand by itself');

        // Its first weekly structure makes it count, conditional.
        $this->setStructure(1);
        $juniors = $this->applicabilityOf(1);
        self::assertSame('INCOMPLETE', $juniors['state']);
        self::assertSame(['WEEKLY_STRUCTURE', 'CONDITIONAL_POLICY', 'NO_ACTIVE_RULE_SET'], $juniors['reasons']);
    }

    public function testAnIncompleteLineBlocksTheFullPublicationOfThePeriod(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->generate($this->client, $this->s);
        $this->addJuniors();
        $this->setStructure(1);

        self::assertSame('INCOMPLETE', $this->applicabilityOf(1)['state']);
        self::assertSame('APPLICABLE', $this->applicabilityOf(0)['state']);
        $preflight = $this->publicationPreflight();
        self::assertFalse($preflight['publishable']);
        self::assertSame([$this->lineId(0), $this->lineId(1)], array_column($preflight['lines'], 'lineStableId'));
        self::assertSame([], $preflight['neutralLines']);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('DRAFT', $this->periodStatusOf(1));
    }

    public function testADemandAppearingOnANeutralLineAfterPublicationMakesThePeriodPartiallyPublished(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->generate($this->client, $this->s);
        $this->addJuniors();
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame('published', $this->periods()[0]['status']);

        $this->ponctualDuty(1, '2027-02-10', withRuleSet: true);
        $period = $this->periods()[0];
        self::assertSame('partially_published', $period['status']);
        self::assertSame(['counting' => 2, 'published' => 1, 'incomplete' => 0, 'withoutDemand' => 0], $period['lineCounts']);
        // A ponctual duty is never masked: the line counts, and has to be generated before it can be published.
        // Since lot L4c (D196) it no longer blocks republishing the published line: each line has its own verdict.
        $preflight = $this->publicationPreflight();
        self::assertTrue($preflight['republishable']);
        self::assertSame([], $preflight['neutralLines']);
        $juniors = $preflight['lines'][1];
        self::assertFalse($juniors['published']);
        self::assertFalse($juniors['firstPublishable']);
        self::assertSame(['NOT_GENERATED'], $juniors['blockers']);
    }

    // --- the real case: Trauma Delta -------------------------------------------------------------

    /**
     * Trauma Delta as it is in production (verified on a disposable restore, D192): period 1 ends on Saturday
     * 30 January (exclusive end Sunday 31), its primary line has a Friday → Sunday weekly structure, it is
     * published; its secondary line is active, DRAFT, with no structure and no duty, and stays in the planning.
     */
    public function testTraumaDelta(): void
    {
        $period = $this->periods()[0];
        // The fixture's collection covers the whole planning, as Trauma Delta's covered its own period.
        $this->db()->executeStatement("UPDATE availability_collections SET ends_at = '2027-01-31' WHERE stable_id = ?", [$this->s['collectionId']]);
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period['stableId']}", ['endsAt' => '2027-01-31', 'expectedEndsAt' => $period['endsAt']], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $this->setStructure(0);
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningTeam());
        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        $this->addJuniors();

        // The secondary line is neutral: listed apart, never a blocker — the first publication goes through.
        $preflight = $this->publicationPreflight();
        self::assertTrue($preflight['publishable'], json_encode($preflight, \JSON_THROW_ON_ERROR));
        self::assertSame([$this->lineId(0)], array_column($preflight['lines'], 'lineStableId'));
        self::assertSame([[$this->lineId(1), 'DRAFT', 'NO_DEMAND']], array_map(static fn (array $l): array => [$l['lineStableId'], $l['periodStatus'], $l['applicability']['state']], $preflight['neutralLines']));
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame('PUBLISHED', $this->periodStatusOf(0));
        self::assertSame('DRAFT', $this->periodStatusOf(1), 'a neutral line is never pushed to PUBLISHED');

        $p1 = $this->periods()[0];
        self::assertSame('published', $p1['status']);
        self::assertSame(['counting' => 1, 'published' => 1, 'incomplete' => 0, 'withoutDemand' => 1], $p1['lineCounts']);

        // A change on the primary line, then its republication — the secondary line untouched.
        $friday = $this->dutyOn($this->s['planningId'], '2027-01-22');
        $this->unassignDuty($this->client, $this->s, $friday);
        self::assertResponseIsSuccessful();
        $preflight = $this->publicationPreflight();
        self::assertTrue($preflight['republishable'], json_encode($preflight, \JSON_THROW_ON_ERROR));
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/republish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame('DRAFT', $this->periodStatusOf(1));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_lines WHERE stable_id = ? AND active', [$this->lineId(1)]));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_publication_entries e JOIN duties d ON d.id = e.duty_id JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_lines l ON l.id = pp.planning_line_id WHERE l.stable_id = ?', [$this->lineId(1)]));

        // Period 2, from Sunday 31 January: the cut weekend continues as a remainder block (D192), once confirmed.
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", [
            'endsAt' => '2027-04-30', 'previousPeriodStableId' => $p1['stableId'], 'acceptHistoricalBlockCuts' => true,
        ], $this->s['creator']);
        self::assertResponseStatusCodeSame(201);
        $p2 = $this->periods()[1];
        // Its duties are materialized on demand, by the generation preflight of the period (D136, D192).
        $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/generation-preflight?period={$p2['stableId']}", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(['2027-01-31'], $this->db()->fetchFirstColumn(<<<'SQL'
            SELECT d.local_date::text FROM duties d
            JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_cycles c ON c.id = pp.planning_cycle_id
            JOIN duty_group_instances gi ON gi.id = d.group_instance_id
            WHERE c.number = 2 AND d.local_date < '2027-02-05' ORDER BY d.local_date
        SQL));
        self::assertSame('APPLICABLE', $this->applicabilityOf(0, 1)['state']);
        self::assertSame('NO_DEMAND', $this->applicabilityOf(1, 1)['state']);
        self::assertSame('published', $this->periods()[0]['status'], 'period 1 keeps its status');
        self::assertNotSame('published', $p2['status']);

        // Reading by period: never the two merged.
        $default = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/result", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $default['period']['number'], 'today (2026) is before both: the default is the next period');
        self::assertSame('2027-01-30', $default['period']['lastDay']);
        $days1 = array_column($default['lines'][0]['duties'], 'date');
        self::assertNotEmpty($days1);
        self::assertLessThanOrEqual('2027-01-30', max($days1));
        self::assertNotContains('2027-01-29', $days1, 'the cut weekend was never materialized in period 1');
        self::assertSame('NO_DEMAND', $default['lines'][1]['applicability']['state']);

        $second = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/result?period={$p2['stableId']}", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $second['period']['number']);
        // Not generated yet in period 2: no duty shown (as before periods) — and never period 1's.
        self::assertNull($second['lines'][0]['generationStableId']);
        self::assertSame([], $second['lines'][0]['duties']);
        self::assertSame('APPLICABLE', $second['lines'][0]['applicability']['state']);
        self::assertSame((int) $this->db()->fetchOne('SELECT COUNT(*) FROM duties d JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_cycles c ON c.id = pp.planning_cycle_id JOIN planning_lines l ON l.id = pp.planning_line_id WHERE c.number = 2 AND l.stable_id = ?', [$this->lineId(0)]), $second['lines'][0]['applicability']['dutyCount']);

        $assignments = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/assignments?period={$p2['stableId']}", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame([], $assignments['assignments'], 'period 1 assignments never shown in period 2');
        self::assertNotEmpty($this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/assignments", token: $this->s['creator'])['assignments']);
    }
}
