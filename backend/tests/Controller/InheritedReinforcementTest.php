<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\InheritedReinforcementTestHelpers;
use App\Tests\LineFillTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D224 — a reinforcement line ("Fell/Ass") inherits the
 * blocks of its source line ("Première ligne"): a reinforcement only on a
 * triggered day, the source's instants, the triggered days of one source
 * block in one block, never a day added to complete a block. Real OR-Tools
 * solves, the pilot planning (2027-01-01 → 2027-05-01, 17 weeks).
 */
final class InheritedReinforcementTest extends WebTestCase
{
    use LineFillTestHelpers;
    use InheritedReinforcementTestHelpers;

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->inheritedScenario($this->client);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /** @return array<string, int> case → occurrences, over the whole calendar */
    private function cases(): array
    {
        $cases = ['three-day block' => 0, 'two-day block (Fri+Sun)' => 0, 'single Saturday' => 0, 'single Tuesday' => 0];
        $byBlock = [];
        foreach ($this->fellRows() as $row) {
            $byBlock[$row['block'] ?? 'd'.$row['id']][] = $row['weekday'];
        }
        foreach ($byBlock as $days) {
            sort($days);
            match ($days) {
                [5, 6, 7] => ++$cases['three-day block'],
                [5, 7] => ++$cases['two-day block (Fri+Sun)'],
                [6] => ++$cases['single Saturday'],
                [2] => ++$cases['single Tuesday'],
                default => self::fail('Unexpected unit: '.implode(',', $days)),
            };
        }

        return $cases;
    }

    public function testTheSourceAndItsReinforcementInOneRunFollowTheSourceBlocks(): void
    {
        $view = $this->configureFell($this->client, $this->s);
        self::assertSame('INHERITED', $view['structureSource']);
        self::assertSame($this->s['primaryLineId'], $view['inheritedFrom']['lineStableId']);
        self::assertSame([['name' => 'Week-end', 'weekdays' => ['FRIDAY', 'SATURDAY', 'SUNDAY']]], $view['targetStructure']['blocks']);

        $job = $this->fillNow($this->client, $this->s, [$this->s['primaryLineId'], $this->s['fellLineId']]);

        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        self::assertSame(0, $job['outcome']['modifiedExistingCount']);
        $this->assertInheritedCalendarIsCoherent();
        $cases = $this->cases();
        foreach ($cases as $case => $count) {
            self::assertGreaterThan(0, $count, "The case \"{$case}\" occurs over 17 weeks (".json_encode($cases).').');
        }
        // Never a Saturday reinforcement under bob, never a Friday or Sunday under alice.
        foreach ($this->fellRows() as $row) {
            if ('bob@example.com' === $row['sourceHolder']) {
                self::assertNotSame(6, $row['weekday']);
            }
            if ('alice@example.com' === $row['sourceHolder']) {
                self::assertSame(6, $row['weekday']);
            }
        }
    }

