<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\PlanningPilotTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The OWNER/ADMIN pilot view of a planning's availability collection
 * (docs/availability-collection.md §15, docs/decisions.md D127-D128): the
 * status of every participant, one participant's detail, and the
 * (informative) availability deadline.
 */
final class PlanningCollectionStatusControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use PlanningPilotTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    // --- who may read ---------------------------------------------------

    public function testCreatorAndTeamAdminCanReadTheCollectionStatus(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        foreach (['creator', 'admin'] as $who) {
            $status = $this->collectionStatus($client, $s, $s[$who]);

            self::assertSame($s['planningId'], $status['planning']['stableId']);
            self::assertSame('2027-01-01', $status['planning']['startsAt']);
            self::assertSame('2027-04-30', $status['planning']['lastDay']);
            // The creator did not include themself: admin, alice and bob participate.
            self::assertSame(3, $status['summary']['participantCount']);
            self::assertSame(3, $status['summary']['expectedCount']);
            self::assertSame(0, $status['summary']['confirmedCount']);
            self::assertSame(3, $status['summary']['pendingCount']);
            self::assertSame(1, $status['openCollectionCount']);
        }
    }

    public function testPlainMemberOutsiderAndAnonymousCannotReadIt(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $url = "/api/plannings/{$s['planningId']}/collection-status";
        $aliceRowId = $this->memberIdOf($client, $s, 'alice@example.com');

        $this->api($client, 'GET', $url, token: $s['alice']);
        self::assertResponseStatusCodeSame(403, 'A plain member must not see everybody\'s answers.');
        $this->api($client, 'GET', $url, token: $s['outsider']);
        self::assertResponseStatusCodeSame(403);
        $this->api($client, 'GET', $url);
        self::assertResponseStatusCodeSame(401);
        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/members/{$aliceRowId}/availability-status", token: $s['bob']);
        self::assertResponseStatusCodeSame(403);
    }

    // --- PENDING is never inferred from the absence of unavailabilities -----

    public function testAMemberWithoutUnavailabilityWhoDidNotConfirmStaysPending(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $row = $this->rowOf($this->collectionStatus($client, $s), 'alice@example.com');

        self::assertSame(0, $row['unavailabilityCount']);
        self::assertSame('PENDING', $row['collectionState'], '"No unavailability recorded" must never read as "confirmed".');
        self::assertNull($row['acknowledgedAt']);
        self::assertNull($row['acknowledgementKind']);
        self::assertSame(1, $row['pendingCollectionCount']);
    }

    public function testAMemberWithUnavailabilitiesWhoDidNotConfirmStaysPending(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->declareRange($client, $s['alice'], '2027-02-10', '2027-02-12');

        $row = $this->rowOf($this->collectionStatus($client, $s), 'alice@example.com');

        self::assertSame(1, $row['unavailabilityCount']);
        self::assertSame('PENDING', $row['collectionState']);
        self::assertNotNull($row['lastAvailabilityChangeAt'], 'Touching the calendar is recorded, but is not an answer (D121).');
    }

    public function testAMemberWhoExplicitlyConfirmedNoUnavailabilityIsConfirmed(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", ['noUnavailability' => true], $s['alice']);
        self::assertResponseIsSuccessful();

        $status = $this->collectionStatus($client, $s);
        $row = $this->rowOf($status, 'alice@example.com');
        self::assertSame('ACKNOWLEDGED', $row['collectionState']);
        self::assertSame('NO_UNAVAILABILITY', $row['acknowledgementKind']);
        self::assertNotNull($row['acknowledgedAt']);
        self::assertSame(0, $row['pendingCollectionCount']);
        self::assertSame(1, $status['summary']['confirmedCount']);
        self::assertSame(2, $status['summary']['pendingCount']);
        self::assertSame('PENDING', $this->rowOf($status, 'bob@example.com')['collectionState']);
    }

    // --- unavailabilities: read live, limited to the planning period ------

    public function testUnavailabilitiesAreLimitedToThePlanningPeriod(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $this->declareRange($client, $s['alice'], '2026-11-02', '2026-11-05');   // before the planning: ignored
        $this->declareRange($client, $s['alice'], '2026-12-29', '2027-01-04');   // crosses the start: counted
        $this->declareRange($client, $s['alice'], '2027-02-10', '2027-02-11');   // inside: counted
        $this->declareRange($client, $s['alice'], '2027-05-10', '2027-05-12');   // after the planning: ignored
        $this->declareRange($client, $s['alice'], '2027-03-01', '2027-03-03', 'PREFER_DUTY');  // a preference: not an unavailability

        $status = $this->collectionStatus($client, $s);
        self::assertSame(2, $this->rowOf($status, 'alice@example.com')['unavailabilityCount'], 'Only the two periods intersecting 2027-01-01 → 2027-04-30 count.');
        self::assertSame(2, $status['summary']['unavailabilityCount']);

        $detail = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/members/{$this->memberIdOf($client, $s, 'alice@example.com')}/availability-status", token: $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $detail['unavailabilities']);
        self::assertSame(['UNAVAILABLE', 'UNAVAILABLE'], array_column($detail['unavailabilities'], 'type'));
        $brussels = new \DateTimeZone('Europe/Brussels');
        self::assertSame('2026-12-29', (new \DateTimeImmutable($detail['unavailabilities'][0]['startsAt']))->setTimezone($brussels)->format('Y-m-d'), 'The crossing period is returned whole, not clipped.');
        self::assertSame('2027-02-10', (new \DateTimeImmutable($detail['unavailabilities'][1]['startsAt']))->setTimezone($brussels)->format('Y-m-d'));
        // Preferences live in their own section, never mixed with the unavailabilities.
        self::assertCount(1, $detail['preferences']);
        self::assertSame('PREFER_DUTY', $detail['preferences'][0]['type']);
    }

    public function testAnAbsenceOutsideThePeriodIsNotCounted(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->declareRange($client, $s['bob'], '2027-05-20', '2027-05-25');

        $status = $this->collectionStatus($client, $s);

        self::assertSame(0, $this->rowOf($status, 'bob@example.com')['unavailabilityCount']);
        self::assertSame(0, $status['summary']['unavailabilityCount']);
    }

    public function testMemberDetailExposesIdentityRoleParticipationAndAnswers(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", [], $s['alice']);

        $detail = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/members/{$this->memberIdOf($client, $s, 'alice@example.com')}/availability-status", token: $s['admin']);
        self::assertResponseIsSuccessful();

        self::assertSame('Test', $detail['member']['firstName']);
        self::assertSame('User', $detail['member']['lastName']);
        self::assertSame('MEMBER', $detail['member']['role']);
        self::assertSame('ACKNOWLEDGED', $detail['member']['collectionState']);
        self::assertSame('CONFIRMED', $detail['member']['acknowledgementKind']);
        self::assertSame('2027-01-01', $detail['participation']['membershipStart']);
        self::assertNull($detail['participation']['membershipEnd']);
        self::assertEquals(1.0, $detail['participation']['participationPeriods'][0]['participationFactor']);
        self::assertCount(1, $detail['collections']);
        self::assertSame('ACKNOWLEDGED', $detail['collections'][0]['status']);
        self::assertSame([], $detail['reminders']);
        // No email address, no other member's data.
        self::assertStringNotContainsString('@example.com', (string) json_encode($detail));
    }

    // --- cross-team / cross-planning isolation -----------------------------

    public function testAnotherPlanningsManagerCannotReadNorProbeThisPlanning(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $aliceRowId = $this->memberIdOf($client, $s, 'alice@example.com');

        // A second planning, with its own creator and member.
        $other = $this->userToken($client, 'othercreator@example.com');
        $this->userToken($client, 'othermember@example.com');
        [$otherPlanningId, $otherTeamId] = $this->createPlanningWithTeam($client, $other, 'Fellows');
        $this->addMemberAs($client, $other, $otherPlanningId, $otherTeamId, 'othermember@example.com', 'MEMBER');
        $otherMemberRowId = $this->memberIdOf($client, ['planningId' => $otherPlanningId, 'teamId' => $otherTeamId, 'creator' => $other], 'othermember@example.com');

        // Their manager may not read our planning …
        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/collection-status", token: $other);
        self::assertResponseStatusCodeSame(403);
        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/members/{$aliceRowId}/availability-status", token: $other);
        self::assertResponseStatusCodeSame(403);
        // … and our manager cannot reach their member through *our* planning: 404, existence not confirmed.
        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/members/{$otherMemberRowId}/availability-status", token: $s['creator']);
        self::assertResponseStatusCodeSame(404);
        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/members/00000000-0000-7000-8000-000000000000/availability-status", token: $s['creator']);
        self::assertResponseStatusCodeSame(404);
    }

    // --- availabilityDeadline: informative, settable, never blocking --------

    public function testTheAvailabilityDeadlineCanBeCreatedChangedAndCleared(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $url = "/api/plannings/{$s['planningId']}/settings";

        self::assertNull($this->collectionStatus($client, $s)['availabilityDeadline']);

        $set = $this->api($client, 'PATCH', $url, ['availabilityDeadline' => '2026-12-25'], $s['admin']);
        self::assertResponseIsSuccessful();
        self::assertSame('2026-12-25', $set['availabilityDeadline']);
        self::assertNull($set['deadlineOverdueDays']);
        self::assertSame('2026-12-25', $this->collectionStatus($client, $s)['availabilityDeadline']);

        $this->api($client, 'PATCH', $url, ['availabilityDeadline' => '2027-01-15'], $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame('2027-01-15', $this->collectionStatus($client, $s)['availabilityDeadline']);
        // The per-collection deadline (existing mechanism) is the very same value: nothing is duplicated.
        $collection = $this->api($client, 'GET', "/api/availability-collections/{$s['collectionId']}", token: $s['creator']);
        self::assertSame('2027-01-15', $collection['deadline']);

        $cleared = $this->api($client, 'PATCH', $url, ['availabilityDeadline' => null], $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertNull($cleared['availabilityDeadline']);
    }

    public function testSettingsAreReservedToManagersAndValidated(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $url = "/api/plannings/{$s['planningId']}/settings";

        $this->api($client, 'PATCH', $url, ['availabilityDeadline' => '2026-12-25'], $s['alice']);
        self::assertResponseStatusCodeSame(403);
        $this->api($client, 'PATCH', $url, ['availabilityDeadline' => '2026-12-25'], $s['outsider']);
        self::assertResponseStatusCodeSame(403);

        $this->api($client, 'PATCH', $url, [], $s['creator']);
        self::assertResponseStatusCodeSame(422, 'An empty body must not silently clear the deadline.');
        $this->api($client, 'PATCH', $url, ['availabilityDeadline' => 'soon'], $s['creator']);
        self::assertResponseStatusCodeSame(422);
        $unknown = $this->api($client, 'PATCH', $url, ['availabilityDeadline' => '2026-12-25', 'lockedAt' => '2026-12-26'], $s['creator']);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('lockedAt', $unknown['violations']);
        $this->api($client, 'PATCH', $url, ['availabilityDeadline' => '2026-12-01'], $s['creator']);
        self::assertResponseStatusCodeSame(422, 'A deadline cannot be *set* in the past (it becomes past by itself as time goes by).');
    }

    public function testSettingTheDeadlineWithNoOpenCollectionIsRefused(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/close", [], $s['creator']);
        self::assertResponseIsSuccessful();

        $this->api($client, 'PATCH', "/api/plannings/{$s['planningId']}/settings", ['availabilityDeadline' => '2026-12-25'], $s['creator']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAPassedDeadlineIsOnlyAWarningAndBlocksNothing(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->api($client, 'PATCH', "/api/plannings/{$s['planningId']}/settings", ['availabilityDeadline' => '2026-12-20'], $s['creator']);
        self::assertResponseIsSuccessful();

        // Time passes: the deadline is now 16 days old.
        self::mockTime('2027-01-05 09:00:00 Europe/Brussels');
        $status = $this->collectionStatus($client, $s);
        self::assertSame('2026-12-20', $status['availabilityDeadline']);
        self::assertSame(16, $status['deadlineOverdueDays']);

        // Alice can still add, edit and delete unavailabilities …
        $created = $this->declareRange($client, $s['alice'], '2027-02-10', '2027-02-12');
        $this->api($client, 'PATCH', "/api/me/calendar/{$created['stableId']}", [
            'type' => 'UNAVAILABLE',
            'startsAt' => (new \DateTimeImmutable('2027-02-10 00:00:00', new \DateTimeZone('Europe/Brussels')))->format(\DATE_ATOM),
            'endsAt' => (new \DateTimeImmutable('2027-02-14 00:00:00', new \DateTimeZone('Europe/Brussels')))->format(\DATE_ATOM),
        ], $s['alice']);
        self::assertResponseIsSuccessful();
        $extra = $this->declareRange($client, $s['alice'], '2027-03-10', '2027-03-11');
        $this->api($client, 'DELETE', "/api/me/calendar/{$extra['stableId']}", token: $s['alice']);
        self::assertResponseStatusCodeSame(204);
        // … can still confirm …
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", [], $s['alice']);
        self::assertResponseIsSuccessful();
        $this->api($client, 'GET', '/api/me/calendar', token: $s['alice']);
        self::assertResponseIsSuccessful();
        // … and a manager can still move the deadline forward.
        $moved = $this->api($client, 'PATCH', "/api/plannings/{$s['planningId']}/settings", ['availabilityDeadline' => '2027-01-31'], $s['admin']);
        self::assertResponseIsSuccessful();
        self::assertSame('2027-01-31', $moved['availabilityDeadline']);
        self::assertNull($moved['deadlineOverdueDays']);
    }
    // --- several collections / closed collections ----------------------------

    public function testAnExtensionMakesEverybodyPendingAgainForTheNewWindowOnly(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", ['noUnavailability' => true], $s['alice']);
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", [], $s['bob']);

        // The planning is extended to 2027-07-01: a second collection opens for May–June only.
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/extensions", ['endsAt' => '2027-07-01', 'deadline' => '2027-01-31'], $s['creator']);
        self::assertResponseIsSuccessful();

        $status = $this->collectionStatus($client, $s);
        self::assertSame(2, $status['openCollectionCount']);
        self::assertSame('2027-06-30', $status['planning']['lastDay']);
        // Alice and bob answered the first window, not the new one: they are pending again, for one window.
        $alice = $this->rowOf($status, 'alice@example.com');
        self::assertSame('PENDING', $alice['collectionState']);
        self::assertSame(1, $alice['pendingCollectionCount']);
        self::assertSame(0, $status['summary']['confirmedCount']);
        self::assertSame('2027-01-31', $status['availabilityDeadline']);

        // Setting the planning deadline sets it on every open window, and the latest one is reported.
        $this->api($client, 'PATCH', "/api/plannings/{$s['planningId']}/settings", ['availabilityDeadline' => '2027-02-15'], $s['creator']);
        self::assertResponseIsSuccessful();
        $collections = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/availability-collections", token: $s['creator']);
        self::assertSame(['2027-02-15', '2027-02-15'], array_column($collections, 'deadline'));

        // Confirming the new window brings alice back to confirmed.
        $newWindow = array_values(array_filter($collections, static fn (array $c): bool => '2027-05-01' === $c['startsAt']))[0];
        $this->api($client, 'POST', "/api/availability-collections/{$newWindow['stableId']}/acknowledge", ['noUnavailability' => true], $s['alice']);
        self::assertResponseIsSuccessful();
        self::assertSame('ACKNOWLEDGED', $this->rowOf($this->collectionStatus($client, $s), 'alice@example.com')['collectionState']);
    }

    public function testAClosedCollectionKeepsReadingAsItEnded(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", ['noUnavailability' => true], $s['alice']);
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/close", [], $s['creator']);
        self::assertResponseIsSuccessful();

        $status = $this->collectionStatus($client, $s);

        self::assertSame(0, $status['openCollectionCount']);
        self::assertNull($status['availabilityDeadline']);
        self::assertSame(1, $status['summary']['confirmedCount']);
        self::assertSame(2, $status['summary']['pendingCount'], 'People who never answered before the closing stay listed as pending.');
    }
}
