<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\PlanningCycleTestHelpers;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D214-D217, through the real endpoints, the real job
 * runner and the real OR-Tools solver: the personal calendar stays editable
 * at any time; a generation uses exactly what its snapshot captured; a later
 * change never touches the assignments nor an earlier snapshot, is said to
 * the person (impacts) and is journaled; the manager reads the whole story.
 *
 * Fixture (pilotScenario): planning 2027-01-01 → 2027-05-01, primary line
 * "Seniors" with admin/alice/bob, Europe/Brussels. Duties of period 1:
 * Thu 11 Feb, Mon 15 Mar and Sat 20 Mar 2027, 08:00 → 20:00. Real time (no
 * mocked clock): the snapshots are dated by the real clock too, and a change
 * is kept to the second — a pause separates the changes from a capture.
 */
final class AvailabilityTraceabilityTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use PlanningCycleTestHelpers;

    private const REST = ['legalMinRestEnabled' => true, 'legalMinRestHours' => 11];

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
        $this->addDuties(1, [['2027-02-11 08:00', '2027-02-11 20:00'], ['2027-03-15 08:00', '2027-03-15 20:00'], ['2027-03-20 08:00', '2027-03-20 20:00']]);
    }

    // --- helpers ---------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function declare(string $who, string $from, string $to, string $type = 'UNAVAILABLE'): array
    {
        $body = $this->api($this->client, 'POST', '/api/me/calendar', ['type' => $type, 'startsAt' => self::local($from), 'endsAt' => self::local($to)], $this->s[$who]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        return $body;
    }

    /** @return array<string, mixed> */
    private function move(string $who, string $stableId, string $from, string $to, string $type = 'UNAVAILABLE'): array
    {
        $body = $this->api($this->client, 'PATCH', "/api/me/calendar/{$stableId}", ['type' => $type, 'startsAt' => self::local($from), 'endsAt' => self::local($to)], $this->s[$who]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $body;
    }

    private static function local(string $day): string
    {
        return (new \DateTimeImmutable(str_contains($day, ' ') ? $day : $day.' 00:00:00', new \DateTimeZone('Europe/Brussels')))->format(\DATE_ATOM);
    }

    /** @return list<array<string, mixed>> */
    private function history(?string $memberEmail = null, ?string $token = null): array
    {
        $query = null === $memberEmail ? '' : '?member='.$this->memberStableIdIn($this->s['planningId'], $memberEmail);
        $body = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/history{$query}", token: $token ?? $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $body['entries'];
    }

    /** What the snapshots hold of $email's calendar: one "generation#: from→to" per captured row. */
    private function captured(string $email): array
    {
        return $this->db()->fetchFirstColumn(<<<'SQL'
            SELECT g.id || ': ' || to_char(a.starts_at AT TIME ZONE 'Europe/Brussels', 'YYYY-MM-DD') || '→' || to_char(a.ends_at AT TIME ZONE 'Europe/Brussels', 'YYYY-MM-DD')
            FROM planning_snapshot_availability_periods a
            JOIN planning_snapshot_members m ON m.id = a.snapshot_member_id
            JOIN planning_snapshots s ON s.id = m.snapshot_id
            JOIN planning_generations g ON g.id = s.generation_id
            JOIN users u ON u.stable_id = m.source_user_stable_id
            WHERE u.email = :email ORDER BY g.id, a.starts_at
        SQL, ['email' => $email]);
    }

    /** @return list<int> */
    private function generationIds(): array
    {
        return array_map('intval', $this->db()->fetchFirstColumn('SELECT id FROM planning_generations ORDER BY id'));
    }

    /** A pause so that the next change is never in the same second as the capture before it. */
    private static function nextSecond(): void
    {
        usleep(1_100_000);
    }

    // --- the snapshot is the truth of a generation (scenarios 2, 3, 4, 11) ----------------------

    public function testAGenerationUsesWhatItsSnapshotCapturedAndNothingLater(): void
    {
        // 2. Entered long before anything: Alice's absence in February is there when the period is generated.
        $early = $this->declare('alice', '2027-02-10', '2027-02-12');
        // 3. Moved just before the capture: the new dates are the ones used.
        $this->move('alice', $early['stableId'], '2027-02-10', '2027-02-13');
        self::nextSecond();

        $this->generatePeriod(1, self::REST);
        [$first] = $this->generationIds();
        self::assertSame(["{$first}: 2027-02-10→2027-02-13"], $this->captured('alice@example.com'));
        self::assertNotSame('alice@example.com', $this->holderOf($this->dutyIn(1, '2027-02-11')), 'the captured absence was used');
        $assignments = $this->fingerprint(1);

        // 4. After the capture: recorded, but neither the snapshot nor the assignments change.
        self::nextSecond();
        $late = $this->declare('bob', '2027-03-15', '2027-03-16');
        $this->move('alice', $early['stableId'], '2027-02-09', '2027-02-13');
        self::assertSame(["{$first}: 2027-02-10→2027-02-13"], $this->captured('alice@example.com'), 'the old snapshot keeps what it really used');
        self::assertSame([], $this->captured('bob@example.com'));
        self::assertSame($assignments, $this->fingerprint(1), 'no automatic change of any assignment');

        // 11. A new generation captures the calendar as it is now, and the first one is untouched.
        self::nextSecond();
        $this->generatePeriod(1, self::REST);
        [, $second] = $this->generationIds();
        self::assertSame(["{$first}: 2027-02-10→2027-02-13", "{$second}: 2027-02-09→2027-02-13"], $this->captured('alice@example.com'));
        self::assertSame(["{$second}: 2027-03-15→2027-03-16"], $this->captured('bob@example.com'));
        self::assertNotSame('bob@example.com', $this->holderOf($this->dutyIn(1, '2027-03-15')));

        // The history says it, from the facts: the late change came after generation 1 and was used by generation 2.
        $bob = array_values(array_filter($this->history(), static fn (array $e): bool => 'availability' === $e['kind'] && $e['availabilityStableId'] === $late['stableId']));
        self::assertCount(1, $bob);
        self::assertSame('CREATED', $bob[0]['action']);
        self::assertNotNull($bob[0]['afterGeneration']);
        self::assertCount(1, $bob[0]['usedBy']);
        self::assertNotSame($bob[0]['afterGeneration']['generationStableId'], $bob[0]['usedBy'][0]['generationStableId']);

        $aliceEvents = array_values(array_filter($this->history('alice@example.com'), static fn (array $e): bool => 'availability' === $e['kind']));
        self::assertSame(['CREATED', 'RESCHEDULED', 'RESCHEDULED'], array_column($aliceEvents, 'action'));
        self::assertNull($aliceEvents[0]['afterGeneration']);
        self::assertSame([], $aliceEvents[0]['usedBy'], 'the creation\'s own dates were changed before the capture: never used as such');
        self::assertCount(1, $aliceEvents[1]['usedBy'], 'the dates moved just before the capture are those generation 1 used');
        self::assertNotNull($aliceEvents[2]['afterGeneration'], 'moved after generation 1…');
        self::assertCount(1, $aliceEvents[2]['usedBy'], '…and used by generation 2');
    }

    // --- after a generation: told, never applied (scenarios 5, 6, 7, 8, 10) ---------------------

    public function testAnAbsenceAddedAfterAGenerationIsRecordedAndOnlyTold(): void
    {
        $this->generatePeriod(1, self::REST);
        $before = $this->fingerprint(1);

        // 5. Generated, not published: the planning is named, never a draft duty.
        $added = $this->declare('alice', '2027-03-15', '2027-03-16');
        self::assertCount(1, $added['impacts']);
        $impact = $added['impacts'][0];
        self::assertSame($this->s['planningId'], $impact['planningStableId']);
        self::assertFalse($impact['published']);
        self::assertNotNull($impact['generatedAt']);
        self::assertSame(['2027-03-15', '2027-03-15'], [$impact['from'], $impact['to']]);
        self::assertSame([], $impact['conflicts'], 'an unpublished draft never shows its duties to a member (D204)');
        self::assertSame($before, $this->fingerprint(1), 'nothing changed in the planning');

        // A day no generated planning covers: no false alarm.
        self::assertSame([], $this->declare('alice', '2027-06-10', '2027-06-12')['impacts']);
        // A preference never warns.
        self::assertSame([], $this->declare('bob', '2027-03-01', '2027-03-02', 'PREFER_DUTY')['impacts']);

        // 6/7. Published: a duty held on these days is named, and stays held.
        $this->publishPeriod(1);
        $published = $this->fingerprint(1);
        $holder = $this->holderOf($this->dutyIn(1, '2027-03-20'));
        self::assertNotNull($holder);
        $who = array_search($holder, ['admin' => 'admin@example.com', 'alice' => 'alice@example.com', 'bob' => 'bob@example.com'], true);
        $onDuty = $this->declare($who, '2027-03-20', '2027-03-21');
        self::assertTrue($onDuty['impacts'][0]['published']);
        self::assertCount(1, $onDuty['impacts'][0]['conflicts']);
        self::assertSame(['2027-03-20'], $onDuty['impacts'][0]['conflicts'][0]['dates']);
        self::assertSame($holder, $this->holderOf($this->dutyIn(1, '2027-03-20')), 'the duty stays assigned');

        // 8. Published, but no duty of hers that day: informative only.
        $other = 'alice' === $who ? 'bob' : 'alice';
        $free = $this->declare($other, '2027-03-20', '2027-03-21');
        self::assertCount(1, $free['impacts']);
        self::assertSame([], $free['impacts'][0]['conflicts']);
        self::assertSame($published, $this->fingerprint(1));

        // Moving it onto other days of the period: the days added and the days given back are both told.
        $moved = $this->move($other, $free['stableId'], '2027-03-22', '2027-03-23');
        self::assertCount(1, $moved['impacts']);
        self::assertSame(['2027-03-22'], $moved['impacts'][0]['addedDays']);
        self::assertSame(['2027-03-20'], $moved['impacts'][0]['releasedDays']);

        // Each event keeps what it met at that moment.
        $events = array_values(array_filter($this->history($holder), static fn (array $e): bool => 'availability' === $e['kind']));
        self::assertCount(1, end($events)['impacts'][0]['conflicts']);
    }

    /**
     * docs/decisions.md D218: shrinking, moving, turning into a preference or deleting an absence on the
     * days of a planning already generated is told too — the personal calendar changed, the generated
     * planning did not. Never refused, nothing reassigned, no snapshot touched, journaled; and nothing
     * said when no generated planning is concerned.
     */
    public function testShrinkingMovingOrDeletingAnAbsenceAfterAGenerationIsToldNeverApplied(): void
    {
        // Before any generation: nothing to say, whatever the change.
        $early = $this->declare('alice', '2027-03-01', '2027-03-03');
        self::assertSame([], $this->move('alice', $early['stableId'], '2027-03-01', '2027-03-02')['impacts']);
        self::assertSame(['impacts' => []], $this->api($this->client, 'DELETE', "/api/me/calendar/{$early['stableId']}", token: $this->s['alice']));
        self::assertResponseStatusCodeSame(200);

        $absence = $this->declare('alice', '2027-03-15', '2027-03-18');
        $kept = $this->declare('alice', '2027-03-25', '2027-03-27');
        self::nextSecond();
        $this->generatePeriod(1, self::REST);
        $this->publishPeriod(1);
        [$generation] = $this->generationIds();
        $captured = $this->captured('alice@example.com');
        self::assertSame(["{$generation}: 2027-03-15→2027-03-18", "{$generation}: 2027-03-25→2027-03-27"], $captured);
        $assignments = $this->fingerprint(1);
        self::nextSecond();

        // Shrunk: the day given back is told, with the planning and its generation.
        $shrunk = $this->move('alice', $absence['stableId'], '2027-03-15', '2027-03-17');
        self::assertCount(1, $shrunk['impacts']);
        $impact = $shrunk['impacts'][0];
        self::assertSame($this->s['planningId'], $impact['planningStableId']);
        self::assertTrue($impact['published']);
        self::assertNotNull($impact['generatedAt']);
        self::assertSame([], $impact['addedDays']);
        self::assertSame(['2027-03-17'], $impact['releasedDays']);
        self::assertSame(['2027-03-17', '2027-03-17'], [$impact['from'], $impact['to']]);
        self::assertSame([], $impact['conflicts'], 'a day given back never clashes with a duty');

        // Moved: both sides.
        $moved = $this->move('alice', $absence['stableId'], '2027-03-22', '2027-03-24');
        self::assertSame(['2027-03-22', '2027-03-23'], $moved['impacts'][0]['addedDays']);
        self::assertSame(['2027-03-15', '2027-03-16'], $moved['impacts'][0]['releasedDays']);

        // Turned into a preference: its days are given back.
        $preference = $this->move('alice', $kept['stableId'], '2027-03-25', '2027-03-27', 'PREFER_DUTY');
        self::assertSame(['2027-03-25', '2027-03-26'], $preference['impacts'][0]['releasedDays']);
        self::assertSame([], $preference['impacts'][0]['addedDays']);
        // Deleting a preference says nothing.
        self::assertSame(['impacts' => []], $this->api($this->client, 'DELETE', "/api/me/calendar/{$kept['stableId']}", token: $this->s['alice']));

        // Deleted: never refused, the days given back are told.
        $deleted = $this->api($this->client, 'DELETE', "/api/me/calendar/{$absence['stableId']}", token: $this->s['alice']);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $deleted['impacts']);
        self::assertSame(['2027-03-22', '2027-03-23'], $deleted['impacts'][0]['releasedDays']);
        self::assertSame([], $deleted['impacts'][0]['addedDays']);
        self::assertSame([], $this->api($this->client, 'GET', '/api/me/calendar', token: $this->s['alice']));

        // Nothing applied: same assignments, same snapshot, no new generation.
        self::assertSame($assignments, $this->fingerprint(1));
        self::assertSame($captured, $this->captured('alice@example.com'));
        self::assertSame([$generation], $this->generationIds());

        // Outside any generated planning: no false alarm on a deletion either.
        $june = $this->declare('alice', '2027-06-10', '2027-06-12');
        self::assertSame([], $this->move('alice', $june['stableId'], '2027-06-10', '2027-06-11')['impacts']);
        self::assertSame(['impacts' => []], $this->api($this->client, 'DELETE', "/api/me/calendar/{$june['stableId']}", token: $this->s['alice']));

        // Journaled with what each change met; the history keeps it after the deletion.
        $events = array_values(array_filter($this->history('alice@example.com'), static fn (array $e): bool => 'availability' === $e['kind'] && $e['availabilityStableId'] === $absence['stableId']));
        self::assertSame(['CREATED', 'RESCHEDULED', 'RESCHEDULED', 'DELETED'], array_column($events, 'action'));
        self::assertSame([], $events[0]['impacts'], 'created before the generation');
        self::assertSame(['2027-03-17'], $events[1]['impacts'][0]['releasedDays']);
        self::assertSame(['2027-03-22', '2027-03-23'], $events[3]['impacts'][0]['releasedDays']);
        self::assertNotNull($events[3]['afterGeneration']);
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM user_availability_events WHERE action = 'DELETED' AND availability_stable_id = ? AND impacts IS NOT NULL", [$absence['stableId']]));
    }

    public function testOneAbsenceMeetingTwoGeneratedPlanningsNamesBoth(): void
    {
        $this->generatePeriod(1, self::REST);
        // Another planning Alice belongs to, its period generated too.
        $otherCreator = $this->userToken($this->client, 'other.creator@example.com');
        [$otherPlanning, $otherTeam] = $this->createPlanningWithTeam($this->client, $otherCreator, 'Fellows');
        $this->addMemberAs($this->client, $otherCreator, $otherPlanning, $otherTeam, 'alice@example.com', 'MEMBER');
        $this->db()->executeStatement("UPDATE planning_periods SET status = 'GENERATED' WHERE planning_line_id IN (SELECT l.id FROM planning_lines l JOIN plannings p ON p.id = l.planning_id WHERE p.stable_id = ?)", [$otherPlanning]);

        $added = $this->declare('alice', '2027-03-15', '2027-03-16');

        self::assertEqualsCanonicalizing([$this->s['planningId'], $otherPlanning], array_column($added['impacts'], 'planningStableId'));
        // Bob is not in the other planning: only his own is named.
        self::assertSame([$this->s['planningId']], array_column($this->declare('bob', '2027-03-15', '2027-03-16')['impacts'], 'planningStableId'));
    }

    // --- the journal (scenarios 9, 13, 14) -------------------------------------------------------

    public function testTheJournalKeepsEveryChangeOnceEvenAfterADeletion(): void
    {
        $period = $this->declare('alice', '2027-02-10', '2027-02-12');
        // The same values again (a retried request): nothing written.
        $this->move('alice', $period['stableId'], '2027-02-10', '2027-02-12');
        $this->move('alice', $period['stableId'], '2027-02-10', '2027-02-14');
        $this->api($this->client, 'DELETE', "/api/me/calendar/{$period['stableId']}", token: $this->s['alice']);
        self::assertResponseStatusCodeSame(200);

        $rows = $this->db()->fetchAllAssociative("SELECT e.action, e.origin, a.email AS actor, e.previous_ends_at IS NOT NULL AS has_previous, e.new_ends_at IS NOT NULL AS has_new
            FROM user_availability_events e JOIN users u ON u.id = e.user_id LEFT JOIN users a ON a.id = e.actor_id WHERE u.email = 'alice@example.com' ORDER BY e.id");
        self::assertSame([
            ['action' => 'CREATED', 'origin' => 'USER', 'actor' => 'alice@example.com', 'has_previous' => false, 'has_new' => true],
            ['action' => 'RESCHEDULED', 'origin' => 'USER', 'actor' => 'alice@example.com', 'has_previous' => true, 'has_new' => true],
            ['action' => 'DELETED', 'origin' => 'USER', 'actor' => 'alice@example.com', 'has_previous' => true, 'has_new' => false],
        ], $rows);

        // The deleted entry's story is still told, in order, with its exact values.
        $events = array_values(array_filter($this->history('alice@example.com'), static fn (array $e): bool => 'availability' === $e['kind']));
        self::assertSame(['CREATED', 'RESCHEDULED', 'DELETED'], array_column($events, 'action'));
        self::assertSame(array_column($events, 'at'), (static function (array $at): array {
            sort($at);

            return $at;
        })(array_column($events, 'at')));
        self::assertSame(strtotime(self::local('2027-02-12')), strtotime($events[1]['previous']['endsAt']), 'the exact previous end');
        self::assertSame(strtotime(self::local('2027-02-14')), strtotime($events[1]['new']['endsAt']), 'the exact new end');
        self::assertNull($events[2]['new']);
        self::assertSame(array_unique(array_column($events, 'eventStableId')), array_column($events, 'eventStableId'));
    }

    public function testTheJournalIsAppendOnly(): void
    {
        $this->declare('alice', '2027-02-10', '2027-02-12');
        $connection = $this->db();

        foreach (["UPDATE user_availability_events SET action = 'DELETED'", 'DELETE FROM user_availability_events'] as $sql) {
            $connection->executeStatement('SAVEPOINT append_only_check');
            try {
                $connection->executeStatement($sql);
                self::fail("`{$sql}` must be refused.");
            } catch (DbalException $exception) {
                self::assertStringContainsString('append-only', $exception->getMessage());
                $connection->executeStatement('ROLLBACK TO SAVEPOINT append_only_check');
            }
        }
    }

    public function testAFailedWriteLeavesNoEvent(): void
    {
        $this->declare('alice', '2027-02-10', '2027-02-12');
        // Overlapping its own entry: refused, and nothing journaled.
        $this->api($this->client, 'POST', '/api/me/calendar', ['type' => 'UNAVAILABLE', 'startsAt' => self::local('2027-02-11'), 'endsAt' => self::local('2027-02-13')], $this->s['alice']);
        self::assertResponseStatusCodeSame(409);

        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM user_availability_events'));
    }

    // --- the history tells reassignments apart, and is the manager's only (scenarios 14, 15) ----

    public function testTheHistoryTellsDeclarationsGenerationsAndReassignmentsApartInOrder(): void
    {
        $this->declare('alice', '2027-02-10', '2027-02-12');
        self::nextSecond();
        $this->generatePeriod(1, self::REST);
        $this->publishPeriod(1);
        self::nextSecond();
        $this->declare('bob', '2027-03-15', '2027-03-16');
        $duty = $this->dutyIn(1, '2027-03-15');
        $target = 'bob@example.com' === $this->holderOf($duty) ? 'admin@example.com' : 'alice@example.com';
        $this->forceHolders([$duty => $target]);

        $entries = $this->history();
        self::assertSame(array_column($entries, 'at'), (static function (array $at): array {
            sort($at);

            return $at;
        })(array_column($entries, 'at')), 'chronological');
        $kinds = array_column($entries, 'kind');
        self::assertSame('availability', $kinds[0]);
        self::assertSame('generation', $kinds[1]);
        self::assertContains('assignment', $kinds);

        $generation = $entries[1];
        self::assertSame('COMPLETED', $generation['status']);
        self::assertNotNull($generation['capturedAt']);
        self::assertNotNull($generation['finishedAt']);
        self::assertSame('creator@example.com', $this->db()->fetchOne('SELECT email FROM users WHERE stable_id = ?', [$generation['launchedBy']['stableId']]));
        self::assertSame(1, $generation['capturedUnavailabilityCount']);

        $assignment = array_values(array_filter($entries, static fn (array $e): bool => 'assignment' === $e['kind']));
        self::assertSame('2027-03-15', end($assignment)['duty']['date']);
        self::assertTrue(end($assignment)['wasPublished']);
        self::assertFalse(end($assignment)['swap']);

        // Narrowed to Bob: his declaration, the generation (with what it captured of him), his duties only.
        $bob = $this->history('bob@example.com');
        foreach ($bob as $entry) {
            if ('availability' === $entry['kind']) {
                self::assertSame('Test', $entry['user']['firstName']);
            }
            if ('generation' === $entry['kind']) {
                self::assertSame([], $entry['capturedUnavailabilities'], 'Bob had declared nothing when it was generated');
            }
        }
    }

    public function testOnlyThoseWhoManageTheAvailabilitiesReadTheHistory(): void
    {
        $url = "/api/plannings/{$this->s['planningId']}/history";

        foreach (['creator', 'admin'] as $who) {
            $this->api($this->client, 'GET', $url, token: $this->s[$who]);
            self::assertResponseIsSuccessful();
        }
        foreach (['alice', 'outsider'] as $who) {
            $this->api($this->client, 'GET', $url, token: $this->s[$who]);
            self::assertResponseStatusCodeSame(403);
        }
        $this->api($this->client, 'GET', $url);
        self::assertResponseStatusCodeSame(401);

        // A member of another planning is never reachable through this one.
        $otherCreator = $this->userToken($this->client, 'other.creator@example.com');
        [$otherPlanning, $otherTeam] = $this->createPlanningWithTeam($this->client, $otherCreator, 'Fellows');
        $this->addMemberAs($this->client, $otherCreator, $otherPlanning, $otherTeam, 'outsider@example.com', 'MEMBER');
        $foreign = (string) $this->db()->fetchOne("SELECT tm.stable_id FROM planning_team_members tm JOIN users u ON u.id = tm.user_id WHERE u.email = 'outsider@example.com'");
        $this->api($this->client, 'GET', "{$url}?member={$foreign}", token: $this->s['creator']);
        self::assertResponseStatusCodeSame(404);
        // …and the other planning's manager does not read ours.
        $this->api($this->client, 'GET', $url, token: $otherCreator);
        self::assertResponseStatusCodeSame(403);

        // A manager reads a participant's calendar only on the period's dates.
        $this->declare('alice', '2027-06-10', '2027-06-12');
        self::assertSame([], array_values(array_filter($this->history('alice@example.com'), static fn (array $e): bool => 'availability' === $e['kind'])));
    }
}
