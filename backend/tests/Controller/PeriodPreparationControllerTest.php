<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PreparationActionState;
use App\Repository\PeriodPreparationReminderRepository;
use App\Repository\PeriodPreparationRepository;
use App\Repository\PlanningCycleRepository;
use App\Repository\PlanningRepository;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D210 — « Préparer la période »: planning the
 * announcement, the target generation date and the reminders of a period.
 *
 * Fixture (pilotScenario): planning 2027-01-01 → 2027-05-01, period 1 with its
 * collection open; period 2 (2027-05-01 → 2027-07-31) is created by the tests
 * that need a period without collection.
 */
final class PeriodPreparationControllerTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
    }

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
    private function save(string $period, array $body, ?string $token = null): array
    {
        return $this->api($this->client, 'PUT', "/api/plannings/{$this->s['planningId']}/periods/{$period}/preparation", $body + ['announceOn' => null, 'targetGenerationOn' => null, 'reminders' => []], $token ?? $this->s['creator']);
    }

    /**
     * @return array<string, mixed>
     */
    private function show(string $period, ?string $token = null): array
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods/{$period}/preparation", token: $token ?? $this->s['creator']);
    }

    private function assertError(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());
        self::assertSame($code, json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? null, (string) $this->client->getResponse()->getContent());
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    // --- reading, rights ----------------------------------------------------------------------------

    public function testANewPeriodPlansNothingAndSendsNoEmail(): void
    {
        $period = $this->createPeriod2();

        self::assertEmailCount(0);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM period_preparations'));
        $view = $this->show($period);
        self::assertResponseIsSuccessful();
        self::assertSame(['stableId' => $period, 'number' => 2, 'startsAt' => '2027-05-01', 'lastDay' => '2027-07-31'], $view['period']);
        self::assertSame('none', $view['announcement']['state']);
        self::assertNull($view['announcement']['on']);
        self::assertNull($view['targetGenerationOn']);
        self::assertSame([], $view['reminders']);
        self::assertSame(['state' => 'not_open', 'responded' => 0, 'expected' => 0], $view['collection']);
        self::assertSame('Europe/Brussels', $view['timezone']);
        self::assertSame('08:00', $view['sendTime']);
        self::assertTrue($view['editable']);
    }

    public function testOnlyThePeopleWhoManageAvailabilityPrepareAPeriod(): void
    {
        $period = $this->createPeriod2();

        $this->show($period, $this->s['alice']);
        self::assertResponseStatusCodeSame(403);
        $this->save($period, ['targetGenerationOn' => '2027-04-01'], $this->s['alice']);
        self::assertResponseStatusCodeSame(403);
        $this->show($period, $this->s['outsider']);
        self::assertResponseStatusCodeSame(403);
        $this->show($period, $this->s['admin']);
        self::assertResponseIsSuccessful();

        $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods/00000000-0000-7000-8000-000000000000/preparation", token: $this->s['creator']);
        self::assertResponseStatusCodeSame(404);
    }

    // --- planning -----------------------------------------------------------------------------------

    public function testPlansAnAnnouncementATargetAndRemindersAt8InBrussels(): void
    {
        $period = $this->createPeriod2();

        $view = $this->save($period, [
            'announceOn' => '2027-03-01',
            'targetGenerationOn' => '2027-04-01',
            'reminders' => [['value' => 3, 'unit' => 'DAY'], ['value' => 2, 'unit' => 'WEEK']],
        ]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertEmailCount(0);

        self::assertSame(['on' => '2027-03-01', 'state' => 'scheduled'], ['on' => $view['announcement']['on'], 'state' => $view['announcement']['state']]);
        self::assertSame('2027-04-01', $view['targetGenerationOn']);
        self::assertSame(
            [['WEEK', 2, '2027-03-18', 'scheduled'], ['DAY', 3, '2027-03-29', 'scheduled']],
            array_map(static fn (array $r): array => [$r['unit'], $r['value'], $r['dueOn'], $r['state']], $view['reminders']),
        );

        // 08:00 Europe/Brussels: 07:00 UTC in winter (1 March), 06:00 UTC in summer (29 March, after the change).
        $db = $this->db();
        self::assertSame('2027-03-01 07:00:00', $db->fetchOne('SELECT announce_at::text FROM period_preparations'));
        self::assertSame(['2027-03-18 07:00:00', '2027-03-29 06:00:00'], $db->fetchFirstColumn('SELECT due_at::text FROM period_preparation_reminders ORDER BY due_at'));
        // Planned is not contacted: the collection rules of D208 see nothing.
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM period_preparation_deliveries'));
    }

    public function testRefusesWhatCannotBePlanned(): void
    {
        $period = $this->createPeriod2();
        $cases = [
            'announcement_in_past' => ['announceOn' => '2026-01-05'],
            'announcement_after_period_end' => ['announceOn' => '2027-08-01'],
            'target_in_past' => ['targetGenerationOn' => '2026-01-05'],
            'target_after_period_end' => ['targetGenerationOn' => '2027-08-01'],
            'target_before_announcement' => ['announceOn' => '2027-03-01', 'targetGenerationOn' => '2027-03-01'],
            'reminders_need_target' => ['reminders' => [['value' => 3, 'unit' => 'DAY']]],
            'invalid_reminder' => ['targetGenerationOn' => '2027-04-01', 'reminders' => [['value' => 0, 'unit' => 'DAY']]],
            'duplicate_reminder' => ['targetGenerationOn' => '2027-04-01', 'reminders' => [['value' => 7, 'unit' => 'DAY'], ['value' => 1, 'unit' => 'WEEK']]],
            'reminder_before_announcement' => ['announceOn' => '2027-03-01', 'targetGenerationOn' => '2027-04-01', 'reminders' => [['value' => 31, 'unit' => 'DAY']]],
            'reminder_in_past' => ['targetGenerationOn' => '2027-04-01', 'reminders' => [['value' => 52, 'unit' => 'WEEK']]],
            'invalid_date' => ['announceOn' => '2027-02-30'],
        ];
        foreach ($cases as $code => $body) {
            $this->save($period, $body);
            $this->assertError(422, $code);
        }
        foreach ([['value' => 366, 'unit' => 'DAY'], ['value' => 53, 'unit' => 'WEEK'], ['value' => 1, 'unit' => 'MONTH'], ['value' => '3', 'unit' => 'DAY']] as $reminder) {
            $this->save($period, ['targetGenerationOn' => '2027-04-01', 'reminders' => [$reminder]]);
            $this->assertError(422, 'invalid_reminder');
        }
        $this->save($period, ['targetGenerationOn' => '2027-04-01', 'reminders' => array_map(static fn (int $d): array => ['value' => $d, 'unit' => 'DAY'], range(1, 11))]);
        $this->assertError(422, 'too_many_reminders');

        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM period_preparations'), 'A refused save writes nothing.');
    }

    public function testChangesAndRemovesRemindersNotSentAndFollowsTheTargetDate(): void
    {
        $period = $this->createPeriod2();
        $view = $this->save($period, ['targetGenerationOn' => '2027-04-01', 'reminders' => [['value' => 2, 'unit' => 'WEEK'], ['value' => 3, 'unit' => 'DAY'], ['value' => 1, 'unit' => 'DAY']]]);
        [$twoWeeks, $threeDays] = $view['reminders'];

        // The two reminders exchange their days; the third is removed; one is added.
        $view = $this->save($period, ['targetGenerationOn' => '2027-04-01', 'reminders' => [
            ['stableId' => $twoWeeks['stableId'], 'value' => 3, 'unit' => 'DAY'],
            ['stableId' => $threeDays['stableId'], 'value' => 2, 'unit' => 'WEEK'],
            ['value' => 10, 'unit' => 'DAY'],
        ]]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(
            [[$threeDays['stableId'], '2027-03-18'], [null, '2027-03-22'], [$twoWeeks['stableId'], '2027-03-29']],
            array_map(static fn (array $r): array => [\in_array($r['stableId'], [$twoWeeks['stableId'], $threeDays['stableId']], true) ? $r['stableId'] : null, $r['dueOn']], $view['reminders']),
        );

        // Moving the target date moves every reminder not sent, offsets kept.
        $view = $this->save($period, ['targetGenerationOn' => '2027-04-15', 'reminders' => array_map(static fn (array $r): array => ['stableId' => $r['stableId'], 'value' => $r['value'], 'unit' => $r['unit']], $view['reminders'])]);
        self::assertSame(['2027-04-01', '2027-04-05', '2027-04-12'], array_column($view['reminders'], 'dueOn'));

        $this->save($period, ['targetGenerationOn' => '2027-04-15', 'reminders' => [['stableId' => '00000000-0000-7000-8000-000000000000', 'value' => 1, 'unit' => 'DAY']]]);
        $this->assertError(422, 'reminder_not_found');

        // Cancelling: no announcement, no reminders.
        $view = $this->save($period, ['targetGenerationOn' => '2027-04-15']);
        self::assertSame([], $view['reminders']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM period_preparation_reminders'));
    }

    public function testAnAnnouncementNotRunYetMovesOrIsCancelled(): void
    {
        $period = $this->createPeriod2();
        $this->save($period, ['announceOn' => '2027-03-01']);
        $view = $this->save($period, ['announceOn' => '2027-03-08']);
        self::assertSame(['2027-03-08', 'scheduled'], [$view['announcement']['on'], $view['announcement']['state']]);
        self::assertSame('2027-03-08 07:00:00', $this->db()->fetchOne('SELECT announce_at::text FROM period_preparations'));

        $view = $this->save($period, ['announceOn' => null]);
        self::assertSame(['on' => null, 'state' => 'none'], ['on' => $view['announcement']['on'], 'state' => $view['announcement']['state']]);
    }

    public function testAnAnnouncementThatRanNeverChanges(): void
    {
        $period = $this->createPeriod2();
        $this->save($period, ['announceOn' => '2027-03-01', 'targetGenerationOn' => '2027-04-01']);
        $this->db()->executeStatement("UPDATE period_preparations SET announcement_state = 'EXECUTED', announcement_outcome = 'collection_opened', announcement_executed_at = NOW()");

        $this->save($period, ['announceOn' => '2027-03-02', 'targetGenerationOn' => '2027-04-01']);
        $this->assertError(409, 'announcement_already_done');
        $view = $this->save($period, ['announceOn' => '2027-03-01', 'targetGenerationOn' => '2027-04-05']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(['executed', '2027-04-05'], [$view['announcement']['state'], $view['targetGenerationOn']]);
    }

    public function testAReminderThatRanIsHistoryAndCannotBeEdited(): void
    {
        $period = $this->createPeriod2();
        $view = $this->save($period, ['targetGenerationOn' => '2027-04-01', 'reminders' => [['value' => 1, 'unit' => 'WEEK'], ['value' => 2, 'unit' => 'DAY']]]);
        $ran = $view['reminders'][0];
        $this->db()->executeStatement("UPDATE period_preparation_reminders SET state = 'EXECUTED', outcome = 'sent_to_pending', executed_at = NOW() WHERE stable_id = :id", ['id' => $ran['stableId']]);

        $this->save($period, ['targetGenerationOn' => '2027-04-01', 'reminders' => [['stableId' => $ran['stableId'], 'value' => 2, 'unit' => 'WEEK']]]);
        $this->assertError(409, 'reminder_not_editable');

        // Left out of the list, it stays: only scheduled reminders are removed; the target moves, it does not.
        $view = $this->save($period, ['targetGenerationOn' => '2027-04-10']);
        self::assertSame([['2027-03-25', 'executed']], array_map(static fn (array $r): array => [$r['dueOn'], $r['state']], $view['reminders']));
    }

    public function testDeletingTheLastPeriodForgetsItsPreparation(): void
    {
        $period = $this->createPeriod2();
        $this->save($period, ['announceOn' => '2027-03-01', 'targetGenerationOn' => '2027-04-01', 'reminders' => [['value' => 1, 'unit' => 'WEEK']]]);

        $this->api($this->client, 'DELETE', "/api/plannings/{$this->s['planningId']}/periods/{$period}?expected={$period}", token: $this->s['creator']);
        self::assertResponseStatusCodeSame(204, (string) $this->client->getResponse()->getContent());
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM period_preparations'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM period_preparation_reminders'));
    }

    /**
     * docs/decisions.md D210: once the generation is launched, the reminders
     * not sent are cancelled and the preparation no longer changes — the
     * target date never started anything by itself.
     */
    public function testLaunchingTheGenerationCancelsTheRemindersNotSent(): void
    {
        $period = $this->period1();
        $this->save($period, ['targetGenerationOn' => '2026-12-15', 'reminders' => [['value' => 2, 'unit' => 'WEEK'], ['value' => 3, 'unit' => 'DAY']]]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $this->prepareLine($this->s['planningId'], [['2027-01-04 08:00', '2027-01-05 08:00']]);
        $this->generate($this->client, $this->s);

        $view = $this->show($period);
        self::assertSame([['cancelled', 'generation_started'], ['cancelled', 'generation_started']], array_map(static fn (array $r): array => [$r['state'], $r['outcome']], $view['reminders']));
        self::assertFalse($view['editable']);
        self::assertContains('period_generation_started', $view['blockers']);
        $this->save($period, ['targetGenerationOn' => '2026-12-20']);
        $this->assertError(409, 'period_generation_started');

        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $preparation = static::getContainer()->get(PeriodPreparationRepository::class)->findOneByCycle(static::getContainer()->get(PlanningCycleRepository::class)->findByPlanning($planning)[0]);
        foreach (static::getContainer()->get(PeriodPreparationReminderRepository::class)->findByPreparation($preparation) as $reminder) {
            self::assertSame(PreparationActionState::CANCELLED, $reminder->getState());
        }
    }
}
