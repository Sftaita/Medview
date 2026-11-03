<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PlanningPeriodStatus;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Service\PlanningCycleAudit;
use App\Service\PlanningRuleSetService;
use App\Tests\LineFillTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D225-D226 — one "Modifier les dates", with real data read
 * before and after each operation: extending a generated or published period,
 * the next period that follows, shortening with its three outcomes, the blocks,
 * publications, journal and emails. Real generations (OR-Tools).
 *
 * Fixture (pilotScenario): Seniors = admin, alice, bob; planning 2027-01-01 →
 * 2027-05-01. Weekly structure: Monday to Thursday alone, block Fri-Sat-Sun.
 */
final class PeriodDateChangeTest extends WebTestCase
{
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

    /** @return list<array<string, mixed>> */
    private function periods(): array
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'];
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function patch(string $period, array $body): array
    {
        return $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period}", $body, $this->s['creator']);
    }

    /** @return array<string, mixed> */
    private function preview(string $period, string $query): array
    {
        $preview = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods/{$period}/date-change-preview?{$query}", token: $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $preview;
    }

    private function assertError(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());
        self::assertSame($code, json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? null);
    }

    private function assertAuditClean(): void
    {
        $audit = static::getContainer()->get(PlanningCycleAudit::class);
        self::assertSame([], $audit->violations($audit->report()));
    }

    private function createPeriod(string $endsAt): string
    {
        $periods = $this->periods();
        $created = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", ['endsAt' => $endsAt, 'previousPeriodStableId' => $periods[\count($periods) - 1]['stableId']], $this->s['creator']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $created['stableId'];
    }

    /** Period 1 shortened to a planning-sized month, weekly structure set, rules active, generated. */
    private function generatedPeriodOne(string $endsAt = '2027-02-01', bool $publish = false): string
    {
        $period1 = $this->periods()[0]['stableId'];
        $this->patch($period1, ['endsAt' => $endsAt, 'expectedEndsAt' => '2027-05-01']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $line = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][0]['stableId'];
        $this->api($this->client, 'PUT', "/api/planning-lines/{$line}/week-structure", self::STRUCTURE, $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningTeam());
        $this->generate($this->client, $this->s);
        if ($publish) {
            $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
            self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        }

        return $period1;
    }

    /** @return array<int, array{date: string, holder: int|null}> by duty id: every duty still in the calendar */
    private function calendar(): array
    {
        $rows = $this->db()->fetchAllAssociative(<<<'SQL'
            SELECT d.id, d.local_date::text AS date, a.id AS assignment, a.team_member_id AS holder, a.source
            FROM duties d LEFT JOIN duty_assignments a ON a.duty_id = d.id AND a.current
            WHERE d.retired_at IS NULL ORDER BY d.local_date, d.id
            SQL);
        $calendar = [];
        foreach ($rows as $row) {
            $calendar[(int) $row['id']] = ['date' => $row['date'], 'assignment' => $row['assignment'], 'holder' => $row['holder'], 'source' => $row['source']];
        }

        return $calendar;
    }

    private function rows(string $sql): int
    {
        return (int) $this->db()->fetchOne($sql);
    }

    // --- Case A -----------------------------------------------------------------------------------

    public function testAPeriodNeverGeneratedMovesWithoutAnyConfirmationAndTheNextOneFollows(): void
    {
        $period1 = $this->periods()[0]['stableId'];
        $period2 = $this->createPeriod('2027-08-01');

        $preview = $this->preview($period1, 'endsAt=2027-05-08');
        self::assertFalse($preview['confirmationRequired']);
        self::assertSame([[1, '2027-05-07'], [2, '2027-07-31']], array_map(static fn (array $c): array => [$c['periodNumber'], $c['to']['lastDay']], $preview['changes']));
        self::assertSame('2027-05-08', $preview['changes'][1]['to']['startsAt'], 'the day after the new last day');

        $this->patch($period1, ['endsAt' => '2027-05-08', 'expectedEndsAt' => '2027-05-01']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('2027-05-08', $this->periods()[1]['startsAt']);
        self::assertSame($period2, $this->periods()[1]['stableId']);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM period_date_changes'));
        $this->assertAuditClean();
    }

    // --- Case B: extension ------------------------------------------------------------------------

    public function testExtendingAGeneratedPeriodKeepsEveryAssignmentAndCreatesEmptyDuties(): void
    {
        $period1 = $this->generatedPeriodOne();
        $before = $this->calendar();
        self::assertNotEmpty($before);
        $period2 = $this->createPeriod('2027-04-01');
        self::assertSame($period2, $this->periods()[1]['stableId']);

        // 1 February (Monday) to 7 February: the week, then the whole weekend block.
        $preview = $this->preview($period1, 'endsAt=2027-02-08');
        self::assertFalse($preview['confirmationRequired'], 'the next period is blank: it just follows');
        self::assertSame([[1, 'Seniors', '2027-02-01', '2027-02-07', 7, false]], array_map(static fn (array $a): array => [$a['periodNumber'], $a['line'], $a['firstDay'], $a['lastDay'], $a['dutyCount'], $a['published']], $preview['additions']));

        $moved = $this->patch($period1, ['endsAt' => '2027-02-08', 'expectedEndsAt' => '2027-02-01']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(7, $moved['addedDutyCount']);
        self::assertTrue($moved['generationOutdated']);
        self::assertSame('2027-02-08', $this->periods()[1]['startsAt'], 'the next period starts the day after');

        $after = $this->calendar();
        foreach ($before as $id => $row) {
            self::assertSame($row, $after[$id], 'an existing assignment is never touched');
        }
        $new = array_diff_key($after, $before);
        self::assertCount(7, $new);
        foreach ($new as $row) {
            self::assertNull($row['assignment'], 'a new duty is empty');
        }
        self::assertSame(7, $this->rows('SELECT COUNT(*) FROM duties WHERE added_by_change_id IS NOT NULL'));
        $this->assertAuditClean();
    }

    public function testExtendingAPublishedPeriodThenCompletingTouchesOnlyTheNewDays(): void
    {
        $period1 = $this->generatedPeriodOne(publish: true);
        // An older hole of the published line: removed by hand, it must stay a hole.
        $old = $this->db()->fetchOne("SELECT d.stable_id FROM duties d WHERE d.local_date = '2027-01-12'");
        $holder = $this->db()->fetchOne("SELECT tm.stable_id FROM duty_assignments a JOIN duties d ON d.id = a.duty_id JOIN planning_team_members tm ON tm.id = a.team_member_id WHERE a.current AND d.local_date = '2027-01-12'");
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$old}/unassign", ['expectedCurrentTeamMemberStableId' => $holder], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        // Republished so that the hole is the published state, and nothing is pending.
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/republish", [], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $publications = $this->rows('SELECT COUNT(*) FROM planning_publications');
        $notifications = $this->rows('SELECT COUNT(*) FROM planning_publication_notifications');
        $document = $this->db()->fetchOne('SELECT md5(string_agg(ROW(d.*)::text, \'|\' ORDER BY d.id)) FROM planning_publication_documents d');

        $this->patch($period1, ['endsAt' => '2027-02-08', 'expectedEndsAt' => '2027-02-01']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $before = $this->calendar();
        self::assertSame(7, \count(array_filter($before, static fn (array $d): bool => null === $d['assignment'] && $d['date'] >= '2027-02-01')));
        self::assertSame(PlanningPeriodStatus::PUBLISHED->value, $this->db()->fetchOne('SELECT pp.status FROM planning_periods pp JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE c.number = 1'));

        $plan = $this->fillPreview($this->client, $this->s)['lines'][0];
        self::assertSame(['COMPLETE', 'extension_days', true, 7, 1], [$plan['action'], $plan['reason'], $plan['extensionOnly'], $plan['uncoveredRequiredDutyCount'], $plan['otherUncoveredCount']]);

        $job = $this->fillNow($this->client, $this->s, [$plan['lineStableId']]);
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job));

        $after = $this->calendar();
        foreach ($before as $id => $row) {
            if (null !== $row['assignment'] || $row['date'] < '2027-02-01') {
                self::assertSame($row, $after[$id], 'neither an assignment nor an older hole moves');
            }
        }
        self::assertNull($after[(int) $this->db()->fetchOne("SELECT id FROM duties WHERE local_date = '2027-01-12'")]['assignment'], 'the older hole stays a hole');
        foreach ($after as $row) {
            if ($row['date'] >= '2027-02-01') {
                self::assertNotNull($row['assignment'], 'every new day is attributed');
            }
        }
        // The weekend of the extension is one block, one holder.
        self::assertSame(1, $this->rows("SELECT COUNT(DISTINCT a.team_member_id) FROM duty_assignments a JOIN duties d ON d.id = a.duty_id WHERE a.current AND d.local_date BETWEEN '2027-02-05' AND '2027-02-07'"));

        // Nothing published, nothing sent, the stored PDF the same bytes.
        self::assertSame($publications, $this->rows('SELECT COUNT(*) FROM planning_publications'));
        self::assertSame($notifications, $this->rows('SELECT COUNT(*) FROM planning_publication_notifications'));
        self::assertSame($document, $this->db()->fetchOne('SELECT md5(string_agg(ROW(d.*)::text, \'|\' ORDER BY d.id)) FROM planning_publication_documents d'));
        self::assertSame(7, $this->rows('SELECT COUNT(*) FROM duty_assignment_events e JOIN duties d ON d.id = e.duty_id WHERE e.was_published AND e.new_assignment_id IS NOT NULL AND d.added_by_change_id IS NOT NULL'), 'unpublished changes of a published line');

        // They wait for an explicit republication.
        $state = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-state?period={$period1}", token: $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(7, \count($state['unpublishedChanges'] ?? $state['changes'] ?? []));

        // Nothing left for the automatic path: the older hole never becomes completable.
        $again = $this->fillPreview($this->client, $this->s)['lines'][0];
        self::assertSame(['EXCLUDED', 'published'], [$again['action'], $again['reason']]);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM (SELECT a.duty_id FROM duty_assignments a WHERE a.current GROUP BY a.duty_id HAVING COUNT(*) > 1) x'));
    }

    public function testACompletionOfAPublishedLineWithoutExtensionIsStillRefused(): void
    {
        $this->generatedPeriodOne(publish: true);
        $plan = $this->fillPreview($this->client, $this->s)['lines'][0];
        self::assertSame(['EXCLUDED', 'published', false], [$plan['action'], $plan['reason'], $plan['extensionOnly']]);
    }

    public function testAGeneratedNextPeriodRequiresTheConfirmationOfThePlanItself(): void
    {
        $period1 = $this->generatedPeriodOne();
        $period2 = $this->createPeriod('2027-04-01');
        $job = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/fill?period={$period2}", ['lines' => [$this->lineIds($this->client, $this->s)[0]]], $this->s['creator']);
        self::assertResponseStatusCodeSame(202, json_encode($job));
        self::assertSame(1, $this->runQueuedPlanningJobs());
        $before = $this->calendar();
        $inRange = array_filter($before, static fn (array $d): bool => $d['date'] >= '2027-02-01' && $d['date'] < '2027-02-08');
        self::assertCount(7, $inRange, 'the days period 1 would take');

        $preview = $this->preview($period1, 'endsAt=2027-02-08');
        self::assertTrue($preview['confirmationRequired']);
        self::assertSame(7, $preview['retiredDutyCount']);
        self::assertSame(7, $preview['retiredAssignedCount']);
        self::assertSame(['2027-02-08', '2027-03-31'], [$preview['changes'][1]['to']['startsAt'], $preview['changes'][1]['to']['lastDay']]);
        self::assertNotEmpty($preview['holders']);

        // Without the plan's hash, or with another one: nothing moves.
        $refused = $this->patch($period1, ['endsAt' => '2027-02-08', 'expectedEndsAt' => '2027-02-01']);
        $this->assertError(409, 'date_change_confirmation_required');
        self::assertSame($preview['impactHash'], $refused['plan']['impactHash']);
        $this->patch($period1, ['endsAt' => '2027-02-08', 'expectedEndsAt' => '2027-02-01', 'confirmation' => str_repeat('a', 64)]);
        $this->assertError(409, 'date_change_confirmation_required');
        self::assertSame($before, $this->calendar());
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM period_date_changes WHERE new_ends_at = \'2027-02-08\''));

        // The calendar changed since the preview: the same hash is no longer accepted.
        $duty = $this->db()->fetchOne("SELECT d.stable_id FROM duties d WHERE d.local_date = '2027-02-02'");
        $holder = $this->db()->fetchOne("SELECT tm.stable_id FROM duty_assignments a JOIN duties d ON d.id = a.duty_id JOIN planning_team_members tm ON tm.id = a.team_member_id WHERE a.current AND d.local_date = '2027-02-02'");
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$duty}/unassign", ['expectedCurrentTeamMemberStableId' => $holder], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->patch($period1, ['endsAt' => '2027-02-08', 'expectedEndsAt' => '2027-02-01', 'confirmation' => $preview['impactHash']]);
        $this->assertError(409, 'date_change_confirmation_required');

        $fresh = $this->preview($period1, 'endsAt=2027-02-08');
        $this->patch($period1, ['endsAt' => '2027-02-08', 'expectedEndsAt' => '2027-02-01', 'confirmation' => $fresh['impactHash']]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(7, $this->rows('SELECT COUNT(*) FROM duties WHERE retired_at IS NOT NULL'), 'kept, retired');
        self::assertSame(7, $this->rows('SELECT COUNT(*) FROM duties WHERE added_by_change_id IS NOT NULL AND retired_at IS NULL'), 'period 1 has its own, empty');
        $this->assertAuditClean();
    }

    // --- Case C: shortening -----------------------------------------------------------------------

    public function testShorteningADraftPeriodConfirmedRetiresWithoutSendingAnythingAndNobodyToWarn(): void
    {
        $period1 = $this->generatedPeriodOne();
        $before = $this->calendar();
        $emails = $this->rows('SELECT COUNT(*) FROM planning_publication_notifications');

        // Thursday 14 January: the weekend block of the 15th leaves whole.
        $preview = $this->preview($period1, 'endsAt=2027-01-15');
        self::assertTrue($preview['confirmationRequired']);
        $outside = array_filter($before, static fn (array $d): bool => $d['date'] >= '2027-01-15');
        self::assertSame(\count($outside), $preview['retiredDutyCount']);
        self::assertSame(\count($outside), $preview['retiredAssignedCount']);
        self::assertFalse($preview['canNotify'], 'never published: nobody has seen those duties');
        self::assertSame('nobody_to_notify', $preview['notifyBlock']);
        self::assertSame($before, $this->calendar(), 'the preview writes nothing');

        // "Annuler" is simply no request; asking to notify is refused by the server.
        $this->patch($period1, ['endsAt' => '2027-01-15', 'expectedEndsAt' => '2027-02-01', 'confirmation' => $preview['impactHash'], 'notify' => true]);
        $this->assertError(409, 'notify_not_possible');
        self::assertSame($before, $this->calendar());

        $moved = $this->patch($period1, ['endsAt' => '2027-01-15', 'expectedEndsAt' => '2027-02-01', 'confirmation' => $preview['impactHash']]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(\count($outside), $moved['retiredDutyCount']);

        $after = $this->calendar();
        foreach ($after as $id => $row) {
            self::assertSame($before[$id], $row);
            self::assertLessThan('2027-01-15', $row['date']);
        }
        // Nothing deleted: every row is still there, the assignments superseded and recorded.
        self::assertSame(\count($outside), $this->rows('SELECT COUNT(*) FROM duties WHERE retired_at IS NOT NULL'));
        self::assertSame(\count($outside), $this->rows('SELECT COUNT(*) FROM duty_assignment_events WHERE new_assignment_id IS NULL AND previous_assignment_id IS NOT NULL'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM duty_assignments a JOIN duties d ON d.id = a.duty_id WHERE a.current AND d.retired_at IS NOT NULL'));
        self::assertSame($emails, $this->rows('SELECT COUNT(*) FROM planning_publication_notifications'));
        $journal = $this->db()->fetchAssociative('SELECT notified, impact FROM period_date_changes ORDER BY id DESC LIMIT 1');
        self::assertFalse($journal['notified']);
        self::assertSame(\count($outside), json_decode((string) $journal['impact'], true)['retiredDutyCount']);
        $this->assertAuditClean();
    }

    public function testShorteningAPublishedPeriodConfirmedKeepsThePdfAndWaitsForARepublication(): void
    {
        $period1 = $this->generatedPeriodOne(publish: true);
        $publications = $this->rows('SELECT COUNT(*) FROM planning_publications');
        $notifications = $this->rows('SELECT COUNT(*) FROM planning_publication_notifications');
        $document = $this->db()->fetchOne('SELECT md5(string_agg(ROW(d.*)::text, \'|\' ORDER BY d.id)) FROM planning_publication_documents d');

        $preview = $this->preview($period1, 'endsAt=2027-01-15');
        self::assertTrue($preview['canNotify']);
        self::assertGreaterThan(0, $preview['retiredPublishedCount']);
        self::assertNotEmpty($preview['recipients']);

        $this->patch($period1, ['endsAt' => '2027-01-15', 'expectedEndsAt' => '2027-02-01', 'confirmation' => $preview['impactHash']]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        self::assertSame($publications, $this->rows('SELECT COUNT(*) FROM planning_publications'));
        self::assertSame($notifications, $this->rows('SELECT COUNT(*) FROM planning_publication_notifications'), 'no notification');
        self::assertSame($document, $this->db()->fetchOne('SELECT md5(string_agg(ROW(d.*)::text, \'|\' ORDER BY d.id)) FROM planning_publication_documents d'), 'the diffused PDF stays what it was');
        self::assertSame((int) $preview['retiredAssignedCount'], $this->rows('SELECT COUNT(*) FROM planning_publication_entries e JOIN duties d ON d.id = e.duty_id WHERE d.retired_at IS NOT NULL'), 'the entries of the publication are history');

        // The retired duties are unpublished changes; the later republication tells exactly their holders.
        $state = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-state?period={$period1}", token: $this->s['creator']);
        self::assertSame((int) $preview['retiredAssignedCount'], \count($state['unpublishedChanges'] ?? $state['changes'] ?? []));
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/republish", [], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame($publications + 1, $this->rows('SELECT COUNT(*) FROM planning_publications'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM planning_publication_entries e JOIN duties d ON d.id = e.duty_id JOIN planning_publications p ON p.id = e.publication_id WHERE d.retired_at IS NOT NULL AND p.id = (SELECT MAX(id) FROM planning_publications)'));
        $this->assertAuditClean();
    }

    public function testShorteningAPublishedPeriodConfirmedAndWarnedRepublishesAndTellsOnlyThoseConcerned(): void
    {
        $period1 = $this->generatedPeriodOne(publish: true);
        $publications = $this->rows('SELECT COUNT(*) FROM planning_publications');
        $notifications = $this->rows('SELECT COUNT(*) FROM planning_publication_notifications');
        $concerned = $this->db()->fetchFirstColumn("SELECT DISTINCT tm.user_id FROM duty_assignments a JOIN duties d ON d.id = a.duty_id JOIN planning_team_members tm ON tm.id = a.team_member_id WHERE a.current AND d.local_date >= '2027-01-29'");
        $preview = $this->preview($period1, 'endsAt=2027-01-29');
        self::assertSame(\count($concerned), \count($preview['recipients']));

        $moved = $this->patch($period1, ['endsAt' => '2027-01-29', 'expectedEndsAt' => '2027-02-01', 'confirmation' => $preview['impactHash'], 'notify' => true]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(1, $moved['republished']);

        self::assertSame($publications + 1, $this->rows('SELECT COUNT(*) FROM planning_publications'));
        $told = array_map('intval', $this->db()->fetchFirstColumn('SELECT user_id FROM planning_publication_notifications WHERE publication_id = (SELECT MAX(id) FROM planning_publications) ORDER BY user_id'));
        $expected = array_map('intval', $concerned);
        sort($expected);
        self::assertSame($expected, $told, 'exactly those who lost a duty');
        self::assertSame($notifications + \count($concerned), $this->rows('SELECT COUNT(*) FROM planning_publication_notifications'));
        self::assertTrue((bool) $this->db()->fetchOne('SELECT notified FROM period_date_changes ORDER BY id DESC LIMIT 1'));
        self::assertSame(2, $this->rows('SELECT COUNT(*) FROM planning_publication_documents') - ($publications - 1), 'one stored PDF per diffusion');
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM planning_publication_entries e JOIN duties d ON d.id = e.duty_id WHERE d.retired_at IS NOT NULL AND e.publication_id = (SELECT MAX(id) FROM planning_publications)'));
        $this->assertAuditClean();
    }

    public function testWarningIsRefusedWhileOtherChangesWaitAndNothingMoves(): void
    {
        $period1 = $this->generatedPeriodOne(publish: true);
        $duty = $this->db()->fetchOne("SELECT d.stable_id FROM duties d WHERE d.local_date = '2027-01-12'");
        $holder = $this->db()->fetchOne("SELECT tm.stable_id FROM duty_assignments a JOIN duties d ON d.id = a.duty_id JOIN planning_team_members tm ON tm.id = a.team_member_id WHERE a.current AND d.local_date = '2027-01-12'");
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$duty}/unassign", ['expectedCurrentTeamMemberStableId' => $holder], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $before = $this->calendar();

        $preview = $this->preview($period1, 'endsAt=2027-01-29');
        self::assertFalse($preview['canNotify']);
        self::assertSame('pending_changes', $preview['notifyBlock']);
        self::assertSame(1, $preview['pendingChanges']);

        $this->patch($period1, ['endsAt' => '2027-01-29', 'expectedEndsAt' => '2027-02-01', 'confirmation' => $preview['impactHash'], 'notify' => true]);
        $this->assertError(409, 'notify_not_possible');
        self::assertSame($before, $this->calendar());
        self::assertSame('2027-02-01', $this->periods()[0]['endsAt']);
    }

    // --- blocks, concurrency, rights, re-extension ------------------------------------------------

    public function testABoundaryNeverCutsABlockEvenOneOfAStructureChangedSince(): void
    {
        $period1 = $this->generatedPeriodOne();

        // Friday 15 January: cuts the weekend block.
        $this->patch($period1, ['endsAt' => '2027-01-16', 'expectedEndsAt' => '2027-02-01']);
        $this->assertError(422, 'period_boundary_splits_block');
        $reasons = $this->preview($period1, 'endsAt=2027-01-16')['reasons'];
        self::assertSame('period_boundary_splits_block', $reasons[0]['code']);
        self::assertSame('Ven · Sam · Dim', $reasons[0]['cuts'][0]['blockName']);

        // No block in the structure any more, but the weekend of 15-17 January exists in the calendar.
        $line = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][0]['stableId'];
        $this->api($this->client, 'PUT', "/api/planning-lines/{$line}/week-structure", ['blocks' => [], 'solo' => ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'], 'soloFamily' => '', 'excluded' => []], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->patch($period1, ['endsAt' => '2027-01-16', 'expectedEndsAt' => '2027-02-01']);
        $this->assertError(409, 'materialized_block_crosses_boundary');
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM duties WHERE retired_at IS NOT NULL'));
    }

    public function testASecondIdenticalRequestIsRefusedAndChangesNothing(): void
    {
        $period1 = $this->generatedPeriodOne();
        $preview = $this->preview($period1, 'endsAt=2027-01-15');
        $body = ['endsAt' => '2027-01-15', 'expectedEndsAt' => '2027-02-01', 'confirmation' => $preview['impactHash']];

        $this->patch($period1, $body);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $after = $this->calendar();
        $journal = $this->rows('SELECT COUNT(*) FROM period_date_changes');

        $this->patch($period1, $body); // the double click
        $this->assertError(409, 'period_changed');
        self::assertSame($after, $this->calendar());
        self::assertSame($journal, $this->rows('SELECT COUNT(*) FROM period_date_changes'));
    }

    public function testExtendingAgainAfterAShorteningCreatesNewDutiesAndTheOldOnesStayRetired(): void
    {
        $period1 = $this->generatedPeriodOne();
        $preview = $this->preview($period1, 'endsAt=2027-01-25');
        $this->patch($period1, ['endsAt' => '2027-01-25', 'expectedEndsAt' => '2027-02-01', 'confirmation' => $preview['impactHash']]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $retired = $this->rows('SELECT COUNT(*) FROM duties WHERE retired_at IS NOT NULL');
        self::assertGreaterThan(0, $retired);

        $moved = $this->patch($period1, ['endsAt' => '2027-02-01', 'expectedEndsAt' => '2027-01-25']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame($retired, $moved['addedDutyCount'], 'the same days, new empty duties');
        self::assertSame($retired, $this->rows('SELECT COUNT(*) FROM duties WHERE retired_at IS NOT NULL'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM (SELECT d.local_date FROM duties d WHERE d.retired_at IS NULL GROUP BY d.local_date HAVING COUNT(*) > 1) x'), 'never two duties a day');
    }

    public function testTheJournalIsAppendOnlyAndOnlyTheCreatorMovesDates(): void
    {
        $period1 = $this->periods()[0]['stableId'];
        $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods/{$period1}/date-change-preview?endsAt=2027-04-01", token: $this->s['alice']);
        self::assertResponseStatusCodeSame(403);
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-04-01', 'expectedEndsAt' => '2027-05-01'], $this->s['alice']);
        self::assertResponseStatusCodeSame(403);

        $this->patch($period1, ['endsAt' => '2027-04-01', 'expectedEndsAt' => '2027-05-01']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        foreach (['UPDATE period_date_changes SET notified = TRUE', 'DELETE FROM period_date_changes'] as $sql) {
            try {
                $this->db()->executeStatement('SAVEPOINT append_only');
                $this->db()->executeStatement($sql);
                self::fail('append-only: '.$sql);
            } catch (\Doctrine\DBAL\Exception\DriverException $exception) {
                self::assertStringContainsString('append-only', $exception->getMessage());
                $this->db()->executeStatement('ROLLBACK TO SAVEPOINT append_only');
            }
        }
    }

    public function testNoDateMovesDuringACalculation(): void
    {
        $period1 = $this->periods()[0]['stableId'];
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", [], $this->s['creator']);
        self::assertResponseStatusCodeSame(202);
        $this->patch($period1, ['endsAt' => '2027-06-01', 'expectedEndsAt' => '2027-05-01']);
        $this->assertError(409, 'job_in_progress');
    }

    public function testADiffusionWithoutStoredPdfStillShowsWhatItAnnouncedAfterAShortening(): void
    {
        $period1 = $this->generatedPeriodOne(publish: true);
        $publicationId = (int) $this->db()->fetchOne('SELECT MAX(id) FROM planning_publications');
        $preview = $this->preview($period1, 'endsAt=2027-01-15');
        $this->patch($period1, ['endsAt' => '2027-01-15', 'expectedEndsAt' => '2027-02-01', 'confirmation' => $preview['impactHash']]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $container = static::getContainer();
        $publication = $container->get(\App\Repository\PlanningPublicationRepository::class)->find($publicationId);
        $view = $container->get(\App\Service\PlanningPdfRenderer::class)->legacyView($publication);
        self::assertStringContainsString('31 janvier 2027', json_encode($view, \JSON_UNESCAPED_UNICODE), 'the days it announced, not the new dates');
    }

    public function testTheMigrationCannotBeUndoneOnceAChangeWasRecorded(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20261104090000.php';
        $period1 = $this->periods()[0]['stableId'];
        $this->patch($period1, ['endsAt' => '2027-04-01', 'expectedEndsAt' => '2027-05-01']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $this->expectException(\Doctrine\Migrations\Exception\AbortMigration::class);
        (new \DoctrineMigrations\Version20261104090000($this->db(), new \Psr\Log\NullLogger()))->down(new \Doctrine\DBAL\Schema\Schema());
    }
}
