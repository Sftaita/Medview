<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\ConditionalLineTestHelpers;
use App\Tests\FaultInjectingPlanningSolver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Lot L4c (docs/decisions.md D196) with a conditional line: a period
 * partially published, a reinforcement line ("Renfort") published with or
 * after the line it reinforces — never before it, its demand being decided
 * by that line's holders. Real OR-Tools generations.
 */
final class PlanningPublicationConditionalByLineTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;
    use ConditionalLineTestHelpers;

    /** Dr B (alice) on Tuesday: its reinforcement is required; Dr A (admin) on the weekend: not required. */
    private const HOLDERS = ['2027-01-05' => 'alice@example.com', '2027-01-08' => 'admin@example.com', '2027-01-09' => 'admin@example.com', '2027-01-10' => 'admin@example.com'];

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        FaultInjectingPlanningSolver::reset();
    }

    /**
     * @return array<string, mixed>
     */
    private function generated(KernelBrowser $client): array
    {
        $s = $this->scenario($client);
        $declared = $this->forceHolders($client, $s, self::HOLDERS);
        self::assertSame('SUCCEEDED', $this->launch($client, $s)['status']);
        $this->liftUnavailabilities($client, $declared);

        return $s;
    }

    /**
     * @return list<array{0: string, 1: string}> [line name, kind] recorded by the latest publication
     */
    private function latestLines(): array
    {
        return array_map(static fn (array $row): array => [$row['line_name'], $row['kind']], static::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT l.line_name, l.kind FROM planning_publication_lines l WHERE l.publication_id = (SELECT MAX(id) FROM planning_publications) ORDER BY l.id',
        ));
    }

    private function periodStatus(string $lineStableId): string
    {
        return (string) static::getContainer()->get(Connection::class)->fetchOne('SELECT pp.status FROM planning_periods pp JOIN planning_lines l ON l.id = pp.planning_line_id WHERE l.stable_id = ?', [$lineStableId]);
    }

    public function testAReinforcementLineIsNeverPublishedBeforeTheLineItReinforces(): void
    {
        $client = static::createClient();
        $s = $this->generated($client);
        $preflight = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/publication-preflight", token: $s['creator']);
        self::assertSame($s['primaryLineId'], $preflight['lines'][1]['sourceLineStableId']);

        $refused = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", ['lines' => [$s['renfortLineId']]], $s['creator']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('source_line_not_published', $refused['error']);
        self::assertSame([['lineStableId' => $s['renfortLineId'], 'lineName' => 'Renfort', 'sourceLineStableId' => $s['primaryLineId'], 'sourceLineName' => 'Seniors']], $refused['details']);
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM planning_publications'));
        self::assertSame('GENERATED', $this->periodStatus($s['renfortLineId']));
    }

    public function testTheSourceAloneThenTheReinforcementLater(): void
    {
        $client = static::createClient();
        $s = $this->generated($client);

        $first = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", ['lines' => [$s['primaryLineId']]], $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertFalse($first['publication']['cycleComplete']);
        self::assertSame([['Seniors', 'FIRST_FOR_LINE']], $this->latestLines());
        self::assertSame('GENERATED', $this->periodStatus($s['renfortLineId']));
        $period = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/periods", token: $s['creator'])['periods'][0];
        self::assertSame('partially_published', $period['status']);

        $later = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", ['lines' => [$s['renfortLineId']]], $s['creator']);

        self::assertResponseIsSuccessful();
        self::assertSame('UPDATE', $later['publication']['kind']);
        self::assertTrue($later['publication']['cycleComplete']);
        self::assertSame([['Renfort', 'FIRST_FOR_LINE']], $this->latestLines());
        self::assertSame('published', $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/periods", token: $s['creator'])['periods'][0]['status']);
    }

    public function testTheSourceAndItsReinforcementTogether(): void
    {
        $client = static::createClient();
        $s = $this->generated($client);

        $outcome = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", ['lines' => [$s['renfortLineId'], $s['primaryLineId']]], $s['creator']);

        self::assertResponseIsSuccessful();
        self::assertSame('FIRST', $outcome['publication']['kind']);
        self::assertTrue($outcome['publication']['cycleComplete']);
        self::assertSame([['Seniors', 'FIRST_FOR_LINE'], ['Renfort', 'FIRST_FOR_LINE']], $this->latestLines());
    }
}