    /**
     * The Trauma Delta case: the Première ligne is generated and PUBLISHED,
     * Fell/Ass is configured only then (no longer refused) and generated
     * alone: its demand is the published holders', and not one row that
     * existed is changed — the 120 duties of the real case are protected
     * the same way. Opening the configuration and previewing write nothing.
     */
    public function testAReinforcementConfiguredAfterThePublicationOfItsSourceLeavesTheSourceIntact(): void
    {
        $this->fillNow($this->client, $this->s, [$this->s['primaryLineId']]);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", ['lines' => [$this->s['primaryLineId']]], $this->s['creator']);
        self::assertResponseIsSuccessful();

        $before = $this->protectedRows();
        $this->api($this->client, 'GET', "/api/planning-lines/{$this->s['fellLineId']}/demand-policy", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        $preview = $this->api($this->client, 'POST', "/api/planning-lines/{$this->s['fellLineId']}/demand-policy/preview", $this->fellPolicy($this->s), $this->s['creator']);
        self::assertResponseIsSuccessful();
        $this->assertNoExistingRowChanged($before);
        foreach (self::PROTECTED_TABLES as $table) {
            self::assertSame(0, $this->newRowCount($before, $table), "Opening and previewing write nothing ({$table}).");
        }

        $this->configureFell($this->client, $this->s);
        $fillPreview = $this->fillPreview($this->client, $this->s);
        $this->assertNoExistingRowChanged($before);
        self::assertSame(0, $this->newRowCount($before, 'duties'), 'The generation preview creates no duty.');
        $fell = array_column($fillPreview['lines'], null, 'lineName')['Fell/Ass'];
        self::assertSame('GENERATE', $fell['action']);

        $job = $this->fillNow($this->client, $this->s, [$this->s['fellLineId']]);

        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        $this->assertNoExistingRowChanged($before, 'The published Première ligne is never touched.');
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM duty_assignment_events e JOIN duties d ON d.id = e.duty_id JOIN planning_periods p ON p.id = d.planning_period_id JOIN planning_lines l ON l.id = p.planning_line_id WHERE l.type = 'PRIMARY' AND e.id > ?", [max(array_keys($before['duty_assignment_events']) ?: [0])]));
        $this->assertInheritedCalendarIsCoherent();
        // The preview announced exactly what was created.
        $announced = array_sum(array_map(static fn (array $p): int => $p['dutyCount'], $preview['periods']));
        self::assertSame($announced, \count($this->fellRows()));

        // Run again: nothing to do, nothing changes.
        $after = $this->protectedRows();
        $again = $this->fillNow($this->client, $this->s, [$this->s['fellLineId']]);
        self::assertSame([409, 'nothing_to_fill'], [$again['httpStatus'] ?? null, $again['error'] ?? null]);
        $this->assertNoExistingRowChanged($after);
        self::assertSame(0, $this->newRowCount($after, 'duties'));
    }

    /**
     * A manual reassignment of the source is read by the next computations:
     * a block of bob (Friday + Sunday reinforced) given to admin makes the
     * Saturday trigger. The next "Générer les postes non pourvus" creates
     * that Saturday in the existing reinforcement block — never assigned
     * automatically, never changing the Friday and Sunday — and the holder of
     * the block is offered first; choosing them keeps their two days as they
     * were. The other way round, a reinforcement that stops triggering keeps
     * its holder and is reported superfluous, never removed.
     */
    public function testAManualReassignmentOfTheSourceIsReadAndNothingExistingIsReplaced(): void
    {
        $this->configureFell($this->client, $this->s);
        $this->fillNow($this->client, $this->s, [$this->s['primaryLineId'], $this->s['fellLineId']]);
        $fell = $this->fellRows();
        $bobBlock = null;
        foreach ($fell as $row) {
            if ('bob@example.com' === $row['sourceHolder'] && null !== $row['block']) {
                $bobBlock = $row;
                break;
            }
        }
        self::assertNotNull($bobBlock, 'bob holds at least one source block.');
        $sourceDuty = (string) $this->db()->fetchOne('SELECT stable_id FROM duties WHERE id = ?', [$bobBlock['source']]);
        $reinforcementHolder = $bobBlock['holder'];
        $keptAssignments = array_column(array_filter($fell, static fn (array $r): bool => $r['block'] === $bobBlock['block']), 'assignmentId');
        self::assertCount(2, $keptAssignments);

        $this->reassignTo($this->client, $this->s, $sourceDuty, 'admin@example.com');
        self::assertResponseIsSuccessful();
        $before = $this->protectedRows();

        $job = $this->fillNow($this->client, $this->s, [$this->s['fellLineId']]);

        self::assertSame(0, $job['outcome']['modifiedExistingCount']);
        $this->assertNoExistingRowChanged($before, 'Completing the new day changes nothing that existed.');
        $block = array_values(array_filter($this->fellRows(), static fn (array $r): bool => $r['block'] === $bobBlock['block']));
        self::assertSame([5, 6, 7], array_column($block, 'weekday'), 'The Saturday joins the existing block.');
        $saturday = $block[1];
        self::assertNull($saturday['holder'], 'Never assigned automatically.');
        self::assertSame($keptAssignments, [$block[0]['assignmentId'], $block[2]['assignmentId']], 'Friday and Sunday keep their very rows.');

        $saturdayStableId = (string) $this->db()->fetchOne('SELECT stable_id FROM duties WHERE id = ?', [$saturday['id']]);
        $view = $this->candidatesFor($this->client, $this->s, $saturdayStableId);
        self::assertTrue($view['assignable']);
        self::assertTrue($view['candidates'][0]['holdsRestOfBlock'] ?? false, 'The holder of the rest of the block is offered first.');
        $first = $view['candidates'][0]['teamMemberStableId'];
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$saturdayStableId}/reassign", ['teamMemberStableId' => $first, 'expectedCurrentTeamMemberStableId' => null], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $block = array_values(array_filter($this->fellRows(), static fn (array $r): bool => $r['block'] === $bobBlock['block']));
        self::assertSame([$reinforcementHolder, $reinforcementHolder, $reinforcementHolder], array_column($block, 'holder'), 'Regularized: one holder.');
        self::assertSame($keptAssignments, [$block[0]['assignmentId'], $block[2]['assignmentId']], 'Friday and Sunday were never re-created.');

        // The other way round: the source block goes back to bob — the Saturday no longer triggers.
        $this->reassignTo($this->client, $this->s, $sourceDuty, 'bob@example.com');
        self::assertResponseIsSuccessful();
        $states = [];
        foreach ($this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/result", token: $this->s['creator'])['lines'] as $line) {
            foreach ($line['duties'] as $duty) {
                if (\in_array($duty['dutyStableId'], array_map(fn (array $r): string => (string) $this->db()->fetchOne('SELECT stable_id FROM duties WHERE id = ?', [$r['id']]), $block), true)) {
                    $states[$duty['date']] = $duty['demand']['state'] ?? null;
                }
            }
        }
        ksort($states);
        self::assertSame(['REQUIRED_ASSIGNED', 'NOT_REQUIRED_ASSIGNED', 'REQUIRED_ASSIGNED'], array_values($states), 'The Saturday is kept with its holder and reported superfluous — never removed.');
        self::assertSame([$reinforcementHolder, $reinforcementHolder, $reinforcementHolder], array_column(array_values(array_filter($this->fellRows(), static fn (array $r): bool => $r['block'] === $bobBlock['block'])), 'holder'));
    }

