<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DutySwapProposal;
use App\Entity\PlanningPeriodStatus;
use App\Exception\DutySwapException;
use App\Exception\DutySwapNotApplicableException;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\DutySwapProposalRepository;
use App\Repository\DutySwapRequestRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\UserRepository;
use App\Service\DutyReassignmentService;
use App\Service\DutySwapService;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\PlanningCycleTestHelpers;
use App\Tests\SqlRecorder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Duty swaps between members on a planning with several periods, lot L4e
 * (docs/decisions.md D198), through the real API, the real job runner and
 * the real OR-Tools solver.
 *
 * Fixture (pilotScenario, LEGAL_MIN_REST 11 h): period 1 2027-01-01 →
 * 2027-05-01, period 2 → 2027-08-01, primary line "Seniors", Europe/Brussels.
 * Holders, forced by the manager after both periods are published:
 *
 *   period 1  Tue 6 Apr 08-20       bob
 *             Tue 13 Apr 08-20      admin
 *             Fri 30 Apr 20:00 → Sat 1 May 08:00 (the night)   alice
 *   period 2  Sat 1 May 06-14       bob     (overlaps the end of the night)
 *             Tue 4 May 08-20       admin
 *             Tue 11 May 08-20      alice
 */
final class PlanningCycleSwapTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;
    use PlanningCycleTestHelpers;

    private const REST = ['legalMinRestEnabled' => true, 'legalMinRestHours' => 11];

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    /** @var array<string, string> name → duty stableId */
    private array $d = [];

    protected function setUp(): void
    {
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
    }

    protected function tearDown(): void
    {
        SqlRecorder::stop();
        parent::tearDown();
    }

    // --- helpers ---------------------------------------------------------------------------------

    private function scenario(bool $publishPeriodTwo = true): void
    {
        $this->createPeriod('2027-08-01');
        $this->addDuties(1, [['2027-04-06 08:00', '2027-04-06 20:00'], ['2027-04-13 08:00', '2027-04-13 20:00'], ['2027-04-30 20:00', '2027-05-01 08:00']]);
        $this->addDuties(2, [['2027-05-01 06:00', '2027-05-01 14:00'], ['2027-05-04 08:00', '2027-05-04 20:00'], ['2027-05-11 08:00', '2027-05-11 20:00']]);
        $this->generatePeriod(1, self::REST);
        $this->generatePeriod(2, self::REST);
        $this->d = [
            'A1' => $this->dutyIn(1, '2027-04-06'),
            'B1' => $this->dutyIn(1, '2027-04-13'),
            'N1' => $this->dutyIn(1, '2027-04-30'),
            'O2' => $this->dutyIn(2, '2027-05-01'),
            'C2' => $this->dutyIn(2, '2027-05-04'),
            'D2' => $this->dutyIn(2, '2027-05-11'),
        ];
        $this->forceHolders([
            $this->d['A1'] => 'bob@example.com',
            $this->d['B1'] => 'admin@example.com',
            $this->d['N1'] => 'alice@example.com',
            $this->d['O2'] => 'bob@example.com',
            $this->d['C2'] => 'admin@example.com',
            $this->d['D2'] => 'alice@example.com',
        ]);
        $this->publishPeriod(1);
        if ($publishPeriodTwo) {
            $this->publishPeriod(2);
        }
    }

    private function userStableId(string $email): string
    {
        return (string) static::getContainer()->get(UserRepository::class)->findOneByEmail($email)->getStableId();
    }

    private function token(string $email): string
    {
        return $this->s[strstr($email, '@', true)];
    }

    /** "J'ai déjà convenu d'un échange": $email's $offered against $counterpartEmail's $counterpart. @return array<string, mixed> */
    private function agreed(string $email, string $offered, string $counterpartEmail, string $counterpart): array
    {
        return $this->api($this->client, 'POST', '/api/duty-swap-requests', [
            'dutyStableId' => $this->d[$offered],
            'kind' => 'AGREED',
            'recipientUserStableIds' => [$this->userStableId($counterpartEmail)],
            'counterpartDutyStableId' => $this->d[$counterpart],
            'acknowledgedResponsibility' => true,
        ], $this->token($email));
    }

    /** @return array<string, mixed> */
    private function accept(string $proposalStableId, string $email): array
    {
        return $this->api($this->client, 'POST', "/api/duty-swap-proposals/{$proposalStableId}/accept", [], $this->token($email));
    }

    private function swapRequestCount(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM duty_swap_requests');
    }

    /** @return list<string> the request's event types, oldest first */
    private function swapEvents(string $requestStableId): array
    {
        return $this->db()->fetchFirstColumn('SELECT e.type FROM duty_swap_events e JOIN duty_swap_requests r ON r.id = e.request_id WHERE r.stable_id = ? ORDER BY e.id', [$requestStableId]);
    }

    // --- 1. inside one period ---------------------------------------------------------------------

    public function testASwapInsideAPeriodIsAppliedAndAuditedLeavingTheOtherPeriodUntouched(): void
    {
        $this->scenario();
        $period1 = $this->fingerprint(1);
        $publications = $this->publicationFingerprint();

        $request = $this->agreed('alice@example.com', 'D2', 'admin@example.com', 'C2');
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        self::assertSame(['stableId' => $this->periodId(2), 'number' => 2, 'count' => 2], $request['offered']['period'], 'the period is the duty\'s own');
        self::assertSame(2, $request['proposals'][0]['counterpartUnit']['period']['count']);
        self::assertSame(2, $request['proposals'][0]['counterpartUnit']['period']['number']);

        $view = $this->accept($request['proposals'][0]['stableId'], 'admin@example.com');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('COMPLETED', $view['status']);
        self::assertSame('admin@example.com', $this->holderOf($this->d['D2']));
        self::assertSame('alice@example.com', $this->holderOf($this->d['C2']));

        // Audited as a change of a published calendar, linked to the proposal, in period 2's generation.
        $events = $this->db()->fetchAllAssociative(<<<'SQL'
            SELECT d.stable_id AS duty, e.was_published, e.swap_proposal_id IS NOT NULL AS by_swap, a.source, c.number AS generation_period
            FROM duty_assignment_events e JOIN duties d ON d.id = e.duty_id JOIN duty_assignments a ON a.id = e.new_assignment_id
            JOIN planning_generations g ON g.id = e.generation_id JOIN planning_periods pp ON pp.id = g.planning_period_id
            JOIN planning_cycles c ON c.id = pp.planning_cycle_id
            WHERE e.swap_proposal_id IS NOT NULL ORDER BY d.stable_id
        SQL);
        self::assertCount(2, $events);
        foreach ($events as $event) {
            self::assertTrue($event['was_published']);
            self::assertTrue($event['by_swap']);
            self::assertSame('SWAP', $event['source']);
            self::assertSame(2, $event['generation_period']);
        }
        self::assertContains('SWAP_COMPLETED', $this->swapEvents($request['stableId']));

        self::assertSame($period1, $this->fingerprint(1), 'period 1 never touched');
        self::assertSame($publications, $this->publicationFingerprint(), 'a swap publishes nothing (L4c untouched)');
        // Only period 2 has unpublished changes now.
        foreach ([1 => 0, 2 => 2] as $number => $expected) {
            $state = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-state?period={$this->periodId($number)}", token: $this->s['creator']);
            self::assertResponseIsSuccessful();
            self::assertCount($expected, $state['changes'], "period {$number}");
        }
    }

    // --- 2. across two periods ------------------------------------------------------------------

    public function testASwapAcrossTwoPeriodsIsRefusedAtEveryStep(): void
    {
        $this->scenario();
        $period1 = $this->fingerprint(1);
        $period2 = $this->fingerprint(2);

        // AGREED: refused before anything is written or anybody notified.
        $this->agreed('alice@example.com', 'D2', 'bob@example.com', 'A1');
        $this->assertError(422, 'other_period');
        self::assertSame(0, $this->swapRequestCount());

        // SEARCH: bob may only offer a unit of period 2 — period 1's are neither offered nor accepted.
        $request = $this->api($this->client, 'POST', '/api/duty-swap-requests', [
            'dutyStableId' => $this->d['D2'],
            'kind' => 'SEARCH',
            'audience' => 'SELECTED',
            'recipientUserStableIds' => [$this->userStableId('bob@example.com')],
            'acknowledgedResponsibility' => true,
        ], $this->s['alice']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $detail = $this->api($this->client, 'GET', "/api/duty-swap-requests/{$request['stableId']}", token: $this->s['bob']);
        self::assertSame([$this->d['O2']], array_column($detail['proposableUnits'], 'dutyStableId'), 'bob\'s unit of period 2 only — never his 6 April');
        self::assertSame([2], array_column(array_column($detail['proposableUnits'], 'period'), 'number'));
        $this->api($this->client, 'POST', "/api/duty-swap-requests/{$request['stableId']}/proposals", ['dutyStableId' => $this->d['A1']], $this->s['bob']);
        $this->assertError(422, 'other_period');
        self::assertSame([], $this->db()->fetchFirstColumn('SELECT id FROM duty_swap_proposals'));

        // And the final revalidation says it too, if a proposal across periods ever reached it.
        $period = $this->periodOf(1); // clears the EntityManager: everything below is read after it
        $bobsApril = static::getContainer()->get(DutyAssignmentRepository::class)->findCurrentByGenerationAndDuties(
            static::getContainer()->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($period),
            [static::getContainer()->get(DutyRepository::class)->findOneByStableId($this->d['A1'])],
        );
        $entity = static::getContainer()->get(DutySwapRequestRepository::class)->findOneByStableId($request['stableId']);
        $forged = new DutySwapProposal($entity, static::getContainer()->get(UserRepository::class)->findOneByEmail('bob@example.com'), array_values($bobsApril)[0], new \DateTimeImmutable());
        try {
            static::getContainer()->get(DutyReassignmentService::class)->checkSwap($forged, new \DateTimeImmutable());
            self::fail('A swap across two periods must be refused.');
        } catch (DutySwapNotApplicableException $exception) {
            self::assertSame(DutySwapNotApplicableException::OTHER_PERIOD, $exception->reason);
        }

        self::assertSame($period1, $this->fingerprint(1));
        self::assertSame($period2, $this->fingerprint(2));
    }

    // --- 3./4. the neighbouring period ------------------------------------------------------------

    public function testASwapAtTheBoundaryRespectingTheRestIsAccepted(): void
    {
        $this->scenario();
        $period1 = $this->fingerprint(1);

        // Adam takes Saturday 1 May 06:00 — he holds nothing the night before (alice does) — and bob his 4 May.
        $request = $this->agreed('bob@example.com', 'O2', 'admin@example.com', 'C2');
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $this->accept($request['proposals'][0]['stableId'], 'admin@example.com');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        self::assertSame('admin@example.com', $this->holderOf($this->d['O2']));
        self::assertSame('bob@example.com', $this->holderOf($this->d['C2']));
        self::assertSame('alice@example.com', $this->holderOf($this->d['N1']));
        self::assertSame($period1, $this->fingerprint(1));
    }

    public function testASwapThatOverlapsTheNeighbouringPeriodIsRefusedBothWays(): void
    {
        $this->scenario();
        $period1 = $this->fingerprint(1);
        $period2 = $this->fingerprint(2);

        // Period 2: alice would take Saturday 06:00 while her night of period 1 runs until 08:00.
        $this->agreed('alice@example.com', 'D2', 'bob@example.com', 'O2');
        $this->assertError(409, 'swap_not_applicable', 'CROSS_LINE_CONFLICT');
        self::assertSame('requester', json_decode((string) $this->client->getResponse()->getContent(), true)['party']);

        // Period 1: bob would take the night while his Saturday of period 2 starts at 06:00.
        $this->agreed('bob@example.com', 'A1', 'alice@example.com', 'N1');
        $this->assertError(409, 'swap_not_applicable', 'CROSS_LINE_CONFLICT');
        self::assertSame('requester', json_decode((string) $this->client->getResponse()->getContent(), true)['party']);

        self::assertSame(0, $this->swapRequestCount());
        self::assertSame($period1, $this->fingerprint(1));
        self::assertSame($period2, $this->fingerprint(2));
    }

    public function testAChangeInTheNeighbouringPeriodIsSeenWhenTheSwapIsAccepted(): void
    {
        $this->scenario();

        // Valid when asked: Adam holds nothing on the night of 30 April.
        $request = $this->agreed('bob@example.com', 'O2', 'admin@example.com', 'C2');
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        // Then the manager gives Adam the night — in period 1.
        $this->forceHolders([$this->d['N1'] => 'admin@example.com']);
        $period2 = $this->fingerprint(2);

        // The acceptance, revalidated under the lock against the calendar of right now, refuses it.
        $this->accept($request['proposals'][0]['stableId'], 'admin@example.com');
        $this->assertError(409, 'swap_not_applicable', 'CROSS_LINE_CONFLICT');
        self::assertSame('bob@example.com', $this->holderOf($this->d['O2']));
        self::assertSame('admin@example.com', $this->holderOf($this->d['C2']));
        self::assertSame($period2, $this->fingerprint(2), 'nothing written');
        self::assertContains('SWAP_VALIDATION_FAILED', $this->swapEvents($request['stableId']));
    }

    public function testAPeriodNoLongerPublishedWhenTheSwapIsAcceptedIsSeenUnderTheLock(): void
    {
        $this->scenario();
        $request = $this->agreed('alice@example.com', 'D2', 'admin@example.com', 'C2');
        self::assertResponseStatusCodeSame(201);
        $proposal = static::getContainer()->get(DutySwapProposalRepository::class)->findOneByStableId($request['proposals'][0]['stableId']);
        $admin = static::getContainer()->get(UserRepository::class)->findOneByEmail('admin@example.com');
        $period = $proposal->getRequest()->getOfferedDuty()->getPlanningPeriod();
        self::assertSame(PlanningPeriodStatus::PUBLISHED, $period->getStatus(), 'loaded as published…');

        // …then archived behind this EntityManager's back, as a request committed meanwhile would.
        $this->db()->executeStatement("UPDATE planning_periods SET status = 'ARCHIVED' WHERE id = ?", [$period->getId()]);
        try {
            static::getContainer()->get(DutySwapService::class)->accept($admin, $proposal);
            self::fail('The period is no longer published.');
        } catch (DutySwapException $exception) {
            self::assertSame(DutySwapNotApplicableException::PERIOD_NOT_PUBLISHED, $exception->reason);
        }
        self::assertSame('alice@example.com', $this->holderOf($this->d['D2']));
        self::assertSame('admin@example.com', $this->holderOf($this->d['C2']));
    }

    public function testTheAcceptanceReadsTheHoldersOnlyUnderTheCalendarWriteLock(): void
    {
        $this->scenario();
        $request = $this->agreed('alice@example.com', 'D2', 'admin@example.com', 'C2');
        self::assertResponseStatusCodeSame(201);

        SqlRecorder::start();
        $this->accept($request['proposals'][0]['stableId'], 'admin@example.com');
        $statements = SqlRecorder::stop();
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $lock = $read = null;
        foreach ($statements as $index => $sql) {
            $lock ??= str_contains($sql, 'pg_advisory_xact_lock') ? $index : null;
            $read ??= 1 === preg_match('/\bduty_assignments\b.*\bcurrent\b/s', $sql) ? $index : null;
        }
        self::assertNotNull($lock, 'the CalendarWriteLock is taken');
        self::assertNotNull($read);
        self::assertLessThan($read, $lock, 'the current holders are read only once the lock is held');
    }

    // --- 6. rights and publication, period by period -----------------------------------------------

    public function testOnlyAPublishedPeriodIsSwappableWhateverThePlanningsOtherPeriods(): void
    {
        $this->scenario(publishPeriodTwo: false);

        // Period 1 is published, period 2 is a draft: its duties are nobody's to swap — or to see.
        // docs/decisions.md D204: exactly the answer an unknown duty gets — its existence is not disclosed.
        $this->agreed('alice@example.com', 'D2', 'admin@example.com', 'C2');
        $this->assertError(404, 'not_found');
        $draft = (string) $this->client->getResponse()->getContent();
        $this->api($this->client, 'GET', "/api/duty-swaps/options?dutyStableId={$this->d['D2']}", token: $this->s['alice']);
        $this->assertError(404, 'not_found');
        $this->api($this->client, 'GET', '/api/duty-swaps/options?dutyStableId=01a00000-0000-7000-8000-000000000000', token: $this->s['alice']);
        $this->assertError(404, 'not_found');
        self::assertSame($draft, (string) $this->client->getResponse()->getContent(), 'unknown and unpublished: the same body');
        self::assertSame(['2027-04-30'], array_merge(...array_column($this->api($this->client, 'GET', '/api/me/duties', token: $this->s['alice'])['duties'], 'dates')));

        // Period 1 still swaps.
        $request = $this->agreed('bob@example.com', 'A1', 'admin@example.com', 'B1');
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $this->accept($request['proposals'][0]['stableId'], 'admin@example.com');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('admin@example.com', $this->holderOf($this->d['A1']));
    }

    public function testRightsAreUnchangedAndAnEarlierPeriodStillSwapsOnceTheNextIsPublished(): void
    {
        $this->scenario();
        $request = $this->agreed('bob@example.com', 'A1', 'admin@example.com', 'B1');
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $proposal = $request['proposals'][0]['stableId'];

        // Only the counterpart decides — not the requester (403); a colleague or the planning's manager does not
        // even see the proposal (404).
        $this->accept($proposal, 'bob@example.com');
        self::assertResponseStatusCodeSame(403);
        $this->accept($proposal, 'alice@example.com');
        self::assertResponseStatusCodeSame(404);
        $this->api($this->client, 'POST', "/api/duty-swap-proposals/{$proposal}/accept", [], $this->s['creator']);
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
        self::assertSame('bob@example.com', $this->holderOf($this->d['A1']));

        // A recipient outside the line is refused.
        $this->api($this->client, 'POST', '/api/duty-swap-requests', [
            'dutyStableId' => $this->d['D2'],
            'kind' => 'SEARCH',
            'audience' => 'SELECTED',
            'recipientUserStableIds' => [$this->userStableId('outsider@example.com')],
            'acknowledgedResponsibility' => true,
        ], $this->s['alice']);
        $this->assertError(422, 'invalid_recipients');

        // Period 1, published before period 2, still swaps — audited as a published change.
        $this->accept($proposal, 'admin@example.com');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('admin@example.com', $this->holderOf($this->d['A1']));
        self::assertSame('bob@example.com', $this->holderOf($this->d['B1']));
        self::assertSame([true, true], array_map(static fn ($v): bool => (bool) $v, $this->db()->fetchFirstColumn('SELECT was_published FROM duty_assignment_events WHERE swap_proposal_id IS NOT NULL')));

        // The managers' history names the period.
        $history = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/duty-swaps", token: $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $history['requests'][0]['offered']['period']['number']);
    }

    public function testTheCreationOptionsOfferOnlyTheUnitsOfTheSamePeriod(): void
    {
        $this->scenario();

        $options = $this->api($this->client, 'GET', "/api/duty-swaps/options?dutyStableId={$this->d['D2']}", token: $this->s['alice']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(2, $options['offered']['period']['number']);
        $offered = [];
        foreach ($options['colleagues'] as $colleague) {
            foreach ($colleague['units'] as $unit) {
                $offered[$unit['dutyStableId']] = $unit['period']['number'];
            }
        }
        ksort($offered);
        $expected = [$this->d['O2'] => 2, $this->d['C2'] => 2];
        ksort($expected);
        self::assertSame($expected, $offered, 'period 2 only: never bob\'s 6 April or Adam\'s 13 April');

        $options = $this->api($this->client, 'GET', "/api/duty-swaps/options?dutyStableId={$this->d['N1']}", token: $this->s['alice']);
        self::assertResponseIsSuccessful();
        $offered = [];
        foreach ($options['colleagues'] as $colleague) {
            foreach ($colleague['units'] as $unit) {
                $offered[$unit['dutyStableId']] = $unit['period']['number'];
            }
        }
        ksort($offered);
        $expected = [$this->d['A1'] => 1, $this->d['B1'] => 1];
        ksort($expected);
        self::assertSame($expected, $offered);
    }
}
