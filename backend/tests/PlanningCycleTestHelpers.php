<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\DutyType;
use App\Entity\PlanningPeriod;
use App\Repository\DutyRepository;
use App\Service\DutyMaterializationService;
use App\Service\PlanningRuleSetService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A planning with several periods driven through the real API, for the
 * transverse reads and swaps of lot L4e (docs/decisions.md D198) — the same
 * helpers as PlanningCycleReassignmentTest (D197), shared.
 *
 * Expects a WebTestCase using CalendarWorkflowTestHelpers, with
 * `$this->client` and `$this->s` (pilotScenario: planning 2027-01-01 →
 * 2027-05-01, primary line "Seniors" with admin/alice/bob, Europe/Brussels).
 */
trait PlanningCycleTestHelpers
{
    private bool $ruleSetActivated = false;

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

    /** Appends a period ending on $endsAt (exclusive). */
    private function createPeriod(string $endsAt): void
    {
        $periods = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'];
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", [
            'endsAt' => $endsAt,
            'previousPeriodStableId' => $periods[\count($periods) - 1]['stableId'],
        ], $this->s['creator']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
    }

    /** The primary line's period in period $number, read fresh. */
    private function periodOf(int $number): PlanningPeriod
    {
        $id = $this->db()->fetchOne(<<<'SQL'
            SELECT pp.id FROM planning_periods pp
            JOIN planning_cycles c ON c.id = pp.planning_cycle_id JOIN plannings p ON p.id = c.planning_id
            JOIN planning_lines l ON l.id = pp.planning_line_id
            WHERE p.stable_id = ? AND c.number = ? AND l.type = 'PRIMARY'
        SQL, [$this->s['planningId'], $number]);
        self::assertNotFalse($id, "No period {$number}.");
        $this->em()->clear();

        return $this->em()->find(PlanningPeriod::class, $id) ?? self::fail("No period {$number}.");
    }

    /**
     * Standalone duties ([local start, local end], Europe/Brussels wall clock) in period $number of the primary line.
     *
     * @param list<array{0: string, 1: string}> $duties
     */
    private function addDuties(int $number, array $duties): void
    {
        $period = $this->periodOf($number);
        $team = $period->getTeam();
        if (!$this->ruleSetActivated) {
            $this->activateRuleSet(static::getContainer()->get(PlanningRuleSetService::class), $team);
            $this->ruleSetActivated = true;
        }
        $dutyType = $this->em()->getRepository(DutyType::class)->findOneBy(['team' => $team, 'code' => 'ONCALL']) ?? $this->createDutyType($this->em(), $team);
        $period = $this->em()->find(PlanningPeriod::class, $period->getId());
        foreach ($duties as [$from, $to]) {
            static::getContainer()->get(DutyMaterializationService::class)->createStandaloneDuty($period, $dutyType, new \DateTimeImmutable($from), new \DateTimeImmutable($to));
        }
    }

    /** @param array<string, mixed> $body */
    private function generatePeriod(int $number, array $body = []): void
    {
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/generations", ['period' => $this->periodId($number)] + $body, $this->s['creator']);
        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->runQueuedPlanningJobs());
        $job = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/jobs/latest?period={$this->periodId($number)}", token: $this->s['creator'])['job'];
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
    }

    private function publishPeriod(int $number): void
    {
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", ['period' => $this->periodId($number)], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    /** The stableId of the primary line's duty of period $number on $localDate. */
    private function dutyIn(int $number, string $localDate): string
    {
        foreach (static::getContainer()->get(DutyRepository::class)->findByPlanningPeriod($this->periodOf($number)) as $duty) {
            if ($duty->getLocalDate()->format('Y-m-d') === $localDate) {
                return (string) $duty->getStableId();
            }
        }

        self::fail("No duty on {$localDate} in period {$number}.");
    }

    /** The current holder (email) of a duty, or null when uncovered. */
    private function holderOf(string $dutyStableId): ?string
    {
        $email = $this->db()->fetchOne(<<<'SQL'
            SELECT u.email FROM duty_assignments a
            JOIN duties d ON d.id = a.duty_id JOIN planning_team_members tm ON tm.id = a.team_member_id JOIN users u ON u.id = tm.user_id
            WHERE d.stable_id = ? AND a.current
        SQL, [$dutyStableId]);

        return false === $email ? null : (string) $email;
    }

    private function currentHolderMemberId(string $dutyStableId): ?string
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/duties/{$dutyStableId}/reassignment-candidates", token: $this->s['creator'])['currentTeamMemberStableId'] ?? null;
    }

    /**
     * Forces the holders, as the manager: every listed duty is first emptied, then assigned — never an
     * intermediate state a rest rule would refuse.
     *
     * @param array<string, string> $holders duty stableId → email
     */
    private function forceHolders(array $holders, int $lineIndex = 0): void
    {
        foreach (array_keys($holders) as $duty) {
            $current = $this->currentHolderMemberId($duty);
            if (null !== $current) {
                $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$duty}/unassign", ['expectedCurrentTeamMemberStableId' => $current], $this->s['creator']);
                self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
            }
        }
        foreach ($holders as $duty => $email) {
            $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$duty}/reassign", [
                'teamMemberStableId' => $this->memberStableIdIn($this->s['planningId'], $email, $lineIndex),
                'expectedCurrentTeamMemberStableId' => null,
            ], $this->s['creator']);
            self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        }
        foreach ($holders as $duty => $email) {
            self::assertSame($email, $this->holderOf($duty), "Fixture: {$duty} held by {$email}.");
        }
    }

    /** Everything a period holds, hashed (as PlanningCycleReassignmentTest::fingerprint()), events included. */
    private function fingerprint(int $number): string
    {
        $cycleId = $this->db()->fetchOne('SELECT c.id FROM planning_cycles c JOIN plannings p ON p.id = c.planning_id WHERE p.stable_id = ? AND c.number = ?', [$this->s['planningId'], $number]);

        return (string) $this->db()->fetchOne(<<<'SQL'
            SELECT md5(string_agg(x, '|' ORDER BY x)) FROM (
              SELECT 'y' || ROW(cy.*)::text AS x FROM planning_cycles cy WHERE cy.id = :c
              UNION ALL SELECT 'p' || ROW(pp.*)::text FROM planning_periods pp WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'd' || ROW(d.*)::text FROM duties d JOIN planning_periods pp ON pp.id = d.planning_period_id WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'g' || ROW(g.*)::text FROM planning_generations g JOIN planning_periods pp ON pp.id = g.planning_period_id WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'a' || ROW(a.*)::text FROM duty_assignments a JOIN duties d ON d.id = a.duty_id JOIN planning_periods pp ON pp.id = d.planning_period_id WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'e' || ROW(e.*)::text FROM duty_assignment_events e JOIN duties d ON d.id = e.duty_id JOIN planning_periods pp ON pp.id = d.planning_period_id WHERE pp.planning_cycle_id = :c
              UNION ALL SELECT 'u' || ROW(u.*)::text FROM planning_publications u WHERE u.planning_cycle_id = :c
            ) s
        SQL, ['c' => $cycleId]);
    }

    /** Every publication row, entry, line, document, notification and delivery, hashed (lot L4c, D196). */
    private function publicationFingerprint(): string
    {
        $parts = [];
        foreach (['planning_publications', 'planning_publication_entries', 'planning_publication_lines', 'planning_publication_documents', 'planning_publication_notifications', 'planning_publication_deliveries'] as $table) {
            $parts[] = $table.':'.$this->db()->fetchOne("SELECT COUNT(*) || '/' || COALESCE(md5(string_agg(ROW(t.*)::text, '|' ORDER BY ROW(t.*)::text)), '') FROM {$table} t");
        }

        return implode(' ', $parts);
    }

    private function assertError(int $status, string $code, ?string $reason = null): void
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());
        self::assertSame($code, $body['error'] ?? null);
        if (null !== $reason) {
            self::assertSame($reason, $body['reason'] ?? null, (string) $this->client->getResponse()->getContent());
        }
    }
}
