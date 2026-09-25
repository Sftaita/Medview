<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DutyPattern;
use App\Repository\DutyRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Service\DutyMaterializationService;
use App\Service\PlanningRuleSetService;
use App\Tests\PlanningPilotTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Duty counts by weekday, two scopes (docs/decisions.md D132). Audited,
 * load-bearing fact this test class exercises directly: a PlanningLine's
 * PlanningPeriod is never superseded today (D122 only ever grows it in
 * place) — so `currentPeriod` and `cumulative` are the exact same real
 * state for any given Planning. The only real mechanism producing more
 * than one PlanningPeriod under one Planning today is a second
 * PlanningLine, which several scenarios below use for that reason.
 */
final class PlanningStatisticsControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use PlanningPilotTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function statistics(KernelBrowser $client, array $s, ?string $token = null): array
    {
        $result = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/statistics", token: $token ?? $s['creator']);
        self::assertResponseIsSuccessful();

        return $result;
    }

    /**
     * @param array<string, mixed> $scope
     *
     * @return array<string, mixed>
     */
    private function memberRow(array $scope, string $groupStableId, string $teamMemberStableId): array
    {
        foreach ($scope['groups'] as $group) {
            if ($group['groupStableId'] !== $groupStableId) {
                continue;
            }
            foreach ($group['members'] as $member) {
                if ($member['teamMemberStableId'] === $teamMemberStableId) {
                    return $member;
                }
            }
        }

        self::fail("No row for {$teamMemberStableId} in group {$groupStableId}.");
    }

    public function testASinglePeriodMeansCurrentEqualsCumulative(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07']]);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $stats = $this->statistics($client, $s);

        self::assertSame($stats['currentPeriod'], $stats['cumulative'], 'D132: only one real PlanningPeriod exists per line today — the two scopes must coincide exactly.');
    }

    public function testWeekdayBucketsAndTotalAreCorrect(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        // 2027-01-05 is a Tuesday, 2027-01-06 a Wednesday.
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07']]);
        // Only bob is available: he gets both duties, one on each weekday.
        foreach (['admin', 'alice'] as $who) {
            $this->declareRange($client, $s[$who], '2027-01-05', '2027-01-07');
        }
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $stats = $this->statistics($client, $s);
        $lineId = $stats['currentPeriod']['groups'][0]['groupStableId'];
        $bobId = $this->memberIdOf($client, $s, 'bob@example.com');
        $row = $this->memberRow($stats['currentPeriod'], $lineId, $bobId);

        self::assertSame(1, $row['countsByWeekday']['TUE']);
        self::assertSame(1, $row['countsByWeekday']['WED']);
        self::assertSame(0, $row['countsByWeekday']['MON']);
        self::assertSame(2, $row['total']);
    }

    public function testABlocksTwoDaysCountOnTheirOwnRealWeekday(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        // Saturday 2027-01-09 + Sunday 2027-01-10.
        $this->prepareBlockGeneration($s['planningId'], '2027-01-09', '2027-01-09', '2027-01-10', '2027-01-10', '2027-01-11');
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $stats = $this->statistics($client, $s);
        $lineId = $stats['currentPeriod']['groups'][0]['groupStableId'];
        $group = $stats['currentPeriod']['groups'][0];
        self::assertCount(1, $group['members'], 'The whole block goes to one candidate.');
        $row = $group['members'][0];

        self::assertSame(1, $row['countsByWeekday']['SAT']);
        self::assertSame(1, $row['countsByWeekday']['SUN']);
        self::assertSame(2, $row['total'], 'A two-day block counts as 2, never 1 (§6 of the spec).');
    }

    /**
     * docs/decisions.md D137: family columns are built from whatever
     * AllocationFamily names this generation's own assignments actually
     * used — never "Week-end"/"Semaine" hardcoded — and the empty-string
     * key groups duties with no family.
     */
    public function testFamilyCountsAreDynamicAndNeverHardcoded(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($s['planningId']);
        $line = static::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->activateRuleSet(static::getContainer()->get(PlanningRuleSetService::class), $line->getPlanningTeam());
        $family = $this->createAllocationFamily($em, $line->getPlanningTeam(), 'WE', 'Week-end');
        $pattern = new DutyPattern($line->getPlanningTeam(), 'WE-PATTERN', 'Week-end', $family);
        $em->persist($pattern);
        $em->flush();

        $dutyType = $this->createDutyType($em, $line->getPlanningTeam());
        $materializationService = static::getContainer()->get(DutyMaterializationService::class);
        // One Duty belongs to the "Week-end" family, one has no family at all.
        $this->createDuty($materializationService, $line->getPlanningPeriod(), $dutyType, '2027-01-05', '2027-01-06', $pattern);
        $this->createDuty($materializationService, $line->getPlanningPeriod(), $dutyType, '2027-01-06', '2027-01-07');

        // Only bob is available: he gets both duties.
        foreach (['admin', 'alice'] as $who) {
            $this->declareRange($client, $s[$who], '2027-01-05', '2027-01-07');
        }
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $stats = $this->statistics($client, $s);
        $lineId = $stats['currentPeriod']['groups'][0]['groupStableId'];
        $bobId = $this->memberIdOf($client, $s, 'bob@example.com');
        $row = $this->memberRow($stats['currentPeriod'], $lineId, $bobId);

        self::assertSame(['Week-end', ''], array_keys($row['countsByFamily']));
        self::assertSame(1, $row['countsByFamily']['Week-end']);
        self::assertSame(1, $row['countsByFamily']['']);
        self::assertSame(2, $row['total']);
    }

    public function testSeveralLinesAreSeparateGroupsNeverMixed(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/lines", ['name' => 'Renfort'], $s['creator']);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: 0);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: 1);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $stats = $this->statistics($client, $s);
        // The second line has no members at all (never generated members for it) — real INCOMPLETE, no group entry with 0 rows expected beyond an empty members array.
        self::assertCount(2, $stats['currentPeriod']['groups'], 'Two real PlanningPeriod under the same Planning today: one per line — this is the audited real mechanism for D132.');
        $labels = array_map(static fn (array $g): string => $g['groupLabel'], $stats['currentPeriod']['groups']);
        self::assertContains('Seniors', $labels);
        self::assertContains('Renfort', $labels);
    }

    public function testTwoDifferentPlanningsAreNeverMixed(): void
    {
        $client = static::createClient();
        $s1 = $this->pilotScenario($client);
        $this->prepareGeneration($s1['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->api($client, 'POST', "/api/plannings/{$s1['planningId']}/generations", [], $s1['creator']);

        // A second, wholly independent planning.
        $creator2 = $this->userToken($client, 'creator2@example.com');
        [$planningId2, $teamId2] = $this->createPlanningWithTeam($client, $creator2, 'AutreEquipe');
        $s2 = ['planningId' => $planningId2, 'teamId' => $teamId2, 'creator' => $creator2];
        $this->prepareGeneration($planningId2, [['2027-01-05', '2027-01-06']]);
        // No member at all for planning 2's line: a real INCOMPLETE outcome, no candidate to assign.
        $this->api($client, 'POST', "/api/plannings/{$planningId2}/generations", [], $creator2);

        $stats1 = $this->statistics($client, $s1);
        $stats2 = $this->api($client, 'GET', "/api/plannings/{$planningId2}/statistics", token: $creator2);

        self::assertNotSame($stats1['currentPeriod']['groups'][0]['groupStableId'], $stats2['currentPeriod']['groups'][0]['groupStableId'] ?? null);
    }

    public function testAReassignmentUpdatesBothScopes(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $before = $this->statistics($client, $s);
        $lineId = $before['currentPeriod']['groups'][0]['groupStableId'];
        $originalMemberId = $before['currentPeriod']['groups'][0]['members'][0]['teamMemberStableId'];

        $dutyStableId = $this->onlyDutyStableIdOf($s['planningId']);
        $view = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/duties/{$dutyStableId}/reassignment-candidates", token: $s['creator']);
        $newMemberId = null;
        foreach ($view['candidates'] as $candidate) {
            if ($candidate['selectable'] && $candidate['teamMemberStableId'] !== $originalMemberId) {
                $newMemberId = $candidate['teamMemberStableId'];
                break;
            }
        }
        self::assertNotNull($newMemberId);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$dutyStableId}/reassign", [
            'teamMemberStableId' => $newMemberId,
            'expectedCurrentTeamMemberStableId' => $originalMemberId,
        ], $s['creator']);
        self::assertResponseIsSuccessful();

        $after = $this->statistics($client, $s);
        $newRow = $this->memberRow($after['currentPeriod'], $lineId, $newMemberId);
        self::assertSame(1, $newRow['total']);
        // The old assignee no longer has any row at all for this line (never a fabricated 0).
        foreach ($after['currentPeriod']['groups'][0]['members'] as $member) {
            self::assertNotSame($originalMemberId, $member['teamMemberStableId'], 'The previous assignee has zero duties left: no row, never a fake 0.');
        }
        self::assertSame($after['currentPeriod'], $after['cumulative'], 'D132: still the same single real period.');
    }

    public function testAnOlderRegeneratedGenerationIsNeverDoubleCounted(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        // Regenerate a second time for the very same line/period.
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $stats = $this->statistics($client, $s);
        $total = array_sum(array_column($stats['currentPeriod']['groups'][0]['members'], 'total'));

        self::assertSame(1, $total, 'D125: only the most recent COMPLETED generation counts — never both.');
    }

    public function testAPeriodWithNoGenerationYetHasNoGroupAtAll(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        // No prepareGeneration/launch at all.

        $stats = $this->statistics($client, $s);

        self::assertSame([], $stats['currentPeriod']['groups'], 'Never a fake empty-but-present group before any generation exists.');
    }

    public function testScopeBoundsMatchThePlanningsRealDates(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $stats = $this->statistics($client, $s);

        self::assertSame('2027-01-01', $stats['currentPeriod']['startsAt']);
        self::assertSame('2027-05-01', $stats['currentPeriod']['endsAt']);
        self::assertSame($stats['currentPeriod']['startsAt'], $stats['cumulative']['startsAt']);
        self::assertSame($stats['currentPeriod']['endsAt'], $stats['cumulative']['endsAt']);
    }

    public function testAnOutsiderCannotReadStatisticsButAMemberCan(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/statistics", token: $s['outsider']);
        self::assertResponseStatusCodeSame(403);

        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/statistics", token: $s['alice']);
        self::assertResponseIsSuccessful();
    }

    private function onlyDutyStableIdOf(string $planningStableId): string
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
        $duties = $container->get(DutyRepository::class)->findByPlanningPeriod($line->getPlanningPeriod());
        self::assertCount(1, $duties);

        return (string) $duties[0]->getStableId();
    }
}
