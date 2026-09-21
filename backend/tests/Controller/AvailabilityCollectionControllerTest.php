<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PlanningPeriodStatus;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Tests\InvitationTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * End-to-end HTTP tests of the availability-collection API
 * (docs/availability-collection.md §8): authorization matrix, "answer" only
 * for oneself, the list a manager reads, extension of a planning.
 */
final class AvailabilityCollectionControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use InvitationTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        // Deadlines must lie in the future: freeze "today" once for every test of this class.
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    /**
     * A planning (2027-01-01 → 2027-05-01) with a creator, a team ADMIN, two
     * plain members, and an outsider — everyone logged in.
     *
     * @return array<string, mixed>
     */
    private function scenario(KernelBrowser $client): array
    {
        $creator = $this->userToken($client, 'creator@example.com');
        $admin = $this->userToken($client, 'admin@example.com');
        $alice = $this->userToken($client, 'alice@example.com');
        $bob = $this->userToken($client, 'bob@example.com');
        $outsider = $this->userToken($client, 'outsider@example.com');

        [$planningId, $teamId] = $this->createPlanningWithTeam($client, $creator);
        $this->addMemberAs($client, $creator, $planningId, $teamId, 'admin@example.com', 'ADMIN');
        $this->addMemberAs($client, $creator, $planningId, $teamId, 'alice@example.com', 'MEMBER');
        $this->addMemberAs($client, $creator, $planningId, $teamId, 'bob@example.com', 'MEMBER');

        $collections = $this->api($client, 'GET', "/api/plannings/{$planningId}/availability-collections", token: $creator);
        self::assertResponseIsSuccessful();

        return [
            'planningId' => $planningId,
            'teamId' => $teamId,
            'collectionId' => $collections[0]['stableId'],
            'creator' => $creator,
            'admin' => $admin,
            'alice' => $alice,
            'bob' => $bob,
            'outsider' => $outsider,
        ];
    }

    private function httpStatus(KernelBrowser $client): int
    {
        return $client->getResponse()->getStatusCode();
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return list<array<string, mixed>> the responses, as the creator reads them
     */
    private function responsesOf(KernelBrowser $client, array $s, string $query = ''): array
    {
        $rows = $this->api($client, 'GET', "/api/availability-collections/{$s['collectionId']}/responses{$query}", token: $s['creator']);
        self::assertResponseIsSuccessful();

        return $rows;
    }

    /* ------------------------------------------------------------- planning API */

    public function testCreatingAPlanningWithIncludeMeMakesTheCreatorAParticipant(): void
    {
        $client = static::createClient();
        $creator = $this->userToken($client, 'creator@example.com');

        $created = $this->api($client, 'POST', '/api/plannings', [
            'name' => 'Gardes incluses',
            'startsAt' => '2027-01-01',
            'endsAt' => '2027-05-01',
            'timezone' => 'Europe/Brussels',
            'primaryTeam' => ['name' => 'Seniors'],
            'includeMe' => true,
        ], $creator);
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($created['participating']);
        self::assertTrue($created['canManage'], 'participating never replaces the right to manage');

        $detail = $this->api($client, 'GET', '/api/plannings/'.$created['stableId'], token: $creator);
        self::assertSame(1, $detail['lines'][0]['memberCount']);

        $collections = $this->api($client, 'GET', "/api/plannings/{$created['stableId']}/availability-collections", token: $creator);
        self::assertSame(['expected' => 1, 'acknowledged' => 0, 'pending' => 1], $collections[0]['progress']);
        self::assertSame('PENDING', $collections[0]['myResponse']['status']);
    }

    public function testByDefaultTheCreatorIsNotAParticipant(): void
    {
        $client = static::createClient();
        $creator = $this->userToken($client, 'creator@example.com');

        $created = $this->api($client, 'POST', '/api/plannings', [
            'name' => 'Gardes',
            'startsAt' => '2027-01-01',
            'endsAt' => '2027-05-01',
            'timezone' => 'Europe/Brussels',
            'primaryTeam' => ['name' => 'Seniors'],
        ], $creator);

        self::assertFalse($created['participating']);
        self::assertTrue($created['canManage']);

        $collections = $this->api($client, 'GET', "/api/plannings/{$created['stableId']}/availability-collections", token: $creator);
        self::assertSame(['expected' => 0, 'acknowledged' => 0, 'pending' => 0], $collections[0]['progress']);
        self::assertNull($collections[0]['myResponse']);
    }

    public function testMeExposesTheStableId(): void
    {
        $client = static::createClient();
        $token = $this->userToken($client, 'creator@example.com');

        $me = $this->api($client, 'GET', '/api/me', token: $token);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $me['stableId']);
    }

    /* ------------------------------------------------------------ authorization */

    public function testTheListIsForPeopleWhoCanViewThePlanningAndTheAnonymousAreRejected(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/availability-collections", token: $s['outsider']);
        self::assertSame(403, $this->httpStatus($client), 'an outsider must not even see that collections exist');

        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/availability-collections");
        self::assertSame(401, $this->httpStatus($client));

        $this->api($client, 'GET', "/api/availability-collections/{$s['collectionId']}", token: $s['outsider']);
        self::assertSame(403, $this->httpStatus($client));

        $this->api($client, 'GET', '/api/availability-collections/not-a-uuid', token: $s['alice']);
        self::assertSame(404, $this->httpStatus($client));
    }

    public function testAMemberSeesTheirOwnAnswerButNeitherTheCountersNorAnybodyElses(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $view = $this->api($client, 'GET', "/api/availability-collections/{$s['collectionId']}", token: $s['alice']);

        self::assertSame(200, $this->httpStatus($client));
        self::assertFalse($view['canManage']);
        self::assertArrayNotHasKey('progress', $view, 'the X/Y counters are a manager view');
        self::assertSame('PENDING', $view['myResponse']['status']);

        $this->api($client, 'GET', "/api/availability-collections/{$s['collectionId']}/responses", token: $s['alice']);
        self::assertSame(403, $this->httpStatus($client), 'MEMBER never reads the detailed answers of others');

        $list = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/availability-collections", token: $s['alice']);
        self::assertArrayNotHasKey('progress', $list[0]);
    }

    public function testAMemberCannotManageACollection(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/availability-collections", ['startsAt' => '2027-06-01', 'endsAt' => '2027-07-01'], $s['alice']);
        self::assertSame(403, $this->httpStatus($client));

        $this->api($client, 'PATCH', "/api/availability-collections/{$s['collectionId']}", ['deadline' => '2026-12-20'], $s['alice']);
        self::assertSame(403, $this->httpStatus($client));

        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/close", token: $s['alice']);
        self::assertSame(403, $this->httpStatus($client));
    }

    public function testTheCreatorAndATeamAdminCanManageAndTheAdminSeesTheCounters(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        foreach (['creator', 'admin'] as $who) {
            $view = $this->api($client, 'GET', "/api/availability-collections/{$s['collectionId']}", token: $s[$who]);
            self::assertTrue($view['canManage'], $who);
            self::assertSame(['expected' => 3, 'acknowledged' => 0, 'pending' => 3], $view['progress'], 'the ADMIN is a participant too');
        }

        $this->api($client, 'PATCH', "/api/availability-collections/{$s['collectionId']}", ['deadline' => '2026-12-20'], $s['admin']);
        self::assertSame(200, $this->httpStatus($client));
    }

    /* ------------------------------------------------------------ creating / dates */

    public function testAnAdminCanOpenAnExplicitCollectionWhoseWindowStaysInsideThePlanningAndNeverOverlaps(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        // The initial collection already covers the whole planning.
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/availability-collections", ['startsAt' => '2027-02-01', 'endsAt' => '2027-03-01'], $s['admin']);
        self::assertSame(409, $this->httpStatus($client));

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/availability-collections", ['startsAt' => '2027-05-01', 'endsAt' => '2027-06-01'], $s['admin']);
        self::assertSame(422, $this->httpStatus($client));

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/availability-collections", ['startsAt' => '2027-03-01', 'endsAt' => '2027-02-01'], $s['admin']);
        self::assertSame(422, $this->httpStatus($client));

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/availability-collections", ['startsAt' => 'nope', 'endsAt' => '2027-02-01'], $s['admin']);
        self::assertSame(422, $this->httpStatus($client));
    }

    public function testTheDeadlineCanBeSetClearedAndIsRejectedInThePast(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $set = $this->api($client, 'PATCH', "/api/availability-collections/{$s['collectionId']}", ['deadline' => '2026-12-20'], $s['creator']);
        self::assertSame('2026-12-20', $set['deadline']);

        $this->api($client, 'PATCH', "/api/availability-collections/{$s['collectionId']}", ['deadline' => '2026-12-09'], $s['creator']);
        self::assertSame(422, $this->httpStatus($client));

        $cleared = $this->api($client, 'PATCH', "/api/availability-collections/{$s['collectionId']}", ['deadline' => null], $s['creator']);
        self::assertNull($cleared['deadline']);

        $this->api($client, 'PATCH', "/api/availability-collections/{$s['collectionId']}", [], $s['creator']);
        self::assertSame(422, $this->httpStatus($client), 'the field must be explicit');
    }

    /* -------------------------------------------------------------- answering */

    public function testOnlyTheCurrentUserCanAnswerAndNeverForSomeoneElse(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $bobId = $this->api($client, 'GET', '/api/me', token: $s['bob'])['stableId'];

        // Alice tries to smuggle Bob's identity into the request: it is not a parameter of this endpoint.
        $answer = $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", ['userStableId' => $bobId, 'user' => $bobId], $s['alice']);

        self::assertSame(200, $this->httpStatus($client));
        self::assertSame('ACKNOWLEDGED', $answer['myResponse']['status']);
        $rows = $this->responsesOf($client, $s);
        $statuses = array_map(static fn (array $r): string => $r['status'], $rows);
        sort($statuses);
        self::assertSame(['ACKNOWLEDGED', 'PENDING', 'PENDING'], $statuses);
        $bob = array_values(array_filter($rows, static fn (array $r): bool => $r['user']['stableId'] === $bobId))[0];
        self::assertSame('PENDING', $bob['status'], 'Bob has not answered: nobody can confirm in his place');
    }

    public function testAnswerWithNoUnavailabilityAndWithConfirmationAreBothExplicitEvents(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->api($client, 'POST', '/api/me/calendar', ['type' => 'UNAVAILABLE', 'startsAt' => '2027-02-01T00:00:00+01:00', 'endsAt' => '2027-02-04T00:00:00+01:00'], $s['alice']);
        self::assertResponseStatusCodeSame(201);

        // Alice: absences entered, then "my availabilities are up to date".
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", token: $s['alice']);
        self::assertSame(200, $this->httpStatus($client));
        // Bob: nothing to declare, and says so.
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", ['noUnavailability' => true], $s['bob']);
        self::assertSame(200, $this->httpStatus($client));

        $rows = $this->responsesOf($client, $s, '?status=ACKNOWLEDGED');
        $kinds = array_map(static fn (array $r): ?string => $r['acknowledgementKind'], $rows);
        sort($kinds);
        self::assertSame(['CONFIRMED', 'NO_UNAVAILABILITY'], $kinds);
        foreach ($rows as $row) {
            self::assertSame('ACKNOWLEDGED', $row['status']);
            self::assertNotNull($row['acknowledgedAt']);
        }
        $view = $this->api($client, 'GET', "/api/availability-collections/{$s['collectionId']}", token: $s['creator']);
        self::assertSame(['expected' => 3, 'acknowledged' => 2, 'pending' => 1], $view['progress']);
    }

    public function testClaimingNoUnavailabilityIsRefusedWhenAbsencesExist(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->api($client, 'POST', '/api/me/calendar', ['type' => 'UNAVAILABLE', 'startsAt' => '2027-02-01T00:00:00+01:00', 'endsAt' => '2027-02-04T00:00:00+01:00'], $s['alice']);

        $error = $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", ['noUnavailability' => true], $s['alice']);

        self::assertSame(409, $this->httpStatus($client));
        self::assertSame('unavailability_exists', $error['error']);
        self::assertSame(1, $error['unavailablePeriodCount']);
        $mine = $this->api($client, 'GET', "/api/availability-collections/{$s['collectionId']}", token: $s['alice']);
        self::assertSame('PENDING', $mine['myResponse']['status']);
    }

    public function testDoubleClickAndSecondTabAreIdempotent(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $first = $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", token: $s['alice']);
        self::mockTime('2026-12-11 09:00:00 Europe/Brussels');
        $second = $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", token: $s['alice']);

        self::assertSame(200, $this->httpStatus($client));
        self::assertSame($first['myResponse']['acknowledgedAt'], $second['myResponse']['acknowledgedAt']);
        $view = $this->api($client, 'GET', "/api/availability-collections/{$s['collectionId']}", token: $s['creator']);
        self::assertSame(1, $view['progress']['acknowledged']);
    }

    public function testNonRespondentsAndOutsidersCannotAnswer(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        // The creator can view the planning but is not a participant: no row to answer.
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", token: $s['creator']);
        self::assertSame(403, $this->httpStatus($client));

        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", token: $s['outsider']);
        self::assertSame(403, $this->httpStatus($client));
    }

    public function testAnAnswerIsRefusedOnceTheCollectionIsClosed(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $closed = $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/close", token: $s['admin']);
        self::assertSame('CLOSED', $closed['status']);
        self::assertNotNull($closed['closedAt']);

        $error = $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", token: $s['alice']);
        self::assertSame(409, $this->httpStatus($client));
        self::assertSame('collection_closed', $error['error']);

        // Closing again is harmless.
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/close", token: $s['admin']);
        self::assertSame(200, $this->httpStatus($client));

        $this->api($client, 'PATCH', "/api/availability-collections/{$s['collectionId']}", ['deadline' => '2026-12-30'], $s['admin']);
        self::assertSame(409, $this->httpStatus($client));
    }

    /* ------------------------------------------------------- the manager's list */

    public function testTheManagerIdentifiesLateRespondentsAndFiltersThem(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", token: $s['alice']);
        // Bob edits his calendar but never confirms.
        $this->api($client, 'POST', '/api/me/calendar', ['type' => 'UNAVAILABLE', 'startsAt' => '2027-03-01T00:00:00+01:00', 'endsAt' => '2027-03-03T00:00:00+01:00'], $s['bob']);

        $pending = $this->api($client, 'GET', "/api/availability-collections/{$s['collectionId']}/responses?status=PENDING", token: $s['admin']);
        self::assertSame(200, $this->httpStatus($client));
        // The ADMIN (a participant who did nothing) and Bob (who touched his calendar) are late; Alice is not.
        self::assertCount(2, $pending);
        foreach ($pending as $row) {
            self::assertSame('PENDING', $row['status']);
            self::assertNull($row['acknowledgedAt']);
        }
        $touched = array_values(array_filter($pending, static fn (array $r): bool => null !== $r['lastAvailabilityChangeAt']));
        self::assertCount(1, $touched, 'the admin sees that Bob touched his calendar without confirming');

        $done = $this->api($client, 'GET', "/api/availability-collections/{$s['collectionId']}/responses?status=ACKNOWLEDGED", token: $s['admin']);
        self::assertCount(1, $done);
        self::assertNull($done[0]['lastAvailabilityChangeAt']);

        $all = $this->api($client, 'GET', "/api/availability-collections/{$s['collectionId']}/responses", token: $s['admin']);
        self::assertCount(3, $all);

        $this->api($client, 'GET', "/api/availability-collections/{$s['collectionId']}/responses?status=WHATEVER", token: $s['admin']);
        self::assertSame(422, $this->httpStatus($client));
    }

    public function testMyCollectionsListsOnlyMyOpenOnesAndTheirStatus(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $mine = $this->api($client, 'GET', '/api/me/availability-collections', token: $s['alice']);
        self::assertCount(1, $mine);
        self::assertSame($s['collectionId'], $mine[0]['stableId']);
        self::assertSame('PENDING', $mine[0]['myResponse']['status']);
        self::assertArrayNotHasKey('progress', $mine[0]);
        self::assertSame('2027-04-30', $mine[0]['lastDay'], 'endsAt is exclusive, lastDay is the inclusive day for display');

        self::assertSame([], $this->api($client, 'GET', '/api/me/availability-collections', token: $s['outsider']));
        self::assertSame([], $this->api($client, 'GET', '/api/me/availability-collections', token: $s['creator']), 'the creator is not a participant here');

        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", token: $s['alice']);
        $after = $this->api($client, 'GET', '/api/me/availability-collections', token: $s['alice']);
        self::assertSame('ACKNOWLEDGED', $after[0]['myResponse']['status'], 'an answered open collection stays listed so the dashboard can say "confirmed on …"');

        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/close", token: $s['admin']);
        self::assertSame([], $this->api($client, 'GET', '/api/me/availability-collections', token: $s['alice']), 'closed collections are history, not a to-do');
    }

    public function testACollectionOfAnotherPlanningIsNeverReachable(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        [$otherPlanning] = $this->createPlanningWithTeam($client, $s['outsider'], 'Autre');

        $otherList = $this->api($client, 'GET', "/api/plannings/{$otherPlanning}/availability-collections", token: $s['outsider']);
        self::assertCount(1, $otherList);

        // Alice belongs to the first planning only.
        $this->api($client, 'GET', "/api/plannings/{$otherPlanning}/availability-collections", token: $s['alice']);
        self::assertSame(403, $this->httpStatus($client));
        $this->api($client, 'POST', "/api/availability-collections/{$otherList[0]['stableId']}/acknowledge", token: $s['alice']);
        self::assertSame(403, $this->httpStatus($client));
    }

    /* ---------------------------------------------------------------- extension */

    public function testOnlyTheCreatorCanExtendAndOnlyTheNewSliceIsCollected(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/extensions", ['endsAt' => '2027-08-01'], $s['admin']);
        self::assertSame(403, $this->httpStatus($client), 'a team ADMIN manages the collection, not the planning structure');
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/extensions", ['endsAt' => '2027-08-01'], $s['alice']);
        self::assertSame(403, $this->httpStatus($client));

        $done = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/extensions", ['endsAt' => '2027-08-01', 'deadline' => '2026-12-20'], $s['creator']);

        self::assertSame(201, $this->httpStatus($client));
        self::assertSame('2027-08-01', $done['planning']['endsAt']);
        self::assertCount(1, $done['collections']);
        self::assertSame('2027-05-01', $done['collections'][0]['startsAt']);
        self::assertSame('2027-08-01', $done['collections'][0]['endsAt']);
        self::assertSame('2026-12-20', $done['collections'][0]['deadline']);

        $list = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/availability-collections", token: $s['creator']);
        self::assertCount(2, $list);
        self::assertSame('2027-05-01', $list[0]['startsAt'], 'newest window first');
        self::assertSame(['expected' => 3, 'acknowledged' => 0, 'pending' => 3], $list[0]['progress']);
        self::assertSame('2027-01-01', $list[1]['startsAt']);
    }

    public function testMembersAnsweredTheFirstWindowButMustAnswerTheExtension(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", token: $s['alice']);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/extensions", ['endsAt' => '2027-08-01'], $s['creator']);

        $mine = $this->api($client, 'GET', '/api/me/availability-collections', token: $s['alice']);
        self::assertCount(2, $mine);
        $byStart = [];
        foreach ($mine as $collection) {
            $byStart[$collection['startsAt']] = $collection['myResponse']['status'];
        }
        self::assertSame(['2027-01-01' => 'ACKNOWLEDGED', '2027-05-01' => 'PENDING'], $byStart);
    }

    public function testExtensionValidation(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $error = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/extensions", ['endsAt' => '2027-05-01'], $s['creator']);
        self::assertSame(422, $this->httpStatus($client));
        self::assertSame('no_new_range', $error['error']);

        $error = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/extensions", ['endsAt' => '2027-04-01'], $s['creator']);
        self::assertSame(422, $this->httpStatus($client));
        self::assertSame('range_shrink_not_supported', $error['error']);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/extensions", ['endsAt' => 'tomorrow'], $s['creator']);
        self::assertSame(422, $this->httpStatus($client));

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/extensions", ['endsAt' => '2027-08-01', 'deadline' => '2026-12-01'], $s['creator']);
        self::assertSame(422, $this->httpStatus($client), 'a deadline in the past');

        $detail = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator']);
        self::assertSame('2027-05-01', $detail['endsAt'], 'every refused extension left the planning untouched');
        self::assertCount(1, $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/availability-collections", token: $s['creator']));
    }

    public function testExtensionIsRefusedWhenALineIsAlreadyValidated(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($s['planningId']);
        $period = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningPeriod();
        $period->transitionTo(PlanningPeriodStatus::GENERATED);
        $period->transitionTo(PlanningPeriodStatus::VALIDATED);
        $container->get(EntityManagerInterface::class)->flush();

        $error = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/extensions", ['endsAt' => '2027-08-01'], $s['creator']);

        self::assertSame(409, $this->httpStatus($client));
        self::assertSame('planning_period_locked', $error['error']);
    }
}
