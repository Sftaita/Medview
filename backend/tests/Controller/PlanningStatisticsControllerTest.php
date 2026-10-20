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
 * Duty counts by weekday (docs/decisions.md D132), on a planning with a
 * single period. Since D201 (lot L5) the scopes are three distinct sets of
 * line periods counted by the same rules: `currentPeriod` (the selected —
 * here the only — period, every generated line), `cumulative` (published
 * line periods only) and `provisional` (the unpublished ones, apart; null
 * for a reader who does not manage the calendar). Several periods:
 * PlanningCycleStatisticsTest.
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

    public function testASinglePeriodIsProvisionalUntilPublishedThenTheCumulative(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07']]);
        $this->generateNow($client, $s['planningId'], $s['creator']);

        // D201: generated, not published — the same numbers, provisional, never in the published cumulative.
        $stats = $this->statistics($client, $s);
        self::assertSame(1, $stats['period']['number']);
        self::assertSame('UNPUBLISHED', $stats['currentPeriod']['groups'][0]['publication']);
        self::assertTrue($stats['currentPeriod']['groups'][0]['provisional']);
        self::assertSame([], $stats['cumulative']['groups']);
        self::assertSame([], $stats['cumulative']['periodNumbers']);
        self::assertSame($stats['currentPeriod']['groups'][0]['members'], $stats['provisional']['groups'][0]['members']);
        self::assertSame([1], $stats['provisional']['periodNumbers']);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();
        $published = $this->statistics($client, $s);
        self::assertSame($stats['currentPeriod']['groups'][0]['members'], $published['currentPeriod']['groups'][0]['members'], 'publishing changes no count');
        self::assertSame('PUBLISHED', $published['currentPeriod']['groups'][0]['publication']);
        self::assertSame($published['currentPeriod']['groups'][0]['members'], $published['cumulative']['groups'][0]['members'], 'one period: the cumulative is that period');
        self::assertSame([], $published['provisional']['groups']);
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
        $this->generateNow($client, $s['planningId'], $s['creator']);

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
        $this->generateNow($client, $s['planningId'], $s['creator']);

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
        $this->createDuty($materializationService, $line->requireSingleCyclePeriod(), $dutyType, '2027-01-05', '2027-01-06', $pattern);
        $this->createDuty($materializationService, $line->requireSingleCyclePeriod(), $dutyType, '2027-01-06', '2027-01-07');

        // Only bob is available: he gets both duties.
        foreach (['admin', 'alice'] as $who) {
            $this->declareRange($client, $s[$who], '2027-01-05', '2027-01-07');
        }
        $this->generateNow($client, $s['planningId'], $s['creator']);

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
        // Its only member is away that day (a line with no member at all is no longer generated, D195).
        $carol = $this->userToken($client, 'carol@example.com');
        $this->addMemberAs($client, $s['creator'], $s['planningId'], $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'][1]['team']['stableId'], 'carol@example.com', 'MEMBER');
        $this->declareRange($client, $carol, '2027-01-05', '2027-01-06');
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: 0);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: 1);
        self::assertSame('SUCCEEDED', $this->generateNow($client, $s['planningId'], $s['creator'])['status']);

        $stats = $this->statistics($client, $s);
        // The second line's duty is uncovered — real INCOMPLETE, its group still listed on its own.
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
        $this->generateNow($client, $s1['planningId'], $s1['creator']);

        // A second, wholly independent planning.
        $creator2 = $this->userToken($client, 'creator2@example.com');
        [$planningId2, $teamId2] = $this->createPlanningWithTeam($client, $creator2, 'AutreEquipe');
        $s2 = ['planningId' => $planningId2, 'teamId' => $teamId2, 'creator' => $creator2];
        $this->prepareGeneration($planningId2, [['2027-01-05', '2027-01-06']]);
        // Planning 2's only member is away that day: a real INCOMPLETE outcome, no candidate to assign
        // (a line with no member at all is no longer generated, docs/decisions.md D195).
        $dave = $this->userToken($client, 'dave@example.com');
        $this->addMemberAs($client, $creator2, $planningId2, $teamId2, 'dave@example.com', 'MEMBER');
        $this->declareRange($client, $dave, '2027-01-05', '2027-01-06');
        self::assertSame('SUCCEEDED', $this->generateNow($client, $planningId2, $creator2)['status']);

        $stats1 = $this->statistics($client, $s1);
        $stats2 = $this->api($client, 'GET', "/api/plannings/{$planningId2}/statistics", token: $creator2);

        self::assertNotSame($stats1['currentPeriod']['groups'][0]['groupStableId'], $stats2['currentPeriod']['groups'][0]['groupStableId'] ?? null);
    }

    public function testAReassignmentUpdatesBothScopes(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->generateNow($client, $s['planningId'], $s['creator']);

        $before = $this->statistics($client, $s);
        $lineId = $before['currentPeriod']['groups'][0]['groupStableId'];
        $originalMemberId = $before['currentPeriod']['groups'][0]['members'][0]['teamMemberStableId'];

        $dutyStableId = $this->onlyDutyStableIdOf($s['planningId']);
        $view = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/duties/{$dutyStableId}/reassignment-candidates", token: $s['creator']);
        $newMemberId = null;
        foreach ($view['candidates'] as $candidate) {
            if ($candidate['teamMemberStableId'] !== $originalMemberId) {
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
        self::assertSame($after['currentPeriod']['groups'][0]['members'], $after['provisional']['groups'][0]['members'], 'D201: not published — the provisional scope follows too.');
    }

    public function testAnOlderRegeneratedGenerationIsNeverDoubleCounted(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->generateNow($client, $s['planningId'], $s['creator']);
        // Regenerate a second time for the very same line/period.
        $this->generateNow($client, $s['planningId'], $s['creator']);

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
        $this->generateNow($client, $s['planningId'], $s['creator']);

        $stats = $this->statistics($client, $s);

        self::assertSame('2027-01-01', $stats['currentPeriod']['startsAt']);
        self::assertSame('2027-05-01', $stats['currentPeriod']['endsAt']);
        self::assertSame($stats['currentPeriod']['startsAt'], $stats['cumulative']['startsAt']);
        self::assertSame($stats['currentPeriod']['endsAt'], $stats['cumulative']['endsAt']);
    }

    public function testAnOutsiderCannotReadStatisticsAndAMemberOnlyWhatIsPublished(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->generateNow($client, $s['planningId'], $s['creator']);

        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/statistics", token: $s['outsider']);
        self::assertResponseStatusCodeSame(403);

        // D201: a plain member never reads a generation that is not published.
        $member = $this->statistics($client, $s, $s['alice']);
        self::assertSame([], $member['currentPeriod']['groups']);
        self::assertSame([], $member['cumulative']['groups']);
        self::assertNull($member['provisional']);
        self::assertSame('UNPUBLISHED', $member['period']['lines'][0]['publication']);
        self::assertNotSame([], $this->statistics($client, $s, $s['admin'])['currentPeriod']['groups'], 'a manager does');

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        $member = $this->statistics($client, $s, $s['alice']);
        self::assertSame($this->statistics($client, $s)['cumulative'], $member['cumulative'], 'published: the same numbers as a manager');
        self::assertNull($member['provisional']);
    }

    private function onlyDutyStableIdOf(string $planningStableId): string
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
        $duties = $container->get(DutyRepository::class)->findByPlanningPeriod($line->requireSingleCyclePeriod());
        self::assertCount(1, $duties);

        return (string) $duties[0]->getStableId();
    }
}
