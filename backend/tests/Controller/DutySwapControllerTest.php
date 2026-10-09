<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DutyAssignmentEvent;
use App\Entity\DutyAssignmentSource;
use App\Entity\DutySwapEvent;
use App\Entity\DutySwapNotificationStatus;
use App\Entity\PlanningPeriodStatus;
use App\Repository\DutySwapProposalRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Service\DutySwapNotificationSender;
use App\Service\DutySwapService;
use App\Service\PlanningPeriodLifecycleService;
use App\Tests\DutySwapTestHelpers;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

/**
 * Duty swaps between members, end to end through the API
 * (docs/duty-swaps.md, docs/decisions.md D178). Fixture: DutySwapTestHelpers
 * (alice 5 Jan, admin 6 Jan + block 9-10 Jan + 19 Jan, bob 12 Jan; an
 * 11-hour legal rest frozen by the generation; published).
 *
 * The rule checked throughout: a request or a proposal never changes who
 * holds a duty — only an accepted, revalidated swap does, atomically.
 */
final class DutySwapControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use DutySwapTestHelpers;

    private ?\Closure $failingListener = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    protected function tearDown(): void
    {
        $this->stopFailing();
        parent::tearDown();
    }

    // --- 1, 2: a request changes nothing ------------------------------------------------------

    public function testCreatingARequestNeverChangesTheCalendar(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $before = $this->holders($s);

        $agreed = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('OPEN', $agreed['status']);
        self::assertSame('AGREED', $agreed['kind']);
        self::assertCount(1, $agreed['proposals']);
        self::assertSame('PENDING', $agreed['proposals'][0]['status']);

        $search = $this->searchRequest($client, $s, $s['admin'], '2027-01-19', null);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('ALL', $search['audience']);

        self::assertSame($before, $this->holders($s), 'Two requests and a proposal: not a single assignment moved.');
        self::assertSame(DutyAssignmentSource::MANUAL, $this->sourceOn($s['planningId'], '2027-01-05'));
        self::assertSame(['REQUEST_CREATED', 'PROPOSAL_CREATED', 'REQUEST_SENT'], $this->eventTypes($agreed['stableId']));
        self::assertSame(['REQUEST_CREATED', 'REQUEST_SENT'], $this->eventTypes($search['stableId']));
    }

    public function testTheRequesterStillHoldsTheDutyAndSeesTheRequestOnIt(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');

        $duties = $this->api($client, 'GET', '/api/me/duties', token: $s['alice'])['duties'];
        self::assertCount(1, $duties, 'Nobody answered: the duty is still Alice\'s, and only hers.');
        self::assertSame(['2027-01-05'], $duties[0]['dates']);
        self::assertSame($request['stableId'], $duties[0]['swapRequestStableId'], 'Shown as "Échange demandé" — never as transferred.');
        self::assertTrue($duties[0]['swappable']);

        $bobDuties = $this->api($client, 'GET', '/api/me/duties', token: $s['bob'])['duties'];
        self::assertSame([['2027-01-12']], array_column($bobDuties, 'dates'), 'Bob has not answered: he holds nothing more.');
        self::assertNull($bobDuties[0]['swapRequestStableId'], 'A request addressed to Bob is not his request.');
    }

    public function testAnUnansweredRequestExpiresWhenItsDutyStartsAndNothingMoves(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');
        $before = $this->holders($s);

        self::mockTime('2027-01-05 08:00:00 Europe/Brussels');
        $overview = $this->api($client, 'GET', '/api/me/duty-swaps', token: $s['alice']);
        self::assertSame('EXPIRED', $overview['mine'][0]['status']);
        self::assertSame('EXPIRED', $overview['mine'][0]['proposals'][0]['status']);
        self::assertSame($before, $this->holders($s), 'Alice stayed responsible for her duty: it is still hers.');
        self::assertSame(['REQUEST_CREATED', 'PROPOSAL_CREATED', 'REQUEST_SENT', 'REQUEST_EXPIRED'], $this->eventTypes($request['stableId']));

        $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['bob']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testTheResponsibilityWarningMustBeAcknowledged(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);

        $response = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12', acknowledged: false);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('responsibility_not_acknowledged', $response['error']);
        self::assertSame([], $this->api($client, 'GET', '/api/me/duty-swaps', token: $s['alice'])['mine']);
    }

    // --- 3, 4, 15, 18, 19: refuse / accept a direct proposal ---------------------------------

    public function testARefusalChangesNothingAndIsRecorded(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $before = $this->holders($s);
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');

        $view = $this->decide($client, $request['proposals'][0]['stableId'], 'refuse', $s['bob']);
        self::assertResponseIsSuccessful();
        self::assertSame('REFUSED', $view['status']);
        self::assertSame('REFUSED', $view['proposals'][0]['status']);
        self::assertSame($before, $this->holders($s));
        self::assertSame(['REQUEST_CREATED', 'PROPOSAL_CREATED', 'REQUEST_SENT', 'PROPOSAL_REFUSED', 'REQUEST_REFUSED'], $this->eventTypes($request['stableId']));
        self::assertCount(1, $this->notificationsOf($request['stableId'], 'PROPOSAL_REFUSED'), 'Alice is told her proposal was refused.');
        self::assertCount(0, $this->notificationsOf($request['stableId'], 'SWAP_CONFIRMED'));
    }

    public function testAcceptingADirectProposalSwapsBothDutiesWithoutAnyManager(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');
        self::assertCount(1, $this->notificationsOf($request['stableId'], 'AGREED_PROPOSAL_RECEIVED'), 'Bob is emailed the proposal.');

        $view = $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['bob']);
        self::assertResponseIsSuccessful();
        self::assertFalse($view['alreadyApplied']);
        self::assertSame('COMPLETED', $view['status']);
        self::assertSame($request['proposals'][0]['stableId'], $view['acceptedProposalStableId']);

        $holders = $this->holders($s);
        self::assertSame('bob@example.com', $holders['2027-01-05']);
        self::assertSame('alice@example.com', $holders['2027-01-12']);
        self::assertSame('admin@example.com', $holders['2027-01-06'], 'Nothing else moved.');
        self::assertSame(DutyAssignmentSource::SWAP, $this->sourceOn($s['planningId'], '2027-01-05'));
        self::assertSame(DutyAssignmentSource::SWAP, $this->sourceOn($s['planningId'], '2027-01-12'));
        self::assertSame(PlanningPeriodStatus::PUBLISHED, $this->periodStatusOf($s['planningId']), 'Still published — no solver, no regeneration.');

        // The calendar history points at the swap, and the swap history at the calendar rows.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $events = $em->getRepository(DutyAssignmentEvent::class)->createQueryBuilder('e')->andWhere('e.swapProposal IS NOT NULL')->getQuery()->getResult();
        self::assertCount(2, $events);
        foreach ($events as $event) {
            self::assertSame($request['proposals'][0]['stableId'], (string) $event->getSwapProposal()->getStableId());
            self::assertSame('bob@example.com', $event->getAuthor()->getEmail(), 'Concluded by Bob\'s acceptance.');
            self::assertTrue($event->wasPublished());
        }
        self::assertSame(['REQUEST_CREATED', 'PROPOSAL_CREATED', 'REQUEST_SENT', 'PROPOSAL_ACCEPTED', 'SWAP_COMPLETED'], $this->eventTypes($request['stableId']));
        $completed = $view['history'][4];
        self::assertSame('SWAP_COMPLETED', $completed['type']);
        self::assertCount(2, $completed['data']['supersededAssignments']);
        self::assertCount(2, $completed['data']['createdAssignments']);
        self::assertSame(['2027-01-12'], $completed['data']['requester']['takes']['dates']);
        self::assertSame(['2027-01-05'], $completed['data']['counterpart']['takes']['dates']);

        // Two confirmation emails, one per participant, sent after the commit.
        $confirmations = $this->notificationsOf($request['stableId'], 'SWAP_CONFIRMED');
        self::assertSame(['alice@example.com', 'bob@example.com'], array_map(static fn ($n): string => $n->getRecipient()->getEmail(), $confirmations));
        foreach ($confirmations as $confirmation) {
            self::assertSame(DutySwapNotificationStatus::SENT, $confirmation->getStatus());
            self::assertSame(1, $confirmation->getAttempts());
        }

        // "Mes gardes" follows the official calendar.
        self::assertSame([['2027-01-12']], array_column($this->api($client, 'GET', '/api/me/duties', token: $s['alice'])['duties'], 'dates'));
    }

    public function testTheConfirmationEmailSaysWhoTakesWhatAndToWarnTheDirection(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');

        $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['bob']);
        self::assertResponseIsSuccessful();

        $confirmations = array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email && 'MedVue — Confirmation de votre échange de gardes' === $m->getSubject()));
        self::assertCount(2, $confirmations);
        $text = (string) $confirmations[0]->getTextBody();
        self::assertStringContainsString('Alice Martin assurera la garde du mardi 12 janvier 2027', $text);
        self::assertStringContainsString('Bob Durand assurera la garde du mardi 5 janvier 2027', $text);
        self::assertStringContainsString('merci de prévenir la direction de cet échange', $text);
        self::assertStringContainsString('Aucune validation supplémentaire du gestionnaire des gardes', $text);
        self::assertStringContainsString('merci de prévenir la direction', (string) $confirmations[0]->getHtmlBody());
    }

    public function testPendingEmailsRemindThatNothingChangedYet(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');

        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        self::assertSame('bob@example.com', $messages[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('RIEN N\'EST ENCORE MODIFIÉ', (string) $messages[0]->getTextBody());
        self::assertStringContainsString('responsable de sa garde initiale', (string) $messages[0]->getTextBody());
    }

    // --- 5: a request to the whole line, several proposals, one retained ----------------------

    public function testAWholeLineRequestTakesOneProposalAndClosesTheOthers(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->searchRequest($client, $s, $s['alice'], '2027-01-05', null);
        self::assertResponseStatusCodeSame(201);
        $notified = array_map(static fn ($n): string => $n->getRecipient()->getEmail(), $this->notificationsOf($request['stableId'], 'REQUEST_RECEIVED'));
        sort($notified);
        self::assertContains('bob@example.com', $notified);
        self::assertContains('admin@example.com', $notified);
        self::assertNotContains('alice@example.com', $notified, 'Never the requester herself.');
        self::assertNotContains('outsider@example.com', $notified);

        // Bob and Adam see it in "Demandes de l'équipe" and each propose one of their own duties.
        self::assertSame($request['stableId'], $this->api($client, 'GET', '/api/me/duty-swaps', token: $s['bob'])['team'][0]['stableId']);
        $this->proposeOn($client, $s, $request['stableId'], $s['bob'], '2027-01-12');
        self::assertResponseStatusCodeSame(201);
        $this->proposeOn($client, $s, $request['stableId'], $s['admin'], '2027-01-06');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('alice@example.com', $this->holders($s)['2027-01-05'], 'Two proposals, still nothing moved.');

        // Bob sees only his own proposal; Alice sees both.
        self::assertCount(1, $this->requestDetail($client, $request['stableId'], $s['bob'])['proposals']);
        $view = $this->requestDetail($client, $request['stableId'], $s['alice']);
        self::assertCount(2, $view['proposals']);
        self::assertCount(2, $this->notificationsOf($request['stableId'], 'PROPOSAL_RECEIVED'));

        $view = $this->decide($client, $this->proposalBy($view, 'admin@example.com')['stableId'], 'accept', $s['alice']);
        self::assertResponseIsSuccessful();
        self::assertSame('COMPLETED', $view['status']);
        self::assertSame('ACCEPTED', $this->proposalBy($view, 'admin@example.com')['status']);
        self::assertSame('NOT_SELECTED', $this->proposalBy($view, 'bob@example.com')['status']);

        $holders = $this->holders($s);
        self::assertSame('admin@example.com', $holders['2027-01-05']);
        self::assertSame('alice@example.com', $holders['2027-01-06']);
        self::assertSame('bob@example.com', $holders['2027-01-12'], 'The proposal not retained moved nothing.');
        self::assertContains('PROPOSAL_NOT_SELECTED', $this->eventTypes($request['stableId']));

        // Bob's history keeps the request even though it is closed.
        $team = $this->api($client, 'GET', '/api/me/duty-swaps', token: $s['bob'])['team'];
        self::assertSame('NOT_SELECTED', $team[0]['proposals'][0]['status']);
    }

    public function testOnlySelectedColleaguesSeeAndAnswerATargetedRequest(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->searchRequest($client, $s, $s['alice'], '2027-01-05', ['bob@example.com']);
        self::assertResponseStatusCodeSame(201);

        self::assertSame($request['stableId'], $this->api($client, 'GET', '/api/me/duty-swaps', token: $s['bob'])['received'][0]['stableId']);
        $admin = $this->api($client, 'GET', '/api/me/duty-swaps', token: $s['admin']);
        self::assertSame([], $admin['received']);
        self::assertSame([], $admin['team'], 'A targeted request is not shown to the rest of the line.');

        $this->requestDetail($client, $request['stableId'], $s['outsider']);
        self::assertResponseStatusCodeSame(404);
        $this->proposeOn($client, $s, $request['stableId'], $s['outsider'], '2027-01-19');
        self::assertResponseStatusCodeSame(404);

        $this->proposeOn($client, $s, $request['stableId'], $s['bob'], '2027-01-12');
        self::assertResponseStatusCodeSame(201);
        $response = $this->proposeOn($client, $s, $request['stableId'], $s['bob'], '2027-01-12');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_proposed', $response['error'], 'One pending proposal per person and request.');
    }

    // --- 6, 17: cancellation and withdrawal ---------------------------------------------------

    public function testACancelledRequestChangesNothingAndKeepsItsHistory(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $before = $this->holders($s);
        $request = $this->searchRequest($client, $s, $s['alice'], '2027-01-05', null);
        $this->proposeOn($client, $s, $request['stableId'], $s['bob'], '2027-01-12');

        $this->api($client, 'POST', "/api/duty-swap-requests/{$request['stableId']}/cancel", [], $s['bob']);
        self::assertResponseStatusCodeSame(403, 'Only the requester cancels.');

        $view = $this->api($client, 'POST', "/api/duty-swap-requests/{$request['stableId']}/cancel", [], $s['alice']);
        self::assertResponseIsSuccessful();
        self::assertSame('CANCELLED', $view['status']);
        self::assertSame('CANCELLED', $view['proposals'][0]['status']);
        self::assertSame($before, $this->holders($s));
        self::assertSame(['REQUEST_CREATED', 'REQUEST_SENT', 'PROPOSAL_CREATED', 'REQUEST_CANCELLED'], array_column($view['history'], 'type'));
        self::assertNotNull($this->requestEntity($request['stableId']), 'Never deleted.');

        $this->decide($client, $view['proposals'][0]['stableId'], 'accept', $s['alice']);
        self::assertResponseStatusCodeSame(409);

        // The duty is free for a new request.
        $this->searchRequest($client, $s, $s['alice'], '2027-01-05', null);
        self::assertResponseStatusCodeSame(201);
    }

    public function testAProposalCanBeWithdrawnAndTheRequestStaysOpen(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->searchRequest($client, $s, $s['alice'], '2027-01-05', null);
        $view = $this->proposeOn($client, $s, $request['stableId'], $s['bob'], '2027-01-12');

        $this->decide($client, $view['proposals'][0]['stableId'], 'withdraw', $s['alice']);
        self::assertResponseStatusCodeSame(403, 'Only its author withdraws a proposal.');
        $view = $this->decide($client, $view['proposals'][0]['stableId'], 'withdraw', $s['bob']);
        self::assertResponseIsSuccessful();
        self::assertSame('WITHDRAWN', $view['proposals'][0]['status']);
        self::assertSame('OPEN', $view['status']);
        self::assertTrue($view['actions']['propose'], 'Bob may propose again.');
    }

    public function testACompletedSwapCannotBeCancelledToRestoreTheOldHolders(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');
        $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['bob']);
        $after = $this->holders($s);

        $response = $this->api($client, 'POST', "/api/duty-swap-requests/{$request['stableId']}/cancel", [], $s['alice']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('swap_already_completed', $response['error']);
        self::assertSame($after, $this->holders($s));
    }

    // --- 7, 8, 9: revalidation on the calendar after the swap ---------------------------------

    public function testAColleagueWhoBecameUnavailableCannotTakeTheDuty(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $before = $this->holders($s);
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');

        // Declared AFTER the request: the check at creation is never the last word.
        $this->declareRange($client, $s['bob'], '2027-01-05', '2027-01-06');
        $response = $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['bob']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('swap_not_applicable', $response['error']);
        self::assertSame('UNAVAILABLE', $response['reason']);
        self::assertSame('counterpart', $response['party']);
        self::assertStringContainsString('Bob Durand ne peut pas assurer la garde du mardi 5 janvier 2027', $response['message']);

        self::assertSame($before, $this->holders($s));
        self::assertSame('SWAP_VALIDATION_FAILED', $this->eventTypes($request['stableId'])[3]);
        self::assertSame('OPEN', $this->requestDetail($client, $request['stableId'], $s['alice'])['status'], 'A person-level refusal leaves the request open; they may still cancel it.');
        self::assertCount(0, $this->notificationsOf($request['stableId'], 'SWAP_CONFIRMED'), 'No confirmation for a swap that did not happen.');
    }

    public function testASwapBreakingTheLegalRestIsRefused(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);

        // Adam would take Tue 5 AND keep Wed 6 (back to back, no rest): refused when proposed already…
        $response = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'admin@example.com', '2027-01-19');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('LEGAL_MIN_REST', $response['reason']);
        self::assertSame('counterpart', $response['party']);
        self::assertStringContainsString('Adam Leroy ne peut pas assurer la garde du mardi 5 janvier 2027 : repos légal insuffisant', $response['message']);

        // …and at acceptance, when the conflict appeared after the proposal: valid when Bob asked
        // (Bob 12 ↔ Alice 5), until the manager gave Bob Wed 6 as well.
        $request = $this->agreedRequest($client, $s, $s['bob'], '2027-01-12', 'alice@example.com', '2027-01-05');
        self::assertResponseStatusCodeSame(201);
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'), 'bob@example.com');
        self::assertResponseIsSuccessful();
        $before = $this->holders($s);

        $response = $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['alice']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('LEGAL_MIN_REST', $response['reason']);
        self::assertSame('requester', $response['party'], 'Bob would hold Tue 5 and Wed 6 back to back.');
        self::assertSame($before, $this->holders($s));
    }

    public function testTheDutyACandidateGivesAwayIsNotAFalseConflict(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);

        // Alice (Tue 5) ↔ Adam (Wed 6): back to back. Checked naively against today's calendar, Adam taking Tue 5
        // while still holding Wed 6 breaks the 11-hour rest — but after the swap Adam no longer holds Wed 6.
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'admin@example.com', '2027-01-06');
        self::assertResponseStatusCodeSame(201);
        $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['admin']);
        self::assertResponseIsSuccessful();

        $holders = $this->holders($s);
        self::assertSame('admin@example.com', $holders['2027-01-05']);
        self::assertSame('alice@example.com', $holders['2027-01-06']);
    }

    // --- 10, 11: blocks, atomicity ------------------------------------------------------------

    public function testABlockIsSwappedWholeAgainstASingleDuty(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);

        // Requested from the block's SECOND day: the whole block is the unit.
        $request = $this->agreedRequest($client, $s, $s['admin'], '2027-01-10', 'bob@example.com', '2027-01-12');
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['2027-01-09', '2027-01-10'], $request['offered']['dates']);
        self::assertSame('Test group', $request['offered']['blockName']);

        $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['bob']);
        self::assertResponseIsSuccessful();
        $holders = $this->holders($s);
        self::assertSame('bob@example.com', $holders['2027-01-09']);
        self::assertSame('bob@example.com', $holders['2027-01-10'], 'Never one day of a block without the other.');
        self::assertSame('admin@example.com', $holders['2027-01-12']);
        $rows = $this->currentRows($s['planningId']);
        foreach (['2027-01-09', '2027-01-10', '2027-01-12'] as $date) {
            self::assertSame(DutyAssignmentSource::SWAP, $rows[$date]->getSource());
        }
    }

    public function testAFailureMidTransactionLeavesNoPartialSwap(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $before = $this->holders($s);
        $request = $this->agreedRequest($client, $s, $s['admin'], '2027-01-09', 'bob@example.com', '2027-01-12');

        // The database refuses the LAST new row (Tue 12 to Adam) — after the block's rows were already written.
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $dutyId = $this->dutyEntityOn($s['planningId'], '2027-01-12')->getId();
        $connection->executeStatement("CREATE FUNCTION swap_test_fail() RETURNS trigger AS \$\$ BEGIN IF NEW.source = 'SWAP' AND NEW.duty_id = {$dutyId} THEN RAISE EXCEPTION 'injected failure'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql");
        $connection->executeStatement('CREATE TRIGGER trg_swap_test_fail BEFORE INSERT ON duty_assignments FOR EACH ROW EXECUTE FUNCTION swap_test_fail()');

        $client->catchExceptions(true);
        $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['bob']);
        self::assertResponseStatusCodeSame(500);

        self::assertSame($before, $this->holders($s), 'Neither unit moved: the block rows were rolled back with the rest.');
        self::assertSame(DutyAssignmentSource::MANUAL, $this->sourceOn($s['planningId'], '2027-01-09'));
        self::assertSame('OPEN', $this->requestEntity($request['stableId'])->getStatus()->value);
        self::assertCount(0, $this->notificationsOf($request['stableId'], 'SWAP_CONFIRMED'), 'No email for a swap that did not happen.');

        $connection->executeStatement('DROP TRIGGER trg_swap_test_fail ON duty_assignments');
        $connection->executeStatement('DROP FUNCTION swap_test_fail()');
        $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['bob']);
        self::assertResponseIsSuccessful('The same proposal goes through once the failure is gone.');
        self::assertSame('bob@example.com', $this->holders($s)['2027-01-10']);
    }

    // --- 12, 13, 21: concurrency, staleness, repeated clicks ---------------------------------

    public function testARepeatedAcceptanceNeverSwapsTwice(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');
        $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['bob']);
        $after = $this->holders($s);
        $rows = \count($this->currentRows($s['planningId']));

        $view = $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['bob']);
        self::assertResponseIsSuccessful();
        self::assertTrue($view['alreadyApplied']);
        self::assertSame($after, $this->holders($s), 'Not swapped back.');
        self::assertSame($rows, \count($this->currentRows($s['planningId'])));
        self::assertCount(2, $this->notificationsOf($request['stableId'], 'SWAP_CONFIRMED'), 'Still exactly two confirmations.');
        self::assertSame(1, array_count_values($this->eventTypes($request['stableId']))['SWAP_COMPLETED']);
    }

    public function testTwoRequestsOnTheSameDutyOnlyOneSwapHappens(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);

        // Bob's Tue 12 is wanted twice: by Alice (for her Tue 5) and by Adam (for his Tue 19).
        $first = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');
        $second = $this->agreedRequest($client, $s, $s['admin'], '2027-01-19', 'bob@example.com', '2027-01-12');
        self::assertResponseStatusCodeSame(201);

        $this->decide($client, $first['proposals'][0]['stableId'], 'accept', $s['bob']);
        self::assertResponseIsSuccessful();
        // The second one is closed in the same transaction, never left to fail later.
        self::assertSame('OBSOLETE', $this->requestEntity($second['stableId'])->getStatus()->value);
        $response = $this->decide($client, $second['proposals'][0]['stableId'], 'accept', $s['bob']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('request_closed', $response['error']);

        $holders = $this->holders($s);
        self::assertSame('alice@example.com', $holders['2027-01-12']);
        self::assertSame('admin@example.com', $holders['2027-01-19']);
        self::assertContains('REQUEST_OBSOLETE', $this->eventTypes($second['stableId']));
    }

    public function testTwoProposalsAcceptedOneAfterTheOtherOnlyTheFirstApplies(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->searchRequest($client, $s, $s['alice'], '2027-01-05', null);
        $this->proposeOn($client, $s, $request['stableId'], $s['bob'], '2027-01-12');
        $view = $this->proposeOn($client, $s, $request['stableId'], $s['admin'], '2027-01-06');

        $this->decide($client, $this->proposalBy($view, 'bob@example.com')['stableId'], 'accept', $s['alice']);
        self::assertResponseIsSuccessful();
        $response = $this->decide($client, $this->proposalBy($view, 'admin@example.com')['stableId'], 'accept', $s['alice']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('request_closed', $response['error']);
        self::assertSame('admin@example.com', $this->holders($s)['2027-01-06']);
    }

    public function testADutyReassignedByAManagerSinceTheRequestMakesItObsolete(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');

        // The manager moves Bob's Tue 12 to Adam, then back to Bob: same holder, but a different row.
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-12'), 'admin@example.com');
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-12'), 'bob@example.com');
        self::assertResponseIsSuccessful();
        $before = $this->holders($s);

        $response = $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['bob']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('duty_changed', $response['reason']);
        self::assertSame($before, $this->holders($s));
        self::assertSame('OBSOLETE', $this->requestEntity($request['stableId'])->getStatus()->value, 'A decision taken on the old state is never applied to the new one.');
        self::assertSame(['REQUEST_CREATED', 'PROPOSAL_CREATED', 'REQUEST_SENT', 'SWAP_VALIDATION_FAILED', 'PROPOSAL_OBSOLETE', 'REQUEST_OBSOLETE'], $this->eventTypes($request['stableId']));
    }

    public function testOnlyOneOpenRequestPerDuty(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $this->searchRequest($client, $s, $s['alice'], '2027-01-05', null);
        self::assertResponseStatusCodeSame(201);

        $response = $this->searchRequest($client, $s, $s['alice'], '2027-01-05', ['bob@example.com']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_requested', $response['error']);
    }

    // --- 14, 22: authorization and isolation -------------------------------------------------

    public function testNobodyDecidesInSomebodyElsesPlace(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');
        $proposal = $request['proposals'][0]['stableId'];

        $this->decide($client, $proposal, 'accept', $s['alice']);
        self::assertResponseStatusCodeSame(403, 'The requester cannot accept her own proposal on Bob\'s behalf.');
        $this->decide($client, $proposal, 'accept', $s['creator']);
        self::assertResponseStatusCodeSame(403, 'A manager may read, never accept for a member.');
        $this->decide($client, $proposal, 'accept', $s['admin']);
        self::assertResponseStatusCodeSame(403);
        $this->decide($client, $proposal, 'refuse', $s['outsider']);
        self::assertResponseStatusCodeSame(404, 'Not even disclosed to a stranger.');

        self::assertSame('alice@example.com', $this->holders($s)['2027-01-05']);
    }

    public function testNobodyRequestsASwapOfADutyThatIsNotTheirs(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);

        $response = $this->searchRequest($client, $s, $s['bob'], '2027-01-05', null);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('duty_not_held', $response['error']);

        // "Agreed" with somebody on a duty that person does not hold.
        $response = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-19');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('duty_not_held', $response['error']);

        $response = $this->searchRequest($client, $s, $s['alice'], '2027-01-05', ['outsider@example.com']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_recipients', $response['error']);
    }

    public function testAnotherLineNeverSeesNorAnswersAWholeLineRequest(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $this->addSecondaryLine($client, $s, ['junior@example.com']);
        $junior = $this->loginUser($client, 'junior@example.com', 'correct-horse-battery');

        // A colleague of another line is never a valid recipient…
        $response = $this->searchRequest($client, $s, $s['alice'], '2027-01-05', ['junior@example.com']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_recipients', $response['error']);

        // …and never part of "the whole line".
        $request = $this->searchRequest($client, $s, $s['alice'], '2027-01-05', null);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([], $this->api($client, 'GET', '/api/me/duty-swaps', token: $junior)['team']);
        self::assertNotContains('junior@example.com', array_map(static fn ($n): string => $n->getRecipient()->getEmail(), $this->notificationsOf($request['stableId'])));
        $this->requestDetail($client, $request['stableId'], $junior);
        self::assertResponseStatusCodeSame(404);
        $this->proposeOn($client, $s, $request['stableId'], $junior, '2027-01-12');
        self::assertResponseStatusCodeSame(404);
    }

    public function testManagersReadThePlanningHistoryButMembersCannot(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');
        $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['bob']);

        foreach (['creator', 'admin'] as $manager) {
            $history = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/duty-swaps", token: $s[$manager]);
            self::assertResponseIsSuccessful();
            self::assertSame('COMPLETED', $history['requests'][0]['status']);
            self::assertSame('SWAP_COMPLETED', end($history['requests'][0]['history'])['type']);
            self::assertFalse($history['requests'][0]['proposals'][0]['actions']['accept'], 'Nothing to approve.');
        }

        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/duty-swaps", token: $s['alice']);
        self::assertResponseStatusCodeSame(403);
    }

    // --- 16: started, past, archived ---------------------------------------------------------

    public function testAStartedDutyCanNoLongerBeSwapped(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');

        self::mockTime('2027-01-05 10:00:00 Europe/Brussels');
        $response = $this->searchRequest($client, $s, $s['alice'], '2027-01-05', null);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('duty_started', $response['error']);

        $response = $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['bob']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('alice@example.com', $this->holders($s)['2027-01-05']);
        self::assertSame('EXPIRED', $this->requestEntity($request['stableId'])->getStatus()->value);
        unset($response);

        $duties = $this->api($client, 'GET', '/api/me/duties', token: $s['alice'])['duties'];
        self::assertFalse($duties[0]['swappable']);
    }

    public function testAnArchivedPeriodIsNeverSwapped(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');

        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($s['planningId']);
        $period = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningPeriod();
        $container->get(PlanningPeriodLifecycleService::class)->transition($period, PlanningPeriodStatus::ARCHIVED);
        $container->get(EntityManagerInterface::class)->flush();

        $response = $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['bob']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('period_not_published', $response['reason']);
        self::assertSame('alice@example.com', $this->holders($s)['2027-01-05']);
        self::assertSame('OBSOLETE', $this->requestEntity($request['stableId'])->getStatus()->value);

        $response = $this->searchRequest($client, $s, $s['bob'], '2027-01-12', null);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('duty_not_swappable', $response['error']);
    }

    public function testADeactivatedLineIsNeverSwapped(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');
        $before = $this->holders($s);

        // Deactivated after the request: the check at acceptance must see it, not only the one at creation.
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($s['planningId']);
        $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->setActive(false);
        $container->get(EntityManagerInterface::class)->flush();

        $response = $this->decide($client, $request['proposals'][0]['stableId'], 'accept', $s['bob']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('period_not_published', $response['reason']);
        self::assertSame($before, $this->holders($s));
        self::assertSame('OBSOLETE', $this->requestEntity($request['stableId'])->getStatus()->value);
    }

    // --- 20: SMTP failure ----------------------------------------------------------------------

    public function testAnSmtpFailureNeverUndoesTheSwapAndTheEmailIsRetried(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');

        // Through the service (the listener lives in this kernel's container, which an HTTP request would reboot).
        $this->failFor('alice@example.com');
        $container = static::getContainer();
        $proposal = $container->get(DutySwapProposalRepository::class)->findOneByStableId($request['proposals'][0]['stableId']);
        self::assertTrue($container->get(DutySwapService::class)->accept($this->userOf('bob@example.com'), $proposal), 'The swap is committed before any email is attempted.');
        self::assertSame('bob@example.com', $this->holders($s)['2027-01-05']);

        $byRecipient = [];
        foreach ($this->notificationsOf($request['stableId'], 'SWAP_CONFIRMED') as $notification) {
            $byRecipient[$notification->getRecipient()->getEmail()] = $notification;
        }
        self::assertSame(DutySwapNotificationStatus::FAILED, $byRecipient['alice@example.com']->getStatus());
        self::assertStringContainsString('SMTP refused', (string) $byRecipient['alice@example.com']->getLastError());
        self::assertNotNull($byRecipient['alice@example.com']->getLastAttemptAt());
        self::assertSame(DutySwapNotificationStatus::SENT, $byRecipient['bob@example.com']->getStatus());

        $this->stopFailing();
        $report = static::getContainer()->get(DutySwapNotificationSender::class)->retryDue();
        self::assertSame(['attempted' => 1, 'sent' => 1, 'failed' => 0], $report, 'Only the failed email is retried, never Bob\'s again.');
        $alice = $this->notificationsOf($request['stableId'], 'SWAP_CONFIRMED')[0];
        self::assertSame(DutySwapNotificationStatus::SENT, $alice->getStatus());
        self::assertSame(2, $alice->getAttempts());
        self::assertSame(['attempted' => 0, 'sent' => 0, 'failed' => 0], static::getContainer()->get(DutySwapNotificationSender::class)->retryDue());
    }

    // --- housekeeping, journal, invariants ------------------------------------------------------

    public function testTheMaintenanceCommandExpiresRequestsNobodyLookedAtAndRetriesEmails(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->searchRequest($client, $s, $s['alice'], '2027-01-05', ['bob@example.com']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // An email left PENDING by a process that died right after the commit.
        $em->getConnection()->executeStatement("UPDATE duty_swap_notifications SET status = 'PENDING', attempts = 0, sent_at = NULL");

        self::mockTime('2027-01-05 10:00:00 Europe/Brussels');
        $tester = new CommandTester((new Application(self::$kernel))->find('app:duty-swaps:maintain'));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('1 swap request(s)/proposal(s) closed; 1 email(s) retried — 1 sent, 0 failed.', self::flat($tester->getDisplay()));

        self::assertSame('EXPIRED', $this->requestEntity($request['stableId'])->getStatus()->value);
        $events = $em->getRepository(DutySwapEvent::class)->findBy([], ['id' => 'ASC']);
        $expired = end($events);
        self::assertSame('REQUEST_EXPIRED', $expired->getType()->value);
        self::assertNull($expired->getActor(), 'A system transition: nobody decided it.');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('0 swap request(s)/proposal(s) closed; 0 email(s) retried', self::flat($tester->getDisplay()), 'Idempotent.');
    }

    public function testTheSwapJournalIsAppendOnly(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $this->searchRequest($client, $s, $s['alice'], '2027-01-05', null);
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        foreach (['UPDATE duty_swap_events SET type = type', 'DELETE FROM duty_swap_events'] as $sql) {
            try {
                $connection->executeStatement('SAVEPOINT journal');
                $connection->executeStatement($sql);
                self::fail("{$sql} must be refused.");
            } catch (DriverException $exception) {
                self::assertStringContainsString('duty_swap_events is append-only', $exception->getMessage());
                $connection->executeStatement('ROLLBACK TO SAVEPOINT journal');
            }
        }
    }

    public function testTheDatabaseRefusesASecondAcceptedProposalForOneRequest(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $request = $this->searchRequest($client, $s, $s['alice'], '2027-01-05', null);
        $this->proposeOn($client, $s, $request['stableId'], $s['bob'], '2027-01-12');
        $this->proposeOn($client, $s, $request['stableId'], $s['admin'], '2027-01-06');
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->expectException(UniqueConstraintViolationException::class);
        $connection->executeStatement("UPDATE duty_swap_proposals SET status = 'ACCEPTED', decided_at = NOW()");
    }

    public function testTheDeciderIsAlwaysTheParticipantWhoDidNotAuthorTheProposal(): void
    {
        $client = static::createClient();
        $s = $this->swapScenario($client);
        $agreed = $this->agreedRequest($client, $s, $s['alice'], '2027-01-05', 'bob@example.com', '2027-01-12');
        self::assertSame('Alice', $agreed['proposals'][0]['author']['firstName']);
        self::assertSame('Bob', $agreed['proposals'][0]['decider']['firstName'], 'Agreed: the colleague decides.');
        self::assertFalse($agreed['proposals'][0]['actions']['accept'], 'Never offered to its author.');
        self::assertTrue($this->requestDetail($client, $agreed['stableId'], $s['bob'])['proposals'][0]['actions']['accept']);

        $search = $this->searchRequest($client, $s, $s['admin'], '2027-01-19', ['bob@example.com']);
        $view = $this->proposeOn($client, $s, $search['stableId'], $s['bob'], '2027-01-12');
        self::assertSame('Bob', $view['proposals'][0]['author']['firstName']);
        self::assertSame('Adam', $view['proposals'][0]['decider']['firstName'], 'Search: the requester decides.');
        self::assertFalse($view['proposals'][0]['actions']['accept']);
        self::assertTrue($view['proposals'][0]['actions']['withdraw']);
    }

    // --- helpers -------------------------------------------------------------------------------

    /** The console output on one line (SymfonyStyle wraps long messages). */
    private static function flat(string $display): string
    {
        return (string) preg_replace('~\s+~', ' ', $display);
    }

    private function failFor(string $address): void
    {
        $this->stopFailing();
        $this->failingListener = static function (MessageEvent $event) use ($address): void {
            $message = $event->getMessage();
            if ($message instanceof Email && $message->getTo()[0]->getAddress() === $address) {
                throw new TransportException('SMTP refused '.$address);
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
}