    public function testAnInheritingLineHasNoWeekStructureOfItsOwn(): void
    {
        $this->configureFell($this->client, $this->s);
        $response = $this->api($this->client, 'PUT', "/api/planning-lines/{$this->s['fellLineId']}/week-structure", ['blocks' => [], 'solo' => ['MAR'], 'soloFamily' => '', 'excluded' => ['LUN', 'MER', 'JEU', 'VEN', 'SAM', 'DIM']], $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['structure_inherited', $this->s['primaryLineId']], [$response['error'], $response['source']['lineStableId']]);
    }

    public function testWithoutAnyTriggerNothingIsAskedAndTheLineSaysWhy(): void
    {
        $view = $this->configureFell($this->client, $this->s, []);
        self::assertContains('NO_TRIGGER', array_column($view['warnings'], 'code'));
        $preview = array_column($this->fillPreview($this->client, $this->s)['lines'], null, 'lineName');
        self::assertSame(['EXCLUDED', 'nothing_demanded'], [$preview['Fell/Ass']['action'], $preview['Fell/Ass']['reason']]);
    }

    /** A surgeon without any trigger: no reinforcement at all wherever they hold the source. */
    public function testASurgeonWithoutTriggerNeverCreatesAReinforcement(): void
    {
        $triggers = ['admin@example.com' => ['FRIDAY', 'SATURDAY', 'SUNDAY', 'TUESDAY']];
        $this->configureFell($this->client, $this->s, $triggers);
        $this->fillNow($this->client, $this->s, [$this->s['primaryLineId'], $this->s['fellLineId']]);

        $this->assertInheritedCalendarIsCoherent($triggers);
        self::assertSame([], array_filter($this->fellRows(), static fn (array $r): bool => 'admin@example.com' !== $r['sourceHolder']));
    }
}
