<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Tests\PlanningPilotTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET/PUT .../week-structure (docs/decisions.md D136).
 */
final class WeekStructureControllerTest extends WebTestCase
{
    use PlanningPilotTestHelpers;

    /**
     * @param array<string, mixed> $s
     */
    private function primaryLineStableId(array $s): string
    {
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($s['planningId']);
        $line = static::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0];

        return (string) $line->getStableId();
    }

    public function testAFreshLineHasAllDaysExcluded(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $lineId = $this->primaryLineStableId($s);

        $result = $this->api($client, 'GET', "/api/planning-lines/{$lineId}/week-structure", token: $s['creator']);
        self::assertResponseIsSuccessful();

        self::assertSame([], $result['blocks']);
        self::assertSame([], $result['solo']);
        self::assertSame(['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'], $result['excluded']);
    }

    public function testTheCreatorCanReplaceTheStructureAndReadItBack(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $lineId = $this->primaryLineStableId($s);

        $result = $this->api($client, 'PUT', "/api/planning-lines/{$lineId}/week-structure", [
            'blocks' => [['name' => 'Week-end', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => 'Week-end']],
            'solo' => ['LUN', 'MAR', 'MER', 'JEU'],
            'soloFamily' => 'Semaine',
            'excluded' => [],
        ], token: $s['creator']);
        self::assertResponseIsSuccessful();

        self::assertCount(1, $result['blocks']);
        self::assertSame('Week-end', $result['blocks'][0]['family']);
        self::assertSame('Semaine', $result['soloFamily']);
        // Regression: WeekStructurePayload.blocks[].id is required by the
        // frontend's fromPayload() to round-trip a block at all — omitting
        // it silently dropped every block back into "solo" on reload.
        self::assertSame('A', $result['blocks'][0]['id']);

        $reread = $this->api($client, 'GET', "/api/planning-lines/{$lineId}/week-structure", token: $s['creator']);
        self::assertSame($result, $reread);
    }

    public function testSeveralBlocksGetDistinctLetterIds(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $lineId = $this->primaryLineStableId($s);

        $result = $this->api($client, 'PUT', "/api/planning-lines/{$lineId}/week-structure", [
            'blocks' => [
                ['name' => 'Semaine', 'days' => ['LUN', 'MAR'], 'family' => 'Semaine'],
                ['name' => 'Week-end', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => 'Week-end'],
            ],
            'solo' => ['MER', 'JEU'],
            'soloFamily' => '',
            'excluded' => [],
        ], token: $s['creator']);
        self::assertResponseIsSuccessful();

        self::assertSame(['A', 'B'], array_column($result['blocks'], 'id'));
    }

    public function testATeamAdminCanAlsoReplaceTheStructure(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $lineId = $this->primaryLineStableId($s);

        $this->api($client, 'PUT', "/api/planning-lines/{$lineId}/week-structure", [
            'blocks' => [],
            'solo' => ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'],
            'soloFamily' => '',
            'excluded' => [],
        ], token: $s['admin']);
        self::assertResponseIsSuccessful();
    }

    public function testAPlainMemberCannotReplaceOrEvenReadTheStructure(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $lineId = $this->primaryLineStableId($s);

        $this->api($client, 'GET', "/api/planning-lines/{$lineId}/week-structure", token: $s['alice']);
        self::assertResponseStatusCodeSame(403);

        $this->api($client, 'PUT', "/api/planning-lines/{$lineId}/week-structure", [
            'blocks' => [],
            'solo' => ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'],
            'soloFamily' => '',
            'excluded' => [],
        ], token: $s['alice']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnOutsiderIsRefused(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $lineId = $this->primaryLineStableId($s);

        $this->api($client, 'GET', "/api/planning-lines/{$lineId}/week-structure", token: $s['outsider']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownLineIsA404(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $this->api($client, 'GET', '/api/planning-lines/01000000-0000-7000-8000-000000000000/week-structure', token: $s['creator']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testADayClaimedTwiceIsRejectedWith422AndNeverPersisted(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $lineId = $this->primaryLineStableId($s);

        $result = $this->api($client, 'PUT', "/api/planning-lines/{$lineId}/week-structure", [
            'blocks' => [['name' => 'Week-end', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => '']],
            'solo' => ['LUN', 'MAR', 'MER', 'JEU', 'DIM'], // DIM also solo
            'soloFamily' => '',
            'excluded' => [],
        ], token: $s['creator']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_week_structure', $result['error']);

        $reread = $this->api($client, 'GET', "/api/planning-lines/{$lineId}/week-structure", token: $s['creator']);
        self::assertSame([], $reread['blocks'], 'A rejected PUT must never leave a partial structure behind.');
    }

    public function testASingleDayBlockIsRejected(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $lineId = $this->primaryLineStableId($s);

        $result = $this->api($client, 'PUT', "/api/planning-lines/{$lineId}/week-structure", [
            'blocks' => [['name' => 'X', 'days' => ['VEN'], 'family' => '']],
            'solo' => ['LUN', 'MAR', 'MER', 'JEU', 'SAM', 'DIM'],
            'soloFamily' => '',
            'excluded' => [],
        ], token: $s['creator']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_week_structure', $result['error']);
    }
}
