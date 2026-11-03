<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Service\PlanningRuleSetService;
use App\Tests\LineFillTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D227 — the general "Semaine type" and what each line uses:
 * the general one, its own, or (a reinforcement line) the blocks of its source.
 * A change never touches a duty that exists.
 */
final class PlanningWeekTemplateTest extends WebTestCase
{
    use LineFillTestHelpers;

    private const WEEK = [
        'blocks' => [['name' => 'Ven · Sam · Dim', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => 'Week-end']],
        'solo' => ['LUN', 'MAR', 'MER', 'JEU'],
        'soloFamily' => 'Semaine',
        'excluded' => [],
    ];
    private const WEEKDAYS_ONLY = ['blocks' => [], 'solo' => ['LUN', 'MAR', 'MER', 'JEU', 'VEN'], 'soloFamily' => 'Semaine', 'excluded' => ['SAM', 'DIM']];

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function url(): string
    {
        return "/api/plannings/{$this->s['planningId']}/week-template";
    }

    /** @return array<string, mixed> */
    private function template(): array
    {
        $body = $this->api($this->client, 'GET', $this->url(), token: $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $body;
    }

    /** @param array<string, mixed> $structure @return array<string, mixed> */
    private function saveTemplate(array $structure): array
    {
        $body = $this->api($this->client, 'PUT', $this->url(), $structure, $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $body;
    }

    /** @return array<string, string> line name => kind */
    private function kinds(): array
    {
        return array_column($this->template()['lines'], 'kind', 'name');
    }

    /** @return list<string> */
    private function ownDays(string $line): array
    {
        $own = $this->api($this->client, 'GET', "/api/planning-lines/{$line}/week-structure", token: $this->s['creator']);

        return $own['solo'];
    }

    public function testThePrimaryLineFollowsItAndANewLineStartsOnIt(): void
    {
        self::assertSame(['Seniors' => 'GENERAL'], $this->kinds());

        $saved = $this->saveTemplate(self::WEEK);
        self::assertSame(['Seniors'], $saved['appliedTo']);
        self::assertSame(['LUN', 'MAR', 'MER', 'JEU'], $saved['solo']);
        self::assertSame(['LUN', 'MAR', 'MER', 'JEU'], $this->ownDays($this->lineIds($this->client, $this->s)[0]), 'the line received its own copy');

        $this->addSecondaryLine($this->client, $this->s, ['carol@example.com']);
        $ids = $this->lineIds($this->client, $this->s);
        self::assertSame('GENERAL', array_values($this->kinds())[1]);
        self::assertSame(['LUN', 'MAR', 'MER', 'JEU'], $this->ownDays($ids[1]));
        $detail = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator']);
        self::assertSame('GENERAL', $detail['lines'][1]['coverage']['weekStructure']);
    }

    public function testACustomLineIsLeftAloneAndCanComeBack(): void
    {
        $this->saveTemplate(self::WEEK);
        $this->addSecondaryLine($this->client, $this->s, ['carol@example.com']);
        $ids = $this->lineIds($this->client, $this->s);

        // Saving on the line itself makes it its own.
        $this->api($this->client, 'PUT', "/api/planning-lines/{$ids[1]}/week-structure", self::WEEKDAYS_ONLY, $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('CUSTOM', array_values($this->kinds())[1]);

        $saved = $this->saveTemplate(['blocks' => [], 'solo' => ['LUN'], 'soloFamily' => '', 'excluded' => ['MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM']]);
        self::assertSame(['Seniors'], $saved['appliedTo'], 'only the lines that follow it');
        self::assertSame(['LUN'], $this->ownDays($ids[0]));
        self::assertSame(['LUN', 'MAR', 'MER', 'JEU', 'VEN'], $this->ownDays($ids[1]), 'the custom line keeps its structure');

        $back = $this->api($this->client, 'PUT', "/api/planning-lines/{$ids[1]}/week-structure-mode", ['mode' => 'GENERAL'], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(['GENERAL', ['LUN']], [$back['mode'], $back['solo']]);

        $this->api($this->client, 'PUT', "/api/planning-lines/{$ids[1]}/week-structure-mode", ['mode' => 'CUSTOM'], $this->s['creator']);
        self::assertSame(['LUN'], $this->ownDays($ids[1]), 'personalizing keeps what it has until edited');
        $this->api($this->client, 'PUT', "/api/planning-lines/{$ids[1]}/week-structure-mode", ['mode' => 'OTHER'], $this->s['creator']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testThePrimaryLinePersonalizedKeepsTheGeneralStructureItCarried(): void
    {
        $line = $this->lineIds($this->client, $this->s)[0];
        $this->api($this->client, 'PUT', "/api/planning-lines/{$line}/week-structure-mode", ['mode' => 'GENERAL'], $this->s['creator']);
        $this->saveTemplate(self::WEEK);

        // Two steps: the general structure is explicit, so that the primary line can leave it without taking it away.
        $this->api($this->client, 'PUT', "/api/planning-lines/{$line}/week-structure", self::WEEKDAYS_ONLY, $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(['LUN', 'MAR', 'MER', 'JEU'], $this->template()['solo']);
        self::assertSame(['LUN', 'MAR', 'MER', 'JEU', 'VEN'], $this->ownDays($line));
    }

    public function testAChangeNeverTouchesAnExistingDutyNorAPublishedLine(): void
    {
        $this->saveTemplate(self::WEEK);
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningTeam());
        $period = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'][0]['stableId'];
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period}", ['endsAt' => '2027-02-01', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        $this->generate($this->client, $this->s);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $fingerprint = fn (): array => [
            $this->db()->fetchOne("SELECT md5(string_agg(ROW(d.*)::text, '|' ORDER BY d.id)) FROM duties d"),
            $this->db()->fetchOne("SELECT md5(string_agg(ROW(a.*)::text, '|' ORDER BY a.id)) FROM duty_assignments a"),
            $this->db()->fetchOne("SELECT md5(string_agg(ROW(g.*)::text, '|' ORDER BY g.id)) FROM planning_generations g"),
            $this->db()->fetchOne("SELECT md5(string_agg(ROW(e.*)::text, '|' ORDER BY e.id)) FROM planning_publication_entries e"),
        ];
        $before = $fingerprint();

        $saved = $this->saveTemplate(self::WEEKDAYS_ONLY);
        self::assertSame($before, $fingerprint(), 'not one row changed');
        self::assertSame([['number' => 1, 'state' => 'published']], $saved['lines'][0]['periods'], 'said to the manager');

        // Only what is created afterwards follows it: an extension of two weeks, from Monday 1 February, without weekend.
        $moved = $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period}", ['endsAt' => '2027-02-08', 'expectedEndsAt' => '2027-02-01'], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(5, $moved['addedDutyCount'], 'Monday to Friday of the new structure');
    }

    public function testAReinforcementLineInheritsAndLeavesTheGeneralStructure(): void
    {
        $this->saveTemplate(self::WEEK);
        $this->addSecondaryLine($this->client, $this->s, ['carol@example.com']);
        $ids = $this->lineIds($this->client, $this->s);
        self::assertSame(['LUN', 'MAR', 'MER', 'JEU'], $this->ownDays($ids[1]));

        $this->api($this->client, 'PUT', "/api/planning-lines/{$ids[1]}/demand-policy", ['schemaVersion' => 1, 'mode' => 'CONDITIONAL_ON_SOURCE_ASSIGNMENT', 'source' => ['lineStableId' => $ids[0]], 'triggers' => []], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        self::assertSame('INHERITED', array_values($this->kinds())[1]);
        self::assertSame(['Seniors'], $this->saveTemplate(self::WEEKDAYS_ONLY)['appliedTo'], 'an inheriting line never receives the general structure');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM duty_patterns dp JOIN planning_lines pl ON pl.planning_team_id = dp.team_id WHERE pl.stable_id = ? AND dp.active AND dp.recurring', [$ids[1]]), 'no structure of its own');

        $this->api($this->client, 'PUT', "/api/planning-lines/{$ids[1]}/week-structure-mode", ['mode' => 'GENERAL'], $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
        $this->api($this->client, 'PUT', "/api/planning-lines/{$ids[1]}/week-structure", self::WEEK, $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testOnlyThoseWhoManageTheStructure(): void
    {
        $this->api($this->client, 'GET', $this->url(), token: $this->s['alice']);
        self::assertResponseStatusCodeSame(403);
        $this->api($this->client, 'PUT', $this->url(), self::WEEK, $this->s['alice']);
        self::assertResponseStatusCodeSame(403);
        $this->api($this->client, 'GET', '/api/plannings/01a15e52-4559-7752-b1f4-76f2c5d5658f/week-template', token: $this->s['creator']);
        self::assertResponseStatusCodeSame(404);
    }
}
