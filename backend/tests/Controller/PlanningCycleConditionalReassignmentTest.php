<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DutyType;
use App\Entity\PlanningPeriod;
use App\Service\DutyMaterializationService;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\ConditionalLineTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A conditional line across periods, lot L4d (docs/decisions.md D197): a
 * change on the source line reports the reinforcements depending on THAT
 * block — always of the same period (docs/planning-cycles.md §5.6), never of
 * another — and writes nothing on the conditional line (D165).
 *
 * Fixture (ConditionalLineTestHelpers): main line (admin, alice, bob),
 * "Renfort" (carol, dave) depending on it; alice triggers every day, bob
 * Friday to Sunday, admin never. Period 1 is moved to end on Monday 3 May
 * (the reinforcement weekend block may not be cut), period 2 runs to 2 August.
 */
final class PlanningCycleConditionalReassignmentTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ConditionalLineTestHelpers;

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->scenario($this->client);
        $period1 = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'][0]['stableId'];
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-05-03', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", ['endsAt' => '2027-08-02', 'previousPeriodStableId' => $period1], $this->s['creator']);
        self::assertResponseStatusCodeSame(201);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function periodId(int $number): string
    {
        return (string) $this->db()->fetchOne('SELECT c.stable_id FROM planning_cycles c JOIN plannings p ON p.id = c.planning_id WHERE p.stable_id = ? AND c.number = ?', [$this->s['planningId'], $number]);
    }

    private function periodOf(int $lineIndex, int $number): PlanningPeriod
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        foreach ($this->line($this->s['planningId'], $lineIndex)->getPeriods() as $period) {
            if ($number === $period->getCycle()?->getNumber()) {
                return $period;
            }
        }

        self::fail("Line {$lineIndex} has no period {$number}.");
    }

    private function generatePeriod(int $number): void
    {
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", ['period' => $this->periodId($number)], $this->s['creator']);
        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->runQueuedPlanningJobs());
    }

    private function dutyOf(int $lineIndex, int $number, string $localDate): string
    {
        $id = $this->db()->fetchOne('SELECT stable_id FROM duties WHERE planning_period_id = ? AND local_date = ?', [$this->periodOf($lineIndex, $number)->getId(), $localDate]);
        self::assertNotFalse($id, "No duty of line {$lineIndex} on {$localDate} in period {$number}.");

        return (string) $id;
    }

    private function holderEmail(string $dutyStableId): ?string
    {
        $email = $this->db()->fetchOne('SELECT u.email FROM duty_assignments a JOIN duties d ON d.id = a.duty_id JOIN planning_team_members tm ON tm.id = a.team_member_id JOIN users u ON u.id = tm.user_id WHERE d.stable_id = ? AND a.current', [$dutyStableId]);

        return false === $email ? null : (string) $email;
    }

    /** The Renfort line's rows (every assignment, current or not) — what a change on the main line must never write. */
    private function renfortRows(): string
    {
        return (string) $this->db()->fetchOne(<<<'SQL'
            SELECT COUNT(*) || '/' || COALESCE(md5(string_agg(ROW(a.*)::text, '|' ORDER BY a.id)), '') FROM duty_assignments a
            JOIN duties d ON d.id = a.duty_id JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_lines l ON l.id = pp.planning_line_id
            WHERE l.stable_id = ?
        SQL, [$this->s['renfortLineId']]);
    }

    /** A main-line holder whose trigger status on a Tuesday differs from $holder's: alice triggers, admin never. */
    private function flip(?string $holder): string
    {
        return 'alice@example.com' === $holder ? 'admin@example.com' : 'alice@example.com';
    }

    /** @return array<string, mixed> */
    private function reassignMain(string $dutyStableId, string $email): array
    {
        $view = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/duties/{$dutyStableId}/reassignment-candidates", token: $this->s['creator']);
        if ($view['currentTeamMemberStableId'] === $this->memberStableIdIn($this->s['planningId'], $email)) {
            return ['dependentImpacts' => []];
        }

        $response = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$dutyStableId}/reassign", [
            'teamMemberStableId' => $this->memberStableIdIn($this->s['planningId'], $email),
            'expectedCurrentTeamMemberStableId' => $view['currentTeamMemberStableId'],
        ], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $response;
    }

    public function testAChangeOnTheSourceLineReportsOnlyTheReinforcementsOfItsOwnPeriod(): void
    {
        // The main line's Tuesday 4 May, in period 2 (period 1 has its own Tuesday, 5 January).
        $primary2 = $this->periodOf(0, 2);
        $type = static::getContainer()->get(EntityManagerInterface::class)->getRepository(DutyType::class)->findOneBy(['team' => $primary2->getTeam()]);
        static::getContainer()->get(DutyMaterializationService::class)->createStandaloneDuty($primary2, $type, new \DateTimeImmutable('2027-05-04'), new \DateTimeImmutable('2027-05-05'));
        $this->generatePeriod(1);
        $this->generatePeriod(2);
        $source2 = $this->dutyOf(0, 2, '2027-05-04');
        $reinforcement2 = $this->dutyOf(1, 2, '2027-05-04');
        $renfort = $this->renfortRows();

        // Changing who holds the main Tuesday of period 2 changes whether its reinforcement is required.
        $holder = $this->holderEmail($source2);
        $response = $this->reassignMain($source2, $this->flip($holder));

        self::assertSame(2, $response['period']['number']);
        self::assertSame([[$this->s['renfortLineId'], [$reinforcement2], ['2027-05-04'], true]], array_map(
            static fn (array $impact): array => [$impact['lineStableId'], $impact['dutyStableIds'], $impact['dates'], $impact['changed']],
            $response['dependentImpacts'],
        ), 'the reinforcement of 4 May — never the one of 5 January, in period 1');
        self::assertSame($renfort, $this->renfortRows(), 'reported, never acted on (D165)');

        // The same on period 1: its own Tuesday's reinforcement only.
        $source1 = $this->dutyOf(0, 1, '2027-01-05');
        $response = $this->reassignMain($source1, $this->flip($this->holderEmail($source1)));
        self::assertSame(1, $response['period']['number']);
        self::assertSame([['2027-01-05']], array_column($response['dependentImpacts'], 'dates'));
        self::assertSame($renfort, $this->renfortRows());
    }

    public function testAReinforcementOfPeriodTwoTakesANewHolderOnlyWhileItsOwnSourceRequiresIt(): void
    {
        $primary2 = $this->periodOf(0, 2);
        $type = static::getContainer()->get(EntityManagerInterface::class)->getRepository(DutyType::class)->findOneBy(['team' => $primary2->getTeam()]);
        static::getContainer()->get(DutyMaterializationService::class)->createStandaloneDuty($primary2, $type, new \DateTimeImmutable('2027-05-04'), new \DateTimeImmutable('2027-05-05'));
        $this->generatePeriod(1);
        $this->generatePeriod(2);
        $source1 = $this->dutyOf(0, 1, '2027-01-05');
        $source2 = $this->dutyOf(0, 2, '2027-05-04');
        $reinforcement2 = $this->dutyOf(1, 2, '2027-05-04');
        // Period 1: alice (a trigger) on the main Tuesday. Period 2: admin (never a trigger).
        $this->reassignMain($source1, 'alice@example.com');
        $this->reassignMain($source2, 'admin@example.com');
        if (null !== $this->holderEmail($reinforcement2)) {
            $view = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/duties/{$reinforcement2}/reassignment-candidates", token: $this->s['creator']);
            $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$reinforcement2}/unassign", ['expectedCurrentTeamMemberStableId' => $view['currentTeamMemberStableId']], $this->s['creator']);
            self::assertResponseIsSuccessful();
        }

        // Read in its own period: not required — whatever period 1's Tuesday requires.
        $view = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/duties/{$reinforcement2}/reassignment-candidates", token: $this->s['creator']);
        self::assertSame(2, $view['period']['number']);
        self::assertFalse($view['assignable']);
        self::assertSame('coverage_not_required', $view['notAssignableReason']);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$reinforcement2}/reassign", [
            'teamMemberStableId' => $this->memberStableIdIn($this->s['planningId'], 'carol@example.com', 1),
            'expectedCurrentTeamMemberStableId' => null,
        ], $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('coverage_not_required', json_decode((string) $this->client->getResponse()->getContent(), true)['error']);

        // Alice on the main Tuesday of period 2: now required, and assignable.
        $this->reassignMain($source2, 'alice@example.com');
        $view = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/duties/{$reinforcement2}/reassignment-candidates", token: $this->s['creator']);
        self::assertTrue($view['assignable']);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$reinforcement2}/reassign", [
            'teamMemberStableId' => $this->memberStableIdIn($this->s['planningId'], 'carol@example.com', 1),
            'expectedCurrentTeamMemberStableId' => null,
        ], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('carol@example.com', $this->holderEmail($reinforcement2));
    }
}
