<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DutyPattern;
use App\Entity\DutyType;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * No boundary between two periods ever cuts an atomic block of duties
 * (docs/planning-cycles.md §5.1, lot L3, option A, PeriodBoundaryGuard):
 * refused when a period is created, when the end of the last one moves and
 * when a line's weekly structure changes; reported by the generation
 * preflight for a boundary made before the rule existed.
 *
 * Fixture (pilotScenario): planning 2027-01-01 → 2027-05-01 — period 1 ends
 * on Saturday 1 May (exclusive), so its boundary falls inside a Friday →
 * Sunday weekend.
 */
final class PlanningCycleBoundaryTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private const WEEKEND = [
        'blocks' => [['name' => 'Week-end', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => '']],
        'solo' => ['LUN', 'MAR', 'MER', 'JEU'],
        'soloFamily' => '',
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

    /**
     * @return list<array<string, mixed>>
     */
    private function periods(): array
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'];
    }

    /**
     * @return array<string, mixed>
     */
    private function createPeriod(string $endsAt): array
    {
        $periods = $this->periods();

        return $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", [
            'endsAt' => $endsAt,
            'previousPeriodStableId' => $periods[\count($periods) - 1]['stableId'],
        ], $this->s['creator']);
    }

    /**
     * @return array<string, mixed>
     */
    private function changeEnd(int $number, string $endsAt): array
    {
        $period = $this->periods()[$number - 1];

        return $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period['stableId']}", [
            'endsAt' => $endsAt,
            'expectedEndsAt' => $period['endsAt'],
        ], $this->s['creator']);
    }

    /**
     * @param array<string, mixed> $structure
     *
     * @return array<string, mixed>
     */
    private function setStructure(array $structure): array
    {
        $line = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][0]['stableId'];

        return $this->api($this->client, 'PUT', "/api/planning-lines/{$line}/week-structure", $structure, $this->s['creator']);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function assertSplitsBlock(array $body): array
    {
        self::assertResponseStatusCodeSame(422, (string) $this->client->getResponse()->getContent());
        self::assertSame('period_boundary_splits_block', $body['error'] ?? null);

        return $body['details'];
    }

    public function testANewPeriodNeverStartsOrEndsInsideABlock(): void
    {
        $this->setStructure(self::WEEKEND);
        self::assertResponseIsSuccessful();

        // Period 1 ends on Saturday 1 May: the weekend of 30 April → 2 May would be cut.
        $details = $this->assertSplitsBlock($this->createPeriod('2027-08-02'));
        self::assertSame([[
            'lineStableId' => $details[0]['lineStableId'],
            'lineName' => 'Seniors',
            'blockName' => 'Week-end',
            'boundary' => '2027-05-01',
            'firstDay' => '2027-04-30',
            'lastDay' => '2027-05-02',
            'earlierDays' => ['2027-04-30'],
            'remainderDays' => ['2027-05-01', '2027-05-02'],
        ]], $details);
        self::assertCount(1, $this->periods(), 'nothing created');

        // Moving the end of period 1 to Monday 3 May makes room.
        $this->changeEnd(1, '2027-05-03');
        self::assertResponseIsSuccessful();
        // The end of the new period is the next boundary: Sunday 1 August would cut 30 July → 1 August.
        self::assertSame('2027-08-01', $this->assertSplitsBlock($this->createPeriod('2027-08-01'))[0]['boundary']);
        $this->createPeriod('2027-08-02');
        self::assertResponseStatusCodeSame(201);

        // Moving the end of the last period: never inside a block either.
        $this->assertSplitsBlock($this->changeEnd(2, '2027-08-08'));
        $this->changeEnd(2, '2027-08-09');
        self::assertResponseIsSuccessful();
    }

    public function testAWeeklyStructureNeverPutsABlockAcrossABoundary(): void
    {
        $this->changeEnd(1, '2027-05-06'); // a Thursday
        self::assertResponseIsSuccessful();
        $this->createPeriod('2027-08-05');
        self::assertResponseStatusCodeSame(201);

        $details = $this->assertSplitsBlock($this->setStructure([
            'blocks' => [['name' => 'Milieu', 'days' => ['MER', 'JEU'], 'family' => '']],
            'solo' => ['LUN', 'MAR', 'VEN', 'SAM', 'DIM'],
            'soloFamily' => '',
            'excluded' => [],
        ]));
        self::assertSame(['Milieu', '2027-05-06', '2027-05-05', '2027-05-06'], [$details[0]['blockName'], $details[0]['boundary'], $details[0]['firstDay'], $details[0]['lastDay']]);
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM duty_patterns WHERE active'), 'nothing written');

        // A block on either side of the Thursday boundary, and a single day on it (never a block), are fine.
        $this->setStructure([
            'blocks' => [['name' => 'Week-end', 'days' => ['SAM', 'DIM'], 'family' => '']],
            'solo' => ['LUN', 'MAR', 'MER', 'JEU', 'VEN'],
            'soloFamily' => '',
            'excluded' => [],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testTheEndsOfASinglePeriodPlanningAreNotBoundaries(): void
    {
        // As before periods existed: a weekend cut by the end of the only period is accepted (and not materialized).
        $this->setStructure(self::WEEKEND);
        self::assertResponseIsSuccessful();

        $preflight = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/generation-preflight", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertNotContains('BLOCK_CUT_BY_PERIOD_BOUNDARY', array_column($preflight['blockers'], 'code'));
    }

    public function testThePreflightReportsABoundaryThatCutsABlockMadeBeforeTheRule(): void
    {
        $this->createPeriod('2027-08-02');
        self::assertResponseStatusCodeSame(201);
        // A weekend set up without the check (as a structure configured before lot L3 would be).
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $team = static::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningTeam();
        $type = new DutyType($team, 'GARDE', 'Garde');
        $em->persist($type);
        $weekend = new DutyPattern($team, 'BLK-legacy', 'Week-end', null, recurring: true);
        $em->persist($weekend);
        foreach ([4, 5, 6] as $offset) {
            $weekend->addComponent($offset, $type);
        }
        $em->flush();

        foreach ($this->periods() as $period) {
            $preflight = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/generation-preflight?period={$period['stableId']}", token: $this->s['creator']);
            self::assertResponseIsSuccessful();
            self::assertContains('BLOCK_CUT_BY_PERIOD_BOUNDARY', array_column($preflight['blockers'], 'code'), "period {$period['number']}");
            self::assertFalse($preflight['canGenerate']);
        }
        // The weekend astride the boundary is materialized in neither period: the hole the blocker prevents.
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM duties WHERE local_date BETWEEN '2027-04-30' AND '2027-05-02'"));
        self::assertGreaterThan(0, (int) static::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM duties WHERE local_date BETWEEN '2027-05-07' AND '2027-05-09'"));
    }
}
