<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PeriodPreparationDelivery;
use App\Entity\PlanningCycle;
use App\Entity\PreparationDeliveryKind;
use App\Repository\PeriodPreparationDeliveryRepository;
use App\Repository\PeriodPreparationRepository;
use App\Repository\PlanningCycleRepository;
use App\Repository\PlanningRepository;
use App\Repository\UserRepository;
use App\Service\PeriodPreparationRunner;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261024090000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

/**
 * docs/decisions.md D210 — what `app:period-preparation:run` does with what
 * was planned: announcements at 08:00 Europe/Brussels, reminders to the
 * people who have not confirmed, the outbox (one email per person and
 * action, claims, retries, stale claims), and every check made again when it
 * runs. The runner is called with an explicit instant.
 *
 * Fixture (pilotScenario): planning 2027-01-01 → 2027-05-01, period 1 with its
 * collection open (creator, admin, alice, bob expected); period 2
 * (2027-05-01 → 2027-07-31) without collection.
 */
final class PeriodPreparationRunnerTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    private ?\Closure $failingListener = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
    }

    protected function tearDown(): void
    {
        $this->stopFailing();
        parent::tearDown();
    }

    // --- helpers -----------------------------------------------------------------------------------

    private function createPeriod2(): string
    {
        $periods = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'];
        $created = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", ['endsAt' => '2027-08-01', 'previousPeriodStableId' => $periods[0]['stableId']], $this->s['creator']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $created['stableId'];
    }

    private function period1(): string
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'][0]['stableId'];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function prepare(string $period, array $body, ?string $token = null): array
    {
        $view = $this->api($this->client, 'PUT', "/api/plannings/{$this->s['planningId']}/periods/{$period}/preparation", $body + ['announceOn' => null, 'targetGenerationOn' => null, 'reminders' => []], $token ?? $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $view;
    }

    /**
     * @return array<string, mixed>
     */
    private function view(string $period): array
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods/{$period}/preparation", token: $this->s['creator']);
    }

    /** "2027-03-01 08:00" in Europe/Brussels. */
    private static function at(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone('Europe/Brussels'));
    }

    /**
     * Runs the preparation at $now and returns the report plus the addresses of the emails really sent by that run.
     *
     * @return array{report: array<string, int>, to: list<string>, emails: list<Email>}
     */
    private function runAt(\DateTimeImmutable $now): array
    {
        $before = \count(self::getMailerMessages());
        $report = static::getContainer()->get(PeriodPreparationRunner::class)->run($now);
        /** @var list<Email> $emails */
        $emails = array_values(\array_slice(self::getMailerMessages(), $before));
        $to = array_map(static fn (Email $message): string => $message->getTo()[0]->getAddress(), $emails);
        sort($to);

        return ['report' => $report, 'to' => $to, 'emails' => $emails];
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function cycle(string $period): PlanningCycle
    {
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $cycle = static::getContainer()->get(PlanningCycleRepository::class)->findOneForPlanning($planning, $period);
        self::assertNotNull($cycle);

        return $cycle;
    }

    /**
     * @return list<string> "KIND email STATUS[ reason]" ordered
     */
    private function deliveries(): array
    {
        return array_map(
            static fn (array $r): string => trim("{$r['kind']} {$r['email']} {$r['status']} {$r['cancel_reason']}"),
            $this->db()->fetchAllAssociative('SELECT d.kind, u.email, d.status, d.cancel_reason FROM period_preparation_deliveries d JOIN users u ON u.id = d.recipient_id ORDER BY d.kind, d.reminder_id NULLS FIRST, u.email'),
        );
    }

    /**
     * @return list<string> the people expected to answer the open collections of $period
     */
    private function respondents(string $period): array
    {
        return $this->db()->fetchFirstColumn(
            'SELECT DISTINCT u.email FROM availability_collection_responses r JOIN users u ON u.id = r.user_id JOIN availability_collections c ON c.id = r.collection_id JOIN planning_cycles cy ON cy.id = c.planning_cycle_id
              WHERE cy.stable_id = :period AND c.status = \'OPEN\' ORDER BY u.email',
            ['period' => $period],
        );
    }

    private function collectionOf(string $period): string
    {
        return (string) $this->db()->fetchOne("SELECT c.stable_id FROM availability_collections c JOIN planning_cycles cy ON cy.id = c.planning_cycle_id WHERE cy.stable_id = :period AND c.status = 'OPEN' ORDER BY c.starts_at LIMIT 1", ['period' => $period]);
    }

    /** Absences entered by $token over the given days: never a reason to skip anybody (D214). */
    private function declareAbsence(string $token, string $from, string $to): void
    {
        $this->api($this->client, 'POST', '/api/me/calendar', ['type' => 'UNAVAILABLE', 'startsAt' => $from.'T00:00:00+01:00', 'endsAt' => $to.'T00:00:00+01:00'], $token);
        self::assertResponseStatusCodeSame(201);
    }

    /** The mail transport refuses every email to $address, or to everybody when null (until stopFailing()). */
    private function failFor(?string $address): void
    {
        $this->stopFailing();
        $this->failingListener = static function (MessageEvent $event) use ($address): void {
            $message = $event->getMessage();
            if ($message instanceof Email && (null === $address || $message->getTo()[0]->getAddress() === $address)) {
                throw new TransportException('SMTP refused');
            }
        };
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(MessageEvent::class, $this->failingListener, 1000);
    }

    private function stopFailing(): void
    {
        if (null !== $this->failingListener) {
            static::getContainer()->get(EventDispatcherInterface::class)->removeListener(MessageEvent::class, $this->failingListener);
            $this->failingListener = null;
        }
    }

    // --- announcements -----------------------------------------------------------------------------

    public function testTheAnnouncementRunsAt8OpensTheCollectionOnceAndInvitesEachPersonOnce(): void
    {
        $period = $this->createPeriod2();
        $this->prepare($period, ['announceOn' => '2027-03-01', 'targetGenerationOn' => '2027-04-01']);

        $early = $this->runAt(self::at('2027-03-01 07:59'));
        self::assertSame([], $early['to']);
        self::assertSame([], $this->respondents($period), 'Nothing opens before 08:00 Europe/Brussels.');

        $run = $this->runAt(self::at('2027-03-01 08:00'));
        self::assertSame(1, $run['report']['announcements']);
        $respondents = $this->respondents($period);
        self::assertContains('alice@example.com', $respondents);
        self::assertContains('bob@example.com', $respondents);
        self::assertSame($respondents, $run['to'], 'One invitation per person expected to answer.');
        self::assertSame(
            [['2027-05-01', '2027-08-01', 'OPEN']],
            array_map('array_values', $this->db()->fetchAllAssociative('SELECT c.starts_at::text, c.ends_at::text, c.status FROM availability_collections c JOIN planning_cycles cy ON cy.id = c.planning_cycle_id WHERE cy.stable_id = :p', ['p' => $period])),
        );
        $email = $run['emails'][0];
        self::assertStringContainsString('Disponibilités à communiquer', (string) $email->getSubject());
        self::assertStringContainsString('/my-availability?collection='.$this->collectionOf($period), (string) $email->getHtmlBody());
        self::assertStringContainsString('1er avril 2027', (string) $email->getTextBody());

        $view = $this->view($period);
        self::assertSame(['executed', 'collection_opened'], [$view['announcement']['state'], $view['announcement']['outcome']]);
        self::assertSame(\count($respondents), $view['announcement']['emails']['sent']);
        self::assertSame(['state' => 'open', 'participants' => \count($respondents)], $view['collection']);

        // Again later, and the next day: nothing more, ever.
        self::assertSame([], $this->runAt(self::at('2027-03-01 08:10'))['to']);
        self::assertSame([], $this->runAt(self::at('2027-03-02 08:00'))['to']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM availability_collections c JOIN planning_cycles cy ON cy.id = c.planning_cycle_id WHERE cy.stable_id = :p', ['p' => $period]));
    }

    public function testAnExistingOpenCollectionIsNeverDuplicatedAndEveryParticipantIsInvited(): void
    {
        $period = $this->period1();
        // Alice already entered absences: she is invited all the same (D214).
        $this->declareAbsence($this->s['alice'], '2027-02-01', '2027-02-04');
        $this->prepare($period, ['announceOn' => '2026-11-02', 'declarationDeadlineOn' => '2026-11-20', 'targetGenerationOn' => '2026-11-25']);

        $run = $this->runAt(self::at('2026-11-02 08:00'));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM availability_collections'));
        self::assertSame('collection_already_open', $this->view($period)['announcement']['outcome']);
        self::assertSame($this->respondents($period), $run['to']);
        self::assertContains('alice@example.com', $run['to']);
        // The collection takes the planned indicative deadline, and the email says it is indicative.
        self::assertSame('2026-11-20', $this->db()->fetchOne('SELECT deadline FROM availability_collections'));
        $text = (string) $run['emails'][0]->getTextBody();
        self::assertStringContainsString('Date limite indicative : 20 novembre 2026. Votre calendrier reste modifiable après cette date.', $text);
        self::assertStringContainsString('Sans indisponibilité renseignée, vous êtes considéré comme disponible.', $text);
        self::assertStringNotContainsString('confirmer', $text);
    }

    /**
     * Trauma Delta, period 2 in production (October 2026): a collection opened by hand on 28 October, the
     * announcement planned for 17 January. Until the announcement runs, nobody is told: the period does not
     * read "collecting", members do not see the collection, the planning is not "collecting" either.
     */
    public function testACollectionOpenedBeforeItsScheduledAnnouncementIsNotShownAsOpen(): void
    {
        $period = $this->createPeriod2();
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods/{$period}/availability-collections", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $this->prepare($period, ['announceOn' => '2027-03-01', 'targetGenerationOn' => '2027-04-01']);
        $status = fn (): string => $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'][1]['status'];
        $mine = fn (): array => array_column($this->api($this->client, 'GET', '/api/me/availability-collections', token: $this->s['alice']), 'period');
        $collecting = fn (): bool => array_values(array_filter($this->api($this->client, 'GET', '/api/plannings', token: $this->s['creator']), fn (array $p): bool => $p['stableId'] === $this->s['planningId']))[0]['collecting'];

        self::assertSame('preparation', $status(), 'announcement still scheduled: not "Collecte ouverte"');
        self::assertNotContains(2, array_column($mine(), 'number'), 'members are not shown a collection nobody announced yet');

        // Period 1's collection is open and announced (no preparation): the planning collects for it.
        self::assertTrue($collecting());
        $this->api($this->client, 'POST', "/api/availability-collections/{$this->s['collectionId']}/close", token: $this->s['creator']);
        self::assertFalse($collecting(), 'only the not-yet-announced collection is open');

        // The announcement runs: now it is open for everyone, and said so.
        $run = $this->runAt(self::at('2027-03-01 08:00'));
        self::assertSame($this->respondents($period), $run['to']);
        self::assertSame('collecting', $status());
        self::assertContains(2, array_column($mine(), 'number'));
        self::assertTrue($collecting());
    }

    public function testBothLinesAreInvitedAndAPersonOnBothLinesOnlyOnce(): void
    {
        $team = $this->addSecondaryLine($this->client, $this->s, ['carol@example.com']);
        $this->addMemberAs($this->client, $this->s['creator'], $this->s['planningId'], $team, 'alice@example.com', 'MEMBER');
        $period = $this->createPeriod2();
        $this->prepare($period, ['announceOn' => '2027-03-01']);

        $run = $this->runAt(self::at('2027-03-01 08:00'));
        self::assertContains('carol@example.com', $run['to']);
        self::assertSame(1, \count(array_keys($run['to'], 'alice@example.com', true)));
        self::assertSame($this->respondents($period), $run['to']);
    }

    public function testAnAnnouncementIsSkippedWhenItsManagerLostTheRight(): void
    {
        $period = $this->createPeriod2();
        $this->prepare($period, ['announceOn' => '2027-03-01'], $this->s['admin']);
        $this->db()->executeStatement("UPDATE planning_team_members SET role = 'MEMBER' WHERE user_id = (SELECT id FROM users WHERE email = 'admin@example.com')");

        $run = $this->runAt(self::at('2027-03-01 08:00'));
        self::assertSame([], $run['to']);
        self::assertSame(['skipped', 'manager_not_allowed'], [$this->view($period)['announcement']['state'], $this->view($period)['announcement']['outcome']]);
        self::assertSame([], $this->respondents($period), 'No collection is opened on behalf of someone no longer allowed.');
    }

    // --- D208: only a real send counts as contact --------------------------------------------------

    public function testOnlyAnEmailReallySentCountsAsContactForTheDateRules(): void
    {
        $period = $this->createPeriod2();
        $this->prepare($period, ['announceOn' => '2027-03-01']);
        $this->failFor(null);
        $this->runAt(self::at('2027-03-01 08:00'));

        $facts = static::getContainer()->get(PlanningCycleRepository::class)->dateFacts($this->cycle($period));
        self::assertFalse($facts['collectionContacted'], 'Queued but refused by the transport: nobody was written to.');
        self::assertNotNull($facts['untouchedCollectionId'], 'The collection still follows the dates of the period.');

        $this->stopFailing();
        $this->runAt(self::at('2027-03-01 08:10'));
        $facts = static::getContainer()->get(PlanningCycleRepository::class)->dateFacts($this->cycle($period));
        self::assertTrue($facts['collectionContacted']);
        self::assertNull($facts['untouchedCollectionId']);

        // A collection people were written to is never resized silently (D208).
        $periods = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'];
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period}", ['endsAt' => '2027-07-20', 'expectedEndsAt' => $periods[1]['endsAt']], $this->s['creator']);
        self::assertResponseStatusCodeSame(409, (string) $this->client->getResponse()->getContent());
        self::assertSame('collection_announced', json_decode((string) $this->client->getResponse()->getContent(), true)['error']);
    }

    // --- reminders ---------------------------------------------------------------------------------

    public function testAfterAnOutageOnlyTheLatestReminderGoesOutToEveryParticipant(): void
    {
        $period = $this->createPeriod2();
        $this->prepare($period, ['announceOn' => '2027-03-01', 'targetGenerationOn' => '2027-04-01', 'reminders' => [['value' => 2, 'unit' => 'WEEK'], ['value' => 3, 'unit' => 'DAY']]]);
        $this->runAt(self::at('2027-03-01 08:00'));
        // Alice entered absences since the announcement: she may still add some, she is reminded too (D214).
        $this->declareAbsence($this->s['alice'], '2027-03-10', '2027-03-12');

        // Down from 17 March to 29 March 10:00: both reminders are overdue.
        $run = $this->runAt(self::at('2027-03-29 10:00'));
        $expected = $this->respondents($period);
        self::assertContains('alice@example.com', $expected);
        self::assertSame($expected, $run['to']);
        self::assertSame(1, $run['report']['reminders']);
        $reminders = $this->view($period)['reminders'];
        self::assertSame([['skipped', 'superseded'], ['executed', 'sent_to_participants']], array_map(static fn (array $r): array => [$r['state'], $r['outcome']], $reminders));
        self::assertSame(\count($expected), $reminders[1]['emails']['sent']);
        self::assertStringContainsString('La génération des gardes approche', (string) $run['emails'][0]->getSubject());
        self::assertStringContainsString('Pensez à vérifier et à actualiser vos éventuelles indisponibilités avant la date prévue de génération.', (string) $run['emails'][0]->getTextBody());

        self::assertSame([], $this->runAt(self::at('2027-03-29 10:10'))['to']);
    }

    public function testAReminderAfterTheTargetDateIsSkippedAndTheCollectionStaysOpen(): void
    {
        $period = $this->createPeriod2();
        $this->prepare($period, ['announceOn' => '2027-03-01', 'targetGenerationOn' => '2027-04-01', 'reminders' => [['value' => 3, 'unit' => 'DAY']]]);
        $this->runAt(self::at('2027-03-01 08:00'));

        $run = $this->runAt(self::at('2027-04-02 08:00'));
        self::assertSame([], $run['to']);
        self::assertSame(['skipped', 'target_passed'], [$this->view($period)['reminders'][0]['state'], $this->view($period)['reminders'][0]['outcome']]);
        self::assertSame('OPEN', $this->db()->fetchOne('SELECT c.status FROM availability_collections c JOIN planning_cycles cy ON cy.id = c.planning_cycle_id WHERE cy.stable_id = :p', ['p' => $period]), 'The target date never closes the collection.');
    }

    // --- the outbox --------------------------------------------------------------------------------

    public function testARefusedEmailIsRetriedAndNobodyElseGetsASecondOne(): void
    {
        $period = $this->createPeriod2();
        $this->prepare($period, ['announceOn' => '2027-03-01']);
        $this->failFor('alice@example.com');

        $first = $this->runAt(self::at('2027-03-01 08:00'));
        self::assertNotContains('alice@example.com', $first['to']);
        self::assertSame(1, $first['report']['failed']);
        self::assertContains('ANNOUNCEMENT alice@example.com FAILED', $this->deliveries());

        $second = $this->runAt(self::at('2027-03-01 08:10'));
        self::assertSame([], $second['to']);
        self::assertSame(2, (int) $this->db()->fetchOne("SELECT d.attempts FROM period_preparation_deliveries d JOIN users u ON u.id = d.recipient_id WHERE u.email = 'alice@example.com'"));

        $this->stopFailing();
        $third = $this->runAt(self::at('2027-03-01 08:20'));
        self::assertSame(['alice@example.com'], $third['to']);
        self::assertSame([], array_filter($this->deliveries(), static fn (string $d): bool => !str_ends_with($d, 'SENT')));
    }

    public function testAnEmailWhosePersonLeftMeanwhileIsCancelledButAbsencesNeverCancelOne(): void
    {
        $period = $this->createPeriod2();
        $this->prepare($period, ['announceOn' => '2027-03-01']);
        $this->failFor(null);
        $this->runAt(self::at('2027-03-01 08:00'));
        // Bob is no longer a participant of the window; Alice entered absences (that cancels nothing).
        $this->db()->executeStatement("UPDATE availability_collection_responses SET withdrawn_at = NOW() WHERE user_id = (SELECT id FROM users WHERE email = 'bob@example.com')");
        $this->declareAbsence($this->s['alice'], '2027-03-10', '2027-03-12');
        $this->stopFailing();

        $run = $this->runAt(self::at('2027-03-01 08:10'));
        self::assertNotContains('bob@example.com', $run['to']);
        self::assertContains('alice@example.com', $run['to']);
        self::assertContains('ANNOUNCEMENT bob@example.com CANCELLED not_participant', $this->deliveries());
    }

    public function testAClaimIsExclusiveAndAnAbandonedSendIsRecoveredOnce(): void
    {
        $period = $this->createPeriod2();
        $this->prepare($period, ['announceOn' => '2027-03-01']);
        $this->failFor(null);
        $this->runAt(self::at('2027-03-01 08:00'));
        $this->stopFailing();

        $container = static::getContainer();
        $repository = $container->get(PeriodPreparationDeliveryRepository::class);
        $deliveries = $repository->findBy([], ['id' => 'ASC']);
        self::assertNotEmpty($deliveries);
        $now = self::at('2027-03-01 08:05')->setTimezone(new \DateTimeZone('UTC'));
        $stale = $now->modify('-15 minutes');
        self::assertTrue($repository->claim($deliveries[0], $now, $stale));
        self::assertFalse($repository->claim($deliveries[0], $now, $stale), 'A second sender never gets the same email.');

        // The sender died after the claim: not retried while the claim is fresh, retried once after.
        $address = $deliveries[0]->getRecipient()->getEmail();
        self::assertNotContains($address, $this->runAt(self::at('2027-03-01 08:10'))['to']);
        $late = $this->runAt(self::at('2027-03-01 08:25'));
        self::assertSame(1, \count(array_keys($late['to'], $address, true)));
        self::assertSame([], $this->runAt(self::at('2027-03-01 08:45'))['to']);
    }

    public function testLaunchingTheGenerationCancelsTheEmailsNotSentYet(): void
    {
        $period = $this->period1();
        $this->prepare($period, ['announceOn' => '2026-11-02', 'targetGenerationOn' => '2026-12-15', 'reminders' => [['value' => 1, 'unit' => 'WEEK']]]);
        $this->failFor(null);
        $this->runAt(self::at('2026-11-02 08:00'));
        $this->stopFailing();
        self::assertNotEmpty($this->deliveries());

        $this->prepareLine($this->s['planningId'], [['2027-01-04 08:00', '2027-01-05 08:00']]);
        $this->generate($this->client, $this->s);

        self::assertSame([], array_filter($this->deliveries(), static fn (string $d): bool => !str_ends_with($d, 'CANCELLED generation_started')));
        self::assertSame([], $this->runAt(self::at('2026-12-08 08:00'))['to'], 'Neither the invitations nor the reminder go out.');
        self::assertSame(['cancelled', 'generation_started'], [$this->view($period)['reminders'][0]['state'], $this->view($period)['reminders'][0]['outcome']]);
    }

    public function testQueueingTheSameEmailTwiceKeepsOneRow(): void
    {
        $period = $this->createPeriod2();
        $this->prepare($period, ['announceOn' => '2027-03-01']);
        $container = static::getContainer();
        $preparation = $container->get(PeriodPreparationRepository::class)->findOneByCycle($this->cycle($period));
        $alice = $container->get(UserRepository::class)->findOneByEmail('alice@example.com');
        $repository = $container->get(PeriodPreparationDeliveryRepository::class);
        $now = new \DateTimeImmutable('2027-03-01 07:00', new \DateTimeZone('UTC'));

        self::assertTrue($repository->queue($preparation, PreparationDeliveryKind::ANNOUNCEMENT, null, $alice, $now));
        self::assertFalse($repository->queue($preparation, PreparationDeliveryKind::ANNOUNCEMENT, null, $alice, $now), 'One announcement per person and period.');
        self::assertSame(1, \count($container->get(EntityManagerInterface::class)->getRepository(PeriodPreparationDelivery::class)->findAll()));
    }

    public function testTheMigrationRefusesToGoDownOnceSomethingWasSent(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20261024090000.php';
        $migration = new Version20261024090000($this->db(), new NullLogger());
        $migration->down(new Schema());
        self::assertNotEmpty($migration->getSql(), 'Nothing sent yet: the tables may be dropped.');

        $period = $this->createPeriod2();
        $this->prepare($period, ['announceOn' => '2027-03-01']);
        $this->runAt(self::at('2027-03-01 08:00'));

        $this->expectException(AbortMigration::class);
        (new Version20261024090000($this->db(), new NullLogger()))->down(new Schema());
    }

    // --- the command -------------------------------------------------------------------------------

    public function testTheCommandRunsAndReports(): void
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:period-preparation:run'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('0 announcement(s), 0 reminder(s) run', $tester->getDisplay());
    }
}
