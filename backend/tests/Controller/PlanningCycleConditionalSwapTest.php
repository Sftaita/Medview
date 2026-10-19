<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DutyType;
use App\Entity\PlanningPeriod;
use App\Repository\UserRepository;
use App\Service\DutyMaterializationService;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\ConditionalLineTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Swaps on a planning with several periods and a conditional line, lot L4e
 * (docs/decisions.md D198): the reinforcements a swap may change are those
 * of its own period (docs/planning-cycles.md §5.6) — a swap that changes
 * them is refused (D165, never left to a manager), one on the conditional
 * line itself that changes nothing is applied.
 *
 * Fixture (ConditionalLineTestHelpers): main line (admin, alice, bob),
 * "Renfort" (carol, dave) depending on it, a reinforcement every Tuesday;
 * alice triggers every day, admin never. Period 1 ends on Monday 3 May (the
 * reinforcement weekend block may not be cut), period 2 runs to 2 August;
 * the main line has Tuesday 4 and 11 May in period 2. Both periods published.
 */
final class PlanningCycleConditionalSwapTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;
    use ConditionalLineTestHelpers;

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        $this->client = static::createClient();
        $this->s = $this->scenario($this->client);
        $period1 = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'][0]['stableId'];
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1}", ['endsAt' => '2027-05-03', 'expectedEndsAt' => '2027-05-01'], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", ['endsAt' => '2027-08-02', 'previousPeriodStableId' => $period1], $this->s['creator']);
        self::assertResponseStatusCodeSame(201);

        $primary2 = $this->periodOf(0, 2);
        $type = $this->em()->getRepository(DutyType::class)->findOneBy(['team' => $primary2->getTeam()]);
        foreach (['2027-05-04', '2027-05-11'] as $day) {
            static::getContainer()->get(DutyMaterializationService::class)->createStandaloneDuty($primary2, $type, new \DateTimeImmutable($day), (new \DateTimeImmutable($day))->modify('+1 day'));
        }
        foreach ([1, 2] as $number) {
            $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", ['period' => $this->periodId($number)], $this->s['creator']);
            self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
            self::assertSame(1, $this->runQueuedPlanningJobs());
        }
    }

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

    private function periodOf(int $lineIndex, int $number): PlanningPeriod
    {
        $this->em()->clear();
        foreach ($this->line($this->s['planningId'], $lineIndex)->getPeriods() as $period) {
            if ($number === $period->getCycle()?->getNumber()) {
                return $period;
            }
        }

        self::fail("Line {$lineIndex} has no period {$number}.");
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

    /** As the manager; null empties the duty. */
    private function setHolder(string $dutyStableId, ?string $email, int $lineIndex): void
    {
        $view = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/duties/{$dutyStableId}/reassignment-candidates", token: $this->s['creator']);
        $target = null !== $email ? $this->memberStableIdIn($this->s['planningId'], $email, $lineIndex) : null;
        if ($view['currentTeamMemberStableId'] === $target) {
            return;
        }
        if (null === $target) {
            $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$dutyStableId}/unassign", ['expectedCurrentTeamMemberStableId' => $view['currentTeamMemberStableId']], $this->s['creator']);
        } else {
            $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$dutyStableId}/reassign", [
                'teamMemberStableId' => $target,
                'expectedCurrentTeamMemberStableId' => $view['currentTeamMemberStableId'],
            ], $this->s['creator']);
        }
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    private function publishBothPeriods(): void
    {
        foreach ([1, 2] as $number) {
            $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", ['period' => $this->periodId($number)], $this->s['creator']);
            self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        }
    }

    /** @return array<string, mixed> */
    private function agreed(string $email, string $offered, string $counterpartEmail, string $counterpart): array
    {
        return $this->api($this->client, 'POST', '/api/duty-swap-requests', [
            'dutyStableId' => $offered,
            'kind' => 'AGREED',
            'recipientUserStableIds' => [(string) static::getContainer()->get(UserRepository::class)->findOneByEmail($counterpartEmail)->getStableId()],
            'counterpartDutyStableId' => $counterpart,
            'acknowledgedResponsibility' => true,
        ], $this->s[strstr($email, '@', true)]);
    }

    /** Every row of a line (current or not), hashed. */
    private function lineRows(string $lineStableId): string
    {
        return (string) $this->db()->fetchOne(<<<'SQL'
            SELECT COUNT(*) || '/' || COALESCE(md5(string_agg(ROW(a.*)::text, '|' ORDER BY a.id)), '') FROM duty_assignments a
            JOIN duties d ON d.id = a.duty_id JOIN planning_periods pp ON pp.id = d.planning_period_id JOIN planning_lines l ON l.id = pp.planning_line_id
            WHERE l.stable_id = ?
        SQL, [$lineStableId]);
    }

    public function testASwapOnTheSourceLineThatChangesTheReinforcementsOfItsPeriodIsRefused(): void
    {
        $main4 = $this->dutyOf(0, 2, '2027-05-04');
        $main11 = $this->dutyOf(0, 2, '2027-05-11');
        // 4 May: alice (triggers), its reinforcement held; 11 May: admin (never), its reinforcement empty.
        $this->setHolder($main4, 'alice@example.com', 0);
        $this->setHolder($main11, 'admin@example.com', 0);
        $this->setHolder($this->dutyOf(1, 2, '2027-05-11'), null, 1);
        $this->setHolder($this->dutyOf(1, 2, '2027-05-04'), 'carol@example.com', 1);
        $this->publishBothPeriods();
        $renfort = $this->lineRows($this->s['renfortLineId']);
        $main = $this->lineRows($this->s['primaryLineId']);

        // Valid for both people — but after it, 4 May no longer needs its reinforcement and 11 May does.
        $request = $this->agreed('alice@example.com', $main4, 'admin@example.com', $main11);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $this->api($this->client, 'POST', "/api/duty-swap-proposals/{$request['proposals'][0]['stableId']}/accept", [], $this->s['admin']);
        self::assertResponseStatusCodeSame(409, (string) $this->client->getResponse()->getContent());
        self::assertSame('changes_reinforcements', json_decode((string) $this->client->getResponse()->getContent(), true)['reason']);

        self::assertSame('alice@example.com', $this->holderEmail($main4));
        self::assertSame('admin@example.com', $this->holderEmail($main11));
        self::assertSame($main, $this->lineRows($this->s['primaryLineId']), 'rolled back');
        self::assertSame($renfort, $this->lineRows($this->s['renfortLineId']), 'never acted on');
    }

    public function testASwapOfTwoRequiredReinforcementsOfOnePeriodIsApplied(): void
    {
        $renfort4 = $this->dutyOf(1, 2, '2027-05-04');
        $renfort11 = $this->dutyOf(1, 2, '2027-05-11');
        // alice on both main Tuesdays of period 2: both reinforcements are required.
        $this->setHolder($this->dutyOf(0, 2, '2027-05-04'), 'alice@example.com', 0);
        $this->setHolder($this->dutyOf(0, 2, '2027-05-11'), 'alice@example.com', 0);
        $this->setHolder($renfort4, null, 1);
        $this->setHolder($renfort11, null, 1);
        $this->setHolder($renfort4, 'carol@example.com', 1);
        $this->setHolder($renfort11, 'dave@example.com', 1);
        $this->publishBothPeriods();
        $main = $this->lineRows($this->s['primaryLineId']);
        $period1Reinforcement = $this->holderEmail($this->dutyOf(1, 1, '2027-01-05'));

        $request = $this->agreed('carol@example.com', $renfort4, 'dave@example.com', $renfort11);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        self::assertSame(2, $request['offered']['period']['number']);
        $this->api($this->client, 'POST', "/api/duty-swap-proposals/{$request['proposals'][0]['stableId']}/accept", [], $this->s['dave']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        self::assertSame('dave@example.com', $this->holderEmail($renfort4));
        self::assertSame('carol@example.com', $this->holderEmail($renfort11));
        self::assertSame($main, $this->lineRows($this->s['primaryLineId']), 'the source line is never written');
        self::assertSame($period1Reinforcement, $this->holderEmail($this->dutyOf(1, 1, '2027-01-05')), 'period 1 untouched');
    }
}
