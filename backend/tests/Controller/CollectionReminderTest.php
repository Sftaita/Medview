<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\UserRepository;
use App\Service\CollectionReminderService;
use App\Service\PlanningRuleSetService;
use App\Tests\LineFillTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * docs/decisions.md D234 — the availability reminders of the dashboard
 * (`GET /api/me/availability-collections`): an open collection is shown only
 * while the user's availabilities can still change something, and the
 * collection is never closed for that. Real generations (OR-Tools).
 *
 * Fixture (pilotScenario): planning 2027-01-01 → 2027-05-01, Seniors =
 * admin, alice, bob; its collection covers the whole planning.
 */
final class CollectionReminderTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use LineFillTestHelpers;

    private const STRUCTURE = [
        'blocks' => [['name' => 'Ven · Sam · Dim', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => 'Week-end']],
        'solo' => ['LUN', 'MAR', 'MER', 'JEU'],
        'soloFamily' => 'Semaine',
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

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /** @return list<array<string, mixed>> what the dashboard of $who shows */
    private function mine(string $who): array
    {
        $mine = $this->api($this->client, 'GET', '/api/me/availability-collections', token: $this->s[$who]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $mine;
    }

    private function collectionStatus(): string
    {
        return (string) $this->db()->fetchOne('SELECT status FROM availability_collections WHERE stable_id = ?', [$this->s['collectionId']]);
    }

    private function publish(): void
    {
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    /** Seniors: a few duties of its own, rules active, generated. */
    private function seniorsGenerated(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 3));
        $this->generate($this->client, $this->s);
    }

    /** Period 1 shortened to January, weekly structure, generated and published — the setting of an extension (D226). */
    private function januaryPublished(): string
    {
        $period = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'][0]['stableId'];
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period}", ['endsAt' => '2027-02-01', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $line = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][0]['stableId'];
        $this->api($this->client, 'PUT', "/api/planning-lines/{$line}/week-structure", self::STRUCTURE, $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningTeam());
        $this->generate($this->client, $this->s);
        $this->publish();

        return $period;
    }

    public function testAPeriodNotGeneratedIsRemindedWithItsRemainingDaysAndLines(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 3));

        $mine = $this->mine('alice');
        self::assertCount(1, $mine);
        self::assertSame($this->s['collectionId'], $mine[0]['stableId']);
        self::assertSame(['from' => '2027-01-01', 'lastDay' => '2027-04-30', 'lines' => ['Seniors'], 'reason' => 'NOT_GENERATED'], $mine[0]['pending']);
        self::assertSame([], $this->mine('outsider'));
    }

    public function testAGeneratedThenPublishedPeriodAsksForNothingAndItsCollectionStaysOpen(): void
    {
        $this->seniorsGenerated();
        self::assertSame([], $this->mine('alice'), 'an unavailability added after the generation is never applied (D216)');

        $this->publish();
        self::assertSame([], $this->mine('alice'));
        self::assertSame([], $this->mine('bob'));
        self::assertSame('OPEN', $this->collectionStatus(), 'a reading: the collection is never closed for the dashboard');

        // The manager's view of the collection is unchanged.
        $collections = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/availability-collections", token: $this->s['creator']);
        self::assertSame('OPEN', $collections[0]['status']);
    }

    public function testOneLinePublishedNeverHidesAnotherLineStillWaiting(): void
    {
        $this->seniorsGenerated();
        $this->publish();

        // Juniors joins the period after the publication (D231): alice belongs to both lines, carol to Juniors only.
        $team = $this->addSecondaryLine($this->client, $this->s, ['carol@example.com']);
        $this->addMemberAs($this->client, $this->s['creator'], $this->s['planningId'], $team, 'alice@example.com', 'MEMBER');
        $this->prepareLine($this->s['planningId'], self::days('2027-01-11', 3), lineIndex: 1);
        $this->s['carol'] = $this->loginUser($this->client, 'carol@example.com', 'correct-horse-battery');

        $alice = $this->mine('alice');
        self::assertCount(1, $alice);
        self::assertSame(['Juniors'], $alice[0]['pending']['lines'], 'only the line still waiting is named');
        self::assertSame('NOT_GENERATED', $alice[0]['pending']['reason']);
        self::assertSame(['Juniors'], $this->mine('carol')[0]['pending']['lines'] ?? null);
        self::assertSame([], $this->mine('bob'), 'Seniors only: published, nothing more is asked');
    }

    public function testANeutralLineNextToAPublishedOneAsksForNothingButCountsInAFreshPeriod(): void
    {
        // A fresh period: Juniors has no duty nor weekly structure yet — not configured, still waited for.
        $team = $this->addSecondaryLine($this->client, $this->s, ['carol@example.com']);
        $this->s['carol'] = $this->loginUser($this->client, 'carol@example.com', 'correct-horse-battery');
        self::assertSame(['Juniors'], $this->mine('carol')[0]['pending']['lines'] ?? null);

        // Seniors generated and published: Juniors, with nothing demanded (D194), is neutral — nothing more is asked.
        $this->seniorsGenerated();
        $this->publish();
        self::assertSame([], $this->mine('carol'));
        self::assertSame([], $this->mine('alice'));
        self::assertNotSame('', $team);
    }

    public function testAnExtensionIsRemindedForItsNewDatesUntilTheyHaveHolders(): void
    {
        $period = $this->januaryPublished();
        self::assertSame([], $this->mine('alice'));

        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period}", ['endsAt' => '2027-02-08', 'expectedEndsAt' => '2027-02-01'], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        // The collection people already answered keeps its dates (D230, not_extended): nothing asks for the new days
        // until the manager opens a collection for them.
        self::assertSame([], $this->mine('alice'));
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods/{$period}/availability-collections", [], $this->s['creator']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        $mine = $this->mine('alice');
        self::assertCount(1, $mine);
        self::assertSame(['from' => '2027-02-01', 'lastDay' => '2027-02-07', 'lines' => ['Seniors'], 'reason' => 'NEW_DATES'], $mine[0]['pending']);

        $plan = $this->fillPreview($this->client, $this->s)['lines'][0];
        $job = $this->fillNow($this->client, $this->s, [$plan['lineStableId']]);
        self::assertSame('SUCCEEDED', $job['status'], (string) json_encode($job));
        self::assertSame([], $this->mine('alice'), 'the new dates have holders: nothing is asked any more');
    }

    public function testThePastIsNeverAskedForAndTheDaysStartWithTheMembershipAndTheCoverage(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 3));
        $reminders = static::getContainer()->get(CollectionReminderService::class);
        $users = static::getContainer()->get(UserRepository::class);

        // Alice joins Seniors on 15 February; Seniors only covers from 1 March for everyone.
        $this->db()->executeStatement("UPDATE planning_team_members SET membership_start = '2027-02-15' WHERE user_id = (SELECT id FROM users WHERE email = 'alice@example.com')");
        self::assertSame('2027-02-15', $this->mine('alice')[0]['pending']['from']);
        $this->db()->executeStatement("UPDATE planning_lines SET coverage_starts_on = '2027-03-01'");
        self::assertSame('2027-03-01', $this->mine('alice')[0]['pending']['from']);
        self::assertSame('2027-03-01', $this->mine('bob')[0]['pending']['from']);

        // The window has started: only what is left of it.
        self::mockTime('2027-04-10 09:00:00 Europe/Brussels');
        static::getContainer()->get('doctrine')->getManager()->clear();
        $bob = $reminders->remindersFor($users->findOneByEmail('bob@example.com') ?? self::fail('bob'));
        self::assertCount(1, $bob);
        self::assertSame('2027-04-10', $bob[0]->toArray()['from']);
        self::assertSame('2027-04-30', $bob[0]->toArray()['lastDay']);

        // Over: never a reminder of the past, even for a period nobody generated.
        self::mockTime('2027-05-01 09:00:00 Europe/Brussels');
        self::assertSame([], $reminders->remindersFor($users->findOneByEmail('bob@example.com') ?? self::fail('bob')));
        self::assertSame('OPEN', $this->collectionStatus());
    }
}
