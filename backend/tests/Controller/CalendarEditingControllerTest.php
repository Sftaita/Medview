<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\DutyAssignmentEventRepository;
use App\Repository\PlanningRepository;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Editing the generated calendar (docs/decisions.md D144, D147): replace or
 * remove the holder of a duty or of a whole block, candidates restricted to
 * the duty's own line and to people really assignable, server-side
 * revalidation, serialized concurrent writes, and the management right the
 * creator can grant.
 */
final class CalendarEditingControllerTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;

    private const STANDALONE = [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07'], ['2027-01-07', '2027-01-08']];
    private const BLOCK = ['2027-01-08', '2027-01-09', '2027-01-10'];

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    // --- replace ---------------------------------------------------------------------

    public function testReplacingAStandaloneDutyChangesThatDutyOnly(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $before = $this->currentCalendar($s['planningId']);

        $holder = $before['0|2027-01-06|ONCALL'];
        $replacement = 'alice@example.com' === $holder ? 'bob@example.com' : 'alice@example.com';
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'), $replacement);
        self::assertResponseIsSuccessful();

        $after = $this->currentCalendar($s['planningId']);
        self::assertSame($replacement, $after['0|2027-01-06|ONCALL']);
        unset($before['0|2027-01-06|ONCALL'], $after['0|2027-01-06|ONCALL']);
        self::assertSame($before, $after);
    }

    public function testACandidateOfAnotherLineIsNeverProposedAndIsRefused(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->addSecondaryLine($client, $s, ['junior@example.com']);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->prepareLine($s['planningId'], [['2027-01-06', '2027-01-07']], lineIndex: 1);
        $this->generate($client, $s);

        $duty = $this->dutyOn($s['planningId'], '2027-01-05');
        $juniorMemberId = $this->memberStableIdIn($s['planningId'], 'junior@example.com', 1);
        $view = $this->candidatesFor($client, $s, $duty);
        self::assertResponseIsSuccessful();
        self::assertNotContains($juniorMemberId, array_column($view['candidates'], 'teamMemberStableId'));

        $response = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$duty}/reassign", [
            'teamMemberStableId' => $juniorMemberId,
            'expectedCurrentTeamMemberStableId' => $view['currentTeamMemberStableId'],
        ], $s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('invalid_candidate', $response['error']);
        self::assertStringContainsString('NOT_A_LINE_MEMBER', $response['message']);
    }

    public function testACandidateUnavailableOnOneDayOfABlockIsNeverProposedForTheBlock(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], [], self::BLOCK);
        $this->generate($client, $s);
        $calendar = $this->currentCalendar($s['planningId']);
        $holder = array_values($calendar)[0];
        $other = 'alice@example.com' === $holder ? 'bob@example.com' : 'alice@example.com';

        // Only the second day of the block.
        $this->declareRange($client, $s[explode('@', $other)[0]], '2027-01-09', '2027-01-10');

        $view = $this->candidatesFor($client, $s, $this->dutyOn($s['planningId'], '2027-01-08'));
        self::assertCount(2, $view['blockDuties'], 'The editor always works on the whole block.');
        self::assertNotContains($this->memberStableIdIn($s['planningId'], $other), array_column($view['candidates'], 'teamMemberStableId'));

        $response = $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-08'), $other);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('UNAVAILABLE', $response['message']);
    }

    public function testEditingAnyDayOfABlockEditsTheWholeBlock(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], [], self::BLOCK);
        $this->generate($client, $s);
        $holder = array_values($this->currentCalendar($s['planningId']))[0];
        $replacement = 'alice@example.com' === $holder ? 'bob@example.com' : 'alice@example.com';

        // Clicked on the *second* day.
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-09'), $replacement);
        self::assertResponseIsSuccessful();

        self::assertSame([$replacement, $replacement], array_values($this->currentCalendar($s['planningId'])));
    }

    // --- remove ----------------------------------------------------------------------

    public function testRemovingLeavesTheDutyUncoveredAndRecordsARemovalEvent(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $before = $this->currentCalendar($s['planningId']);

        $response = $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'));
        self::assertResponseIsSuccessful();
        self::assertSame('unassigned', $response['status']);

        $after = $this->currentCalendar($s['planningId']);
        self::assertNull($after['0|2027-01-06|ONCALL']);
        unset($before['0|2027-01-06|ONCALL'], $after['0|2027-01-06|ONCALL']);
        self::assertSame($before, $after, 'Nothing else moved.');

        $result = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/result", token: $s['creator']);
        $wednesday = array_values(array_filter($result['lines'][0]['duties'], static fn (array $d): bool => '2027-01-06' === $d['date']))[0];
        self::assertFalse($wednesday['covered']);
        self::assertSame(1, $result['lines'][0]['uncoveredRequiredDutyCount']);

        $events = static::getContainer()->get(DutyAssignmentEventRepository::class)->findByDuty($this->dutyEntityOn($s['planningId'], '2027-01-06'));
        self::assertCount(1, $events);
        self::assertNotNull($events[0]->getPreviousAssignment());
        self::assertNull($events[0]->getNewAssignment(), 'A removal: no new assignment.');
        self::assertFalse($events[0]->getPreviousAssignment()->isCurrent(), 'Superseded, never deleted.');
    }

    public function testRemovingABlockRemovesEveryDayOfIt(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], [], self::BLOCK);
        $this->generate($client, $s);

        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-09'));
        self::assertResponseIsSuccessful();

        self::assertSame([null, null], array_values($this->currentCalendar($s['planningId'])));
    }

    public function testRemovingWithAStaleHolderOrNothingToRemoveIsRejected(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $duty = $this->dutyOn($s['planningId'], '2027-01-06');
        $staleHolder = $this->candidatesFor($client, $s, $duty)['currentTeamMemberStableId'];

        $this->unassignDuty($client, $s, $duty);
        self::assertResponseIsSuccessful();

        $response = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$duty}/unassign", ['expectedCurrentTeamMemberStableId' => $staleHolder], $s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_reassignment', $response['error']);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$duty}/unassign", ['expectedCurrentTeamMemberStableId' => null], $s['creator']);
        self::assertResponseStatusCodeSame(422, 'The holder being removed must always be named.');
    }

    public function testOnlyAManagerCanRemove(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $duty = $this->dutyOn($s['planningId'], '2027-01-06');
        $holder = $this->candidatesFor($client, $s, $duty)['currentTeamMemberStableId'];

        foreach (['alice', 'outsider'] as $who) {
            $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$duty}/unassign", ['expectedCurrentTeamMemberStableId' => $holder], $s[$who]);
            self::assertResponseStatusCodeSame(403);
        }

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$duty}/unassign", ['expectedCurrentTeamMemberStableId' => $holder], $s['admin']);
        self::assertResponseIsSuccessful();
    }

    // --- statistics follow the current calendar -------------------------------------

    public function testStatisticsReflectAReassignmentAndARemovalImmediately(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);

        $holder = $this->currentCalendar($s['planningId'])['0|2027-01-06|ONCALL'];
        $replacement = 'alice@example.com' === $holder ? 'bob@example.com' : 'alice@example.com';
        $totalsBefore = $this->totalsByEmail($client, $s);

        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'), $replacement);
        $totals = $this->totalsByEmail($client, $s);
        self::assertSame(($totalsBefore[$replacement]['total'] ?? 0) + 1, $totals[$replacement]['total']);
        self::assertSame($totalsBefore[$holder]['total'] - 1, $totals[$holder]['total'] ?? 0);
        self::assertSame(1, $totals[$replacement]['countsByWeekday']['WED'] - ($totalsBefore[$replacement]['countsByWeekday']['WED'] ?? 0));
        self::assertEquals($totals[$replacement]['total'], $totals[$replacement]['weightedLoad'], 'Workload value 1 per duty in this fixture.');
        self::assertSame($totals[$replacement]['total'], array_sum($totals[$replacement]['countsByDutyType']));

        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'));
        $afterRemoval = $this->totalsByEmail($client, $s);
        self::assertSame($totals[$replacement]['total'] - 1, $afterRemoval[$replacement]['total'] ?? 0);
    }

    // --- concurrency ----------------------------------------------------------------

    public function testTwoSavesOfTheSameBlockNeverBothSucceed(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $duty = $this->dutyOn($s['planningId'], '2027-01-06');
        $view = $this->candidatesFor($client, $s, $duty);
        [$first, $second] = array_column($view['candidates'], 'teamMemberStableId');

        // Both managers opened the editor on the same state.
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$duty}/reassign", ['teamMemberStableId' => $first, 'expectedCurrentTeamMemberStableId' => $view['currentTeamMemberStableId']], $s['creator']);
        self::assertResponseIsSuccessful();
        $response = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$duty}/reassign", ['teamMemberStableId' => $second, 'expectedCurrentTeamMemberStableId' => $view['currentTeamMemberStableId']], $s['admin']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('stale_reassignment', $response['error']);
    }

    /**
     * The guarantee behind the test above when the two requests really
     * overlap: every calendar write takes a transaction-level advisory lock
     * per Planning *before* reading, so a second writer — here a genuinely
     * separate database connection — cannot enter while the first one holds it.
     */
    public function testTheCalendarWriteLockExcludesAConcurrentConnection(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($s['planningId']);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $em->getConnection()->beginTransaction();
        static::getContainer()->get(\App\Service\CalendarWriteLock::class)->acquire($planning);

        $other = DriverManager::getConnection($em->getConnection()->getParams());
        try {
            self::assertFalse((bool) $other->fetchOne('SELECT pg_try_advisory_xact_lock(7354, :id)', ['id' => $planning->getId()]), 'A concurrent writer must wait.');
        } finally {
            $other->close();
        }
        $em->getConnection()->rollBack();
    }

    // --- management right --------------------------------------------------------------

    public function testTheCreatorCanGrantAndWithdrawTheManagementRight(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $aliceMember = $this->memberStableIdIn($s['planningId'], 'alice@example.com');
        $roleUrl = "/api/plannings/{$s['planningId']}/teams/{$s['teamId']}/members/{$aliceMember}/role";
        $duty = $this->dutyOn($s['planningId'], '2027-01-06');

        $this->candidatesFor($client, $s, $duty, $s['alice']);
        self::assertResponseStatusCodeSame(403, 'A plain member cannot edit the calendar.');
        $detail = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['alice']);
        self::assertFalse($detail['canManageCalendar']);
        self::assertFalse($detail['canPublish']);

        $response = $this->api($client, 'PUT', $roleUrl, ['role' => 'ADMIN'], $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame('ADMIN', $response['role']);

        $detail = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['alice']);
        self::assertTrue($detail['canManageCalendar']);
        self::assertTrue($detail['canPublish']);
        self::assertTrue($detail['canGenerate']);
        self::assertFalse($detail['canManage'], 'Structure (rename, lines, members) stays the creator\'s.');
        $this->candidatesFor($client, $s, $duty, $s['alice']);
        self::assertResponseIsSuccessful();
        $this->completePlanning($client, $s, $s['alice']);
        self::assertResponseIsSuccessful();

        $this->api($client, 'PUT', $roleUrl, ['role' => 'MEMBER'], $s['creator']);
        self::assertResponseIsSuccessful();
        $this->candidatesFor($client, $s, $duty, $s['alice']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testOnlyTheCreatorChangesRolesAndOwnerIsNeverTouched(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $bobMember = $this->memberStableIdIn($s['planningId'], 'bob@example.com');
        $url = "/api/plannings/{$s['planningId']}/teams/{$s['teamId']}/members/{$bobMember}/role";

        $this->api($client, 'PUT', $url, ['role' => 'ADMIN'], $s['admin']);
        self::assertResponseStatusCodeSame(403, 'A manager cannot appoint other managers — only the creator can.');
        $this->api($client, 'PUT', $url, ['role' => 'ADMIN'], $s['alice']);
        self::assertResponseStatusCodeSame(403);

        $this->api($client, 'PUT', $url, ['role' => 'OWNER'], $s['creator']);
        self::assertResponseStatusCodeSame(422);
        $this->api($client, 'PUT', $url, ['role' => 'BOSS'], $s['creator']);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, array<string, mixed>> email → statistics row (currentPeriod, first line)
     */
    private function totalsByEmail(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, array $s): array
    {
        $stats = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/statistics", token: $s['creator']);
        self::assertResponseIsSuccessful();
        $emailByMember = [];
        foreach (['admin', 'alice', 'bob'] as $who) {
            $emailByMember[$this->memberStableIdIn($s['planningId'], "{$who}@example.com")] = "{$who}@example.com";
        }

        $byEmail = [];
        foreach ($stats['currentPeriod']['groups'][0]['members'] ?? [] as $row) {
            $byEmail[$emailByMember[$row['teamMemberStableId']]] = $row;
        }

        return $byEmail;
    }
}
