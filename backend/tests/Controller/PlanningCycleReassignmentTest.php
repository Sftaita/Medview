<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DutyType;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\UserAvailabilityType;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\UserRepository;
use App\Service\DutyMaterializationService;
use App\Service\DutyReassignmentService;
use App\Service\PlanningRuleSetService;
use App\Service\UserAvailabilityService;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\FaultInjectingPlanningSolver;
use App\Tests\SqlRecorder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Reassignment, removal, candidates and completion on a planning with
 * several periods, lot L4d (docs/decisions.md D197), through the real
 * endpoints, the real job runner and the real OR-Tools solver.
 *
 * Fixture (pilotScenario): planning 2027-01-01 → 2027-05-01 (period 1, ends
 * on a Saturday), primary line "Seniors" with admin/alice/bob,
 * Europe/Brussels; period 2 runs to 2027-08-01. The rest policy, when used,
 * is LEGAL_MIN_REST 11 h. The night of the last day of period 1 (Friday 30
 * April 20:00 → Saturday 1 May 08:00) is a duty of period 1: its local date
 * is 30 April.
 */
final class PlanningCycleReassignmentTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private const REST = ['legalMinRestEnabled' => true, 'legalMinRestHours' => 11];
    private const MEMBERS = ['admin@example.com', 'alice@example.com', 'bob@example.com'];
    private const NIGHT = ['2027-04-30 20:00', '2027-05-01 08:00'];

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    private bool $prepared = false;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
    }

    protected function tearDown(): void
    {
        FaultInjectingPlanningSolver::reset();
        SqlRecorder::stop();
        parent::tearDown();
    }

    // --- helpers ---------------------------------------------------------------------------------

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function periodId(int $number): string
    {
        return (string) $this->db()->fetchOne('SELECT c.stable_id FROM planning_cycles c JOIN plannings p ON p.id = c.planning_id WHERE p.stable_id = ? AND c.number = ?', [$this->s['planningId'], $number]);
    }

    private function createPeriod(string $endsAt = '2027-08-01'): void
    {
        $periods = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'];
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", [
            'endsAt' => $endsAt,
            'previousPeriodStableId' => $periods[\count($periods) - 1]['stableId'],
        ], $this->s['creator']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** The primary line's period in period $number, read fresh. */
    private function periodOf(int $number): PlanningPeriod
    {
        $id = $this->db()->fetchOne(<<<'SQL'
            SELECT pp.id FROM planning_periods pp
            JOIN planning_cycles c ON c.id = pp.planning_cycle_id JOIN plannings p ON p.id = c.planning_id
            WHERE p.stable_id = ? AND c.number = ? AND pp.planning_line_id IS NOT NULL
        SQL, [$this->s['planningId'], $number]);
        self::assertNotFalse($id, "No period {$number}.");
        $this->em()->clear();

        return $this->em()->find(PlanningPeriod::class, $id) ?? self::fail("No period {$number}.");
    }

    /**
     * Standalone duties ([local start, local end], Europe/Brussels wall clock) in period $number.
     *
     * @param list<array{0: string, 1: string}> $duties
     */
    private function addDuties(int $number, array $duties): void
    {
        $period = $this->periodOf($number);
        $team = $period->getTeam();
        if (!$this->prepared) {
            $this->activateRuleSet(static::getContainer()->get(PlanningRuleSetService::class), $team);
            $this->prepared = true;
        }
        $dutyType = $this->em()->getRepository(DutyType::class)->findOneBy(['team' => $team, 'code' => 'ONCALL']) ?? $this->createDutyType($this->em(), $team);
        $period = $this->em()->find(PlanningPeriod::class, $period->getId());
        foreach ($duties as [$from, $to]) {
            static::getContainer()->get(DutyMaterializationService::class)->createStandaloneDuty($period, $dutyType, new \DateTimeImmutable($from), new \DateTimeImmutable($to));
        }
    }

    /** @param array<string, mixed> $body */
    private function generatePeriod(int $number, array $body = []): void
    {
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", ['period' => $this->periodId($number)] + $body, $this->s['creator']);
        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->runQueuedPlanningJobs());
        $job = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/jobs/latest?period={$this->periodId($number)}", token: $this->s['creator'])['job'];
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
    }

    private function publish(int $number): void
    {
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", ['period' => $this->periodId($number)], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    /** The stableId of the duty of period $number on $localDate. */
    private function dutyIn(int $number, string $localDate): string
    {
        foreach (static::getContainer()->get(DutyRepository::class)->findByPlanningPeriod($this->periodOf($number)) as $duty) {
            if ($duty->getLocalDate()->format('Y-m-d') === $localDate) {
                return (string) $duty->getStableId();
            }
        }

        self::fail("No duty on {$localDate} in period {$number}.");
    }

    /** The current holder (email) of the duty of period $number on $localDate, or null when uncovered. */
    private function holderOn(int $number, string $localDate): ?string
    {
        $email = $this->db()->fetchOne(<<<'SQL'
            SELECT u.email FROM duty_assignments a
            JOIN duties d ON d.id = a.duty_id JOIN planning_team_members tm ON tm.id = a.team_member_id JOIN users u ON u.id = tm.user_id
            WHERE d.stable_id = ? AND a.current
        SQL, [$this->dutyIn($number, $localDate)]);

        return false === $email ? null : (string) $email;
    }

    /** @return array<string, mixed> */
    private function candidates(string $dutyStableId, ?string $token = null): array
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/duties/{$dutyStableId}/reassignment-candidates", token: $token ?? $this->s['creator']);
    }

    /** @return list<string> the candidates' emails */
    private function candidateEmails(string $dutyStableId): array
    {
        $byMember = [];
        foreach (self::MEMBERS as $email) {
            $byMember[$this->memberStableIdIn($this->s['planningId'], $email)] = $email;
        }

        return array_values(array_map(static fn (array $c): string => $byMember[$c['teamMemberStableId']], $this->candidates($dutyStableId)['candidates']));
    }

    /** @return array<string, mixed> */
    private function reassign(string $dutyStableId, string $email, ?string $token = null): array
    {
        return $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$dutyStableId}/reassign", [
            'teamMemberStableId' => $this->memberStableIdIn($this->s['planningId'], $email),
            'expectedCurrentTeamMemberStableId' => $this->candidates($dutyStableId)['currentTeamMemberStableId'] ?? null,
        ], $token ?? $this->s['creator']);
    }

    private function someoneElse(string ...$excluded): string
    {
        foreach (self::MEMBERS as $email) {
            if (!\in_array($email, $excluded, true)) {
                return $email;
            }
        }

        self::fail('Nobody else.');
    }

    /** Unavailable for whole UTC instants (the availability calendar stores instants). */
    private function unavailable(string $email, string $fromUtc, string $toUtc): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
        $this->addAvailability(static::getContainer()->get(UserAvailabilityService::class), $user, UserAvailabilityType::UNAVAILABLE, $fromUtc.' UTC', $toUtc.' UTC');
    }

    /**
     * Everything a period holds, hashed (same as PlanningCycleGenerationTest::fingerprint()), events included.
     */
    private function fingerprint(int $number): string
    {
        $cycleId = $this->db()->fetchOne('SELECT c.id FROM planning_cycles c JOIN plannings p ON p.id = c.planning_id WHERE p.stable_id = ? AND c.number = ?', [$this->s['planningId'], $number]);

        return (string) $this->db()->fetchOne(<<<'SQL'
            SELECT md5(string_agg(x, '|' ORDER BY x)) FROM (
              SELECT 'y' || ROW(cy.*)::text AS x FROM planning_cycles cy WHERE cy.id = :c
              UNION ALL SELECT 'p' || ROW(pp.*)::text FROM planning_periods pp WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'd' || ROW(d.*)::text FROM duties d JOIN planning_periods pp ON pp.id = d.planning_period_id WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'g' || ROW(g.*)::text FROM planning_generations g JOIN planning_periods pp ON pp.id = g.planning_period_id WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'a' || ROW(a.*)::text FROM duty_assignments a JOIN duties d ON d.id = a.duty_id JOIN planning_periods pp ON pp.id = d.planning_period_id WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'e' || ROW(e.*)::text FROM duty_assignment_events e JOIN duties d ON d.id = e.duty_id JOIN planning_periods pp ON pp.id = d.planning_period_id WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'u' || ROW(u.*)::text FROM planning_publications u WHERE u.planning_cycle_id = :c
            ) s
        SQL, ['c' => $cycleId]);
    }

    /** Every publication row, entry, line, document, notification and delivery, hashed (lot L4c, D196). */
    private function publicationFingerprint(): string
    {
        $parts = [];
        foreach (['planning_publications', 'planning_publication_entries', 'planning_publication_lines', 'planning_publication_documents', 'planning_publication_notifications', 'planning_publication_deliveries'] as $table) {
            $parts[] = $table.':'.$this->db()->fetchOne("SELECT COUNT(*) || '/' || COALESCE(md5(string_agg(ROW(t.*)::text, '|' ORDER BY ROW(t.*)::text)), '') FROM {$table} t");
        }

        return implode(' ', $parts);
    }

    private function assertError(int $status, string $code, ?string $reason = null): void
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());
        self::assertSame($code, $body['error'] ?? null);
        if (null !== $reason) {
            self::assertSame($reason, $body['reason'] ?? null);
        }
    }

    /** @return list<array<string, mixed>> the events of the duty, oldest first */
    private function eventsOf(string $dutyStableId): array
    {
        return $this->db()->fetchAllAssociative(<<<'SQL'
            SELECT e.was_published, e.previous_assignment_id IS NOT NULL AS had_previous, e.new_assignment_id IS NOT NULL AS has_new,
                   u.email AS author, c.number AS generation_period
            FROM duty_assignment_events e
            JOIN duties d ON d.id = e.duty_id JOIN users u ON u.id = e.author_id
            JOIN planning_generations g ON g.id = e.generation_id JOIN planning_periods pp ON pp.id = g.planning_period_id
            JOIN planning_cycles c ON c.id = pp.planning_cycle_id
            WHERE d.stable_id = ? ORDER BY e.id
        SQL, [$dutyStableId]);
    }

    // --- the period is the duty's own ------------------------------------------------------------

    public function testADutyOfPeriodTwoIsReassignedAndNothingOfPeriodOneChanges(): void
    {
        $this->createPeriod();
        $this->addDuties(1, [['2027-04-27 08:00', '2027-04-27 20:00']]);
        $this->addDuties(2, [['2027-06-01 08:00', '2027-06-01 20:00']]);
        $this->generatePeriod(1);
        $this->generatePeriod(2);
        $period1 = $this->fingerprint(1);
        $duty = $this->dutyIn(2, '2027-06-01');
        $before = $this->holderOn(2, '2027-06-01');

        $view = $this->candidates($duty);
        self::assertResponseIsSuccessful();
        self::assertSame(['stableId' => $this->periodId(2), 'number' => 2], $view['period'], 'deduced from the duty — no parameter');
        $generation2 = static::getContainer()->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($this->periodOf(2));
        self::assertSame((string) $generation2->getStableId(), $view['generationStableId']);

        $after = $this->someoneElse($before);
        $response = $this->reassign($duty, $after);

        self::assertResponseIsSuccessful();
        self::assertSame('reassigned', $response['status']);
        self::assertSame(2, $response['period']['number']);
        self::assertSame($after, $this->holderOn(2, '2027-06-01'));
        self::assertSame([['was_published' => false, 'had_previous' => true, 'has_new' => true, 'author' => 'creator@example.com', 'generation_period' => 2]], $this->eventsOf($duty));
        self::assertSame($period1, $this->fingerprint(1), 'period 1 is only read');
    }

    public function testAPlanningWithASinglePeriodAnswersAsBefore(): void
    {
        $this->addDuties(1, [['2027-02-02 08:00', '2027-02-02 20:00']]);
        $this->generatePeriod(1);
        $duty = $this->dutyIn(1, '2027-02-02');
        $before = $this->holderOn(1, '2027-02-02');

        self::assertSame(1, $this->candidates($duty)['period']['number']);
        $response = $this->reassign($duty, $this->someoneElse($before));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $response['period']['number']);
        $view = $this->candidates($duty);
        $removed = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$duty}/unassign", ['expectedCurrentTeamMemberStableId' => $view['currentTeamMemberStableId']], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'unassigned', 'period' => ['stableId' => $this->periodId(1), 'number' => 1], 'dependentImpacts' => []], $removed);
    }

    // --- live checks across the boundary ---------------------------------------------------------

    public function testTheRestWithTheOtherPeriodIsCheckedBothWays(): void
    {
        $this->createPeriod();
        $this->addDuties(1, [self::NIGHT]);
        $this->addDuties(2, [['2027-05-01 08:00', '2027-05-01 20:00']]);
        $this->generatePeriod(1, self::REST);
        $this->generatePeriod(2, self::REST);
        $night = $this->dutyIn(1, '2027-04-30');
        $morning = $this->dutyIn(2, '2027-05-01');
        $nightHolder = $this->holderOn(1, '2027-04-30');
        $morningHolder = $this->holderOn(2, '2027-05-01');
        self::assertNotNull($nightHolder);
        self::assertNotNull($morningHolder);
        self::assertNotSame($nightHolder, $morningHolder, 'the generations kept the rest');
        $third = $this->someoneElse($nightHolder, $morningHolder);
        $fingerprints = [$this->fingerprint(1), $this->fingerprint(2)];

        // Period 2, right after the night of period 1.
        self::assertNotContains($nightHolder, $this->candidateEmails($morning), 'absent, never shown disabled');
        $this->reassign($morning, $nightHolder);
        $this->assertError(409, 'invalid_candidate', 'CROSS_LINE_LEGAL_MIN_REST');

        // Period 1, right before the morning of period 2: the same rule, the other way.
        self::assertNotContains($morningHolder, $this->candidateEmails($night));
        $this->reassign($night, $morningHolder);
        $this->assertError(409, 'invalid_candidate', 'CROSS_LINE_LEGAL_MIN_REST');

        self::assertSame($fingerprints, [$this->fingerprint(1), $this->fingerprint(2)], 'a refusal writes nothing');

        // Someone free on both sides may take either.
        self::assertContains($third, $this->candidateEmails($morning));
        self::assertContains($third, $this->candidateEmails($night));
        $response = $this->reassign($night, $third);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $response['period']['number'], 'the night of 30 April is a duty of period 1');
    }

    public function testANightOfPeriodOneOverlapsTheNextMorningEvenWithoutRestPolicy(): void
    {
        $this->createPeriod();
        $this->addDuties(1, [self::NIGHT]);
        // Starts before the night of period 1 ends: an overlap, a HARD conflict whatever the rest policy.
        $this->addDuties(2, [['2027-05-01 06:00', '2027-05-01 14:00']]);
        $this->generatePeriod(1);
        $this->generatePeriod(2);
        $nightHolder = $this->holderOn(1, '2027-04-30');
        $morningHolder = $this->holderOn(2, '2027-05-01');
        self::assertNotSame($nightHolder, $morningHolder);

        self::assertNotContains($nightHolder, $this->candidateEmails($this->dutyIn(2, '2027-05-01')));
        $this->reassign($this->dutyIn(2, '2027-05-01'), $nightHolder);
        $this->assertError(409, 'invalid_candidate', 'CROSS_LINE_CONFLICT');

        $this->reassign($this->dutyIn(1, '2027-04-30'), $morningHolder);
        $this->assertError(409, 'invalid_candidate', 'CROSS_LINE_CONFLICT');
    }

    public function testWithoutRestPolicyTheSamePersonMayFollowAcrossTheBoundary(): void
    {
        $this->createPeriod();
        $this->addDuties(1, [self::NIGHT]);
        $this->addDuties(2, [['2027-05-01 08:00', '2027-05-01 20:00']]);
        $this->generatePeriod(1);
        $this->generatePeriod(2);
        $nightHolder = $this->holderOn(1, '2027-04-30');
        $morning = $this->dutyIn(2, '2027-05-01');
        if ($nightHolder === $this->holderOn(2, '2027-05-01')) {
            $this->reassign($morning, $this->someoneElse($nightHolder));
            self::assertResponseIsSuccessful();
        }

        // Back to back, no overlap, no rest rule: allowed — the refusals above are the rest's and the overlap's.
        self::assertContains($nightHolder, $this->candidateEmails($morning));
        $this->reassign($morning, $nightHolder);
        self::assertResponseIsSuccessful();
        self::assertSame($nightHolder, $this->holderOn(2, '2027-05-01'));
    }

    public function testAnUnavailabilityOverTheBoundaryIsReadForTheDutyOfEachPeriod(): void
    {
        $this->createPeriod();
        $this->addDuties(1, [self::NIGHT]);
        $this->addDuties(2, [['2027-05-01 08:00', '2027-05-01 20:00']]);
        $this->generatePeriod(1);
        $this->generatePeriod(2);
        $free = $this->someoneElse($this->holderOn(1, '2027-04-30'), $this->holderOn(2, '2027-05-01'));
        self::assertContains($free, $this->candidateEmails($this->dutyIn(2, '2027-05-01')));

        // Declared after the generations, across midnight between the two periods (the night's end and the morning).
        $this->unavailable($free, '2027-05-01 05:00', '2027-05-01 07:00');

        self::assertNotContains($free, $this->candidateEmails($this->dutyIn(1, '2027-04-30')), 'the night ends at 06:00 UTC');
        self::assertNotContains($free, $this->candidateEmails($this->dutyIn(2, '2027-05-01')), 'the morning starts at 06:00 UTC');
        $this->reassign($this->dutyIn(2, '2027-05-01'), $free);
        $this->assertError(409, 'invalid_candidate', 'UNAVAILABLE');
    }

    // --- after publication: rights, audit, publications untouched -------------------------------

    public function testAPublishedPeriodIsStillReassignedAuditedAndItsPublicationsAreUntouched(): void
    {
        $this->createPeriod();
        $this->addDuties(1, [['2027-04-27 08:00', '2027-04-27 20:00']]);
        $this->addDuties(2, [['2027-06-01 08:00', '2027-06-01 20:00']]);
        $this->generatePeriod(1);
        $this->publish(1);
        $this->generatePeriod(2);
        $publications = $this->publicationFingerprint();
        $published = $this->dutyIn(1, '2027-04-27');
        $draft = $this->dutyIn(2, '2027-06-01');

        $this->reassign($published, $this->someoneElse($this->holderOn(1, '2027-04-27')));
        self::assertResponseIsSuccessful();
        $this->reassign($draft, $this->someoneElse($this->holderOn(2, '2027-06-01')));
        self::assertResponseIsSuccessful();
        $view = $this->candidates($published);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$published}/unassign", ['expectedCurrentTeamMemberStableId' => $view['currentTeamMemberStableId']], $this->s['admin']);
        self::assertResponseIsSuccessful();

        self::assertSame([
            ['was_published' => true, 'had_previous' => true, 'has_new' => true, 'author' => 'creator@example.com', 'generation_period' => 1],
            ['was_published' => true, 'had_previous' => true, 'has_new' => false, 'author' => 'admin@example.com', 'generation_period' => 1],
        ], $this->eventsOf($published), 'a change of the published calendar, by whom');
        self::assertSame([['was_published' => false, 'had_previous' => true, 'has_new' => true, 'author' => 'creator@example.com', 'generation_period' => 2]], $this->eventsOf($draft));

        // Unpublished changes, per period: never a change of period 2 shown in period 1, nor the reverse.
        $state1 = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-state?period={$this->periodId(1)}", token: $this->s['creator']);
        self::assertTrue($state1['hasUnpublishedChanges']);
        self::assertSame(['2027-04-27'], array_column($state1['changes'], 'date'));
        $state2 = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-state?period={$this->periodId(2)}", token: $this->s['creator']);
        self::assertSame([], $state2['changes']);

        self::assertSame($publications, $this->publicationFingerprint(), 'publications, entries, lines, documents, notifications: untouched');
    }

    public function testTheRightsAreUnchangedOnEveryPeriod(): void
    {
        $this->createPeriod();
        $this->addDuties(2, [['2027-06-01 08:00', '2027-06-01 20:00']]);
        $this->generatePeriod(2);
        $duty = $this->dutyIn(2, '2027-06-01');
        $holder = $this->holderOn(2, '2027-06-01');
        $holderId = $this->memberStableIdIn($this->s['planningId'], $holder);

        foreach (['alice', 'bob', 'outsider'] as $who) {
            $this->candidates($duty, $this->s[$who]);
            self::assertResponseStatusCodeSame(403, $who);
            $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$duty}/reassign", ['teamMemberStableId' => $this->memberStableIdIn($this->s['planningId'], $this->someoneElse($holder)), 'expectedCurrentTeamMemberStableId' => $holderId], $this->s[$who]);
            self::assertResponseStatusCodeSame(403, $who);
            $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$duty}/unassign", ['expectedCurrentTeamMemberStableId' => $holderId], $this->s[$who]);
            self::assertResponseStatusCodeSame(403, $who);
        }
        self::assertSame($holder, $this->holderOn(2, '2027-06-01'));

        // A line ADMIN manages the calendar, as on a planning with a single period.
        $this->candidates($duty, $this->s['admin']);
        self::assertResponseIsSuccessful();
    }

    public function testADutyOfAnOrphanPeriodIsPartOfNoCalendar(): void
    {
        $this->createPeriod();
        $this->addDuties(2, [['2027-06-01 08:00', '2027-06-01 20:00']]);
        $this->generatePeriod(2);
        $duty = $this->dutyIn(2, '2027-06-01');
        $holderId = $this->memberStableIdIn($this->s['planningId'], $this->holderOn(2, '2027-06-01'));
        // A historical orphan (docs/planning-cycles.md §6.3): the period lost its line.
        $this->db()->executeStatement('UPDATE planning_periods SET planning_line_id = NULL WHERE id = ?', [$this->periodOf(2)->getId()]);
        $before = $this->fingerprint(2);

        $this->candidates($duty);
        self::assertResponseStatusCodeSame(404);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$duty}/reassign", ['teamMemberStableId' => $holderId, 'expectedCurrentTeamMemberStableId' => $holderId], $this->s['creator']);
        self::assertResponseStatusCodeSame(404);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$duty}/unassign", ['expectedCurrentTeamMemberStableId' => $holderId], $this->s['creator']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($before, $this->fingerprint(2));
    }

    // --- concurrency ----------------------------------------------------------------------------

    public function testTheCalendarIsReadOnlyUnderTheCalendarWriteLock(): void
    {
        $this->createPeriod();
        $this->addDuties(2, [['2027-06-01 08:00', '2027-06-01 20:00']]);
        $this->generatePeriod(2);
        $duty = $this->dutyIn(2, '2027-06-01');
        $holder = $this->holderOn(2, '2027-06-01');
        $holderId = $this->memberStableIdIn($this->s['planningId'], $holder);

        foreach ([
            ['reassign', ['teamMemberStableId' => $this->memberStableIdIn($this->s['planningId'], $this->someoneElse($holder)), 'expectedCurrentTeamMemberStableId' => $holderId]],
            ['unassign', ['expectedCurrentTeamMemberStableId' => $this->memberStableIdIn($this->s['planningId'], $this->someoneElse($holder))]],
        ] as [$action, $body]) {
            SqlRecorder::start();
            $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$duty}/{$action}", $body, $this->s['creator']);
            $statements = SqlRecorder::stop();
            self::assertResponseIsSuccessful($action);

            $lock = $read = null;
            foreach ($statements as $index => $sql) {
                $lock ??= str_contains($sql, 'pg_advisory_xact_lock') ? $index : null;
                $read ??= 1 === preg_match('/\bduty_assignments\b/', $sql) ? $index : null;
            }
            self::assertNotNull($lock, "{$action}: the CalendarWriteLock is taken");
            self::assertNotNull($read);
            self::assertLessThan($read, $lock, "{$action}: the current holders are read only once the lock is held");
        }
    }

    /**
     * Two managers open the calendar at the same time: each sees the same
     * free person for a duty on either side of the boundary. Each save alone
     * would be valid; both together would break the rest. The second save,
     * serialized behind the first by the lock, sees it and is refused.
     */
    public function testTwoSavesOnEitherSideOfTheBoundaryNeverBreakTheRestTogether(): void
    {
        $this->createPeriod();
        $this->addDuties(1, [self::NIGHT]);
        $this->addDuties(2, [['2027-05-01 08:00', '2027-05-01 20:00']]);
        $this->generatePeriod(1, self::REST);
        $this->generatePeriod(2, self::REST);
        $night = $this->dutyIn(1, '2027-04-30');
        $morning = $this->dutyIn(2, '2027-05-01');
        $free = $this->someoneElse($this->holderOn(1, '2027-04-30'), $this->holderOn(2, '2027-05-01'));
        $nightView = $this->candidates($night);
        $morningView = $this->candidates($morning);
        $freeId = $this->memberStableIdIn($this->s['planningId'], $free);
        self::assertContains($freeId, array_column($nightView['candidates'], 'teamMemberStableId'));
        self::assertContains($freeId, array_column($morningView['candidates'], 'teamMemberStableId'));

        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$night}/reassign", ['teamMemberStableId' => $freeId, 'expectedCurrentTeamMemberStableId' => $nightView['currentTeamMemberStableId']], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $period2 = $this->fingerprint(2);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$morning}/reassign", ['teamMemberStableId' => $freeId, 'expectedCurrentTeamMemberStableId' => $morningView['currentTeamMemberStableId']], $this->s['admin']);

        $this->assertError(409, 'invalid_candidate', 'CROSS_LINE_LEGAL_MIN_REST');
        self::assertSame($period2, $this->fingerprint(2));
        self::assertSame($free, $this->holderOn(1, '2027-04-30'));
    }

    /**
     * A publication committed after the request loaded the period (here: the
     * status written behind the EntityManager's back, as another request
     * would) — the event still says the published calendar changed.
     */
    public function testWhetherTheChangeTouchesAPublishedCalendarIsReadUnderTheLock(): void
    {
        $this->createPeriod();
        $this->addDuties(1, [['2027-04-27 08:00', '2027-04-27 20:00']]);
        $this->generatePeriod(1);
        $dutyId = $this->dutyIn(1, '2027-04-27');
        $holder = $this->holderOn(1, '2027-04-27');
        $this->em()->clear();
        $duty = static::getContainer()->get(DutyRepository::class)->findOneByStableId($dutyId);
        self::assertNotSame(PlanningPeriodStatus::PUBLISHED, $duty->getPlanningPeriod()->getStatus());

        $this->db()->executeStatement("UPDATE planning_periods SET status = 'PUBLISHED' WHERE id = ?", [$duty->getPlanningPeriod()->getId()]);
        $members = static::getContainer()->get(PlanningTeamMemberRepository::class);
        static::getContainer()->get(DutyReassignmentService::class)->reassign(
            $duty,
            $members->findOneByStableId($this->memberStableIdIn($this->s['planningId'], $this->someoneElse($holder))) ?? self::fail('no member'),
            $this->memberStableIdIn($this->s['planningId'], $holder),
            static::getContainer()->get(UserRepository::class)->findOneByEmail('creator@example.com') ?? self::fail('no creator'),
        );

        self::assertNotSame(PlanningPeriodStatus::PUBLISHED, $duty->getPlanningPeriod()->getStatus(), 'the entity in memory was stale');
        self::assertTrue($this->eventsOf($dutyId)[0]['was_published']);
    }

    public function testACompletionRecordsWhetherThePeriodWasPublishedWhenItWrites(): void
    {
        $this->createPeriod();
        $this->addDuties(1, [['2027-04-27 08:00', '2027-04-27 20:00']]);
        $this->addDuties(2, [['2027-06-01 08:00', '2027-06-01 20:00']]);
        $this->generatePeriod(1);
        $this->generatePeriod(2);
        $duty = $this->dutyIn(1, '2027-04-27');
        $view = $this->candidates($duty);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$duty}/unassign", ['expectedCurrentTeamMemberStableId' => $view['currentTeamMemberStableId']], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $period2 = $this->fingerprint(2);
        // Published while the completion solves (the worker loaded the period before).
        $db = $this->db();
        $periodId = $this->periodOf(1)->getId();
        FaultInjectingPlanningSolver::$duringSolve = static function () use ($db, $periodId): void {
            FaultInjectingPlanningSolver::$duringSolve = null;
            $db->executeStatement("UPDATE planning_periods SET status = 'PUBLISHED' WHERE id = ?", [$periodId]);
        };

        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/complete", ['period' => $this->periodId(1)], $this->s['creator']);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(1, $this->runQueuedPlanningJobs());

        self::assertNotNull($this->holderOn(1, '2027-04-27'));
        $events = $this->eventsOf($duty);
        self::assertCount(2, $events);
        self::assertSame(['was_published' => true, 'had_previous' => false, 'has_new' => true, 'author' => 'creator@example.com', 'generation_period' => 1], $events[1]);
        self::assertSame($period2, $this->fingerprint(2), 'completing period 1 never touches period 2');
    }

    // --- the former technical endpoint ----------------------------------------------------------

    public function testTheFormerTechnicalEndpointIsClosedOnEveryPeriod(): void
    {
        $this->createPeriod();
        $this->addDuties(2, [['2027-06-01 08:00', '2027-06-01 20:00']]);
        $this->generatePeriod(2);
        $generation = static::getContainer()->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($this->periodOf(2));
        $before = $this->fingerprint(2);

        $this->api($this->client, 'POST', "/api/planning-generations/{$generation->getStableId()}/assignments", [
            'dutyStableId' => $this->dutyIn(2, '2027-06-01'),
            'teamMemberStableId' => $this->memberStableIdIn($this->s['planningId'], 'alice@example.com'),
        ], $this->s['admin']);

        $this->assertError(410, 'endpoint_removed');
        self::assertSame($before, $this->fingerprint(2));
    }
}
