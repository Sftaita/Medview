<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\InheritedReinforcementTestHelpers;
use App\Tests\LineFillTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D232 — the publication preflight of a reinforcement line
 * that inherits its blocks reads the same demand as the rest of the
 * application: no active trigger = nothing asked, an active trigger = a
 * required post, a required post without holder = really vacant, and each
 * vacancy says its dates, its source holder and why it is empty. Real
 * OR-Tools solves on the pilot planning; only "admin" triggers a reinforcement.
 */
final class PublicationRequiredReinforcementTest extends WebTestCase
{
    use LineFillTestHelpers;
    use InheritedReinforcementTestHelpers;

    private const ADMIN = 'admin@example.com';
    private const ADMIN_ONLY = [self::ADMIN => ['FRIDAY', 'SATURDAY', 'SUNDAY', 'TUESDAY']];
    private const TRIGGERING_WEEKDAYS = [2, 5, 6, 7];

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->inheritedScenario($this->client);
        $this->configureFell($this->client, $this->s, self::ADMIN_ONLY);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /** @return array<string, mixed> */
    private function preflight(): array
    {
        $view = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-preflight", token: $this->s['creator']);
        self::assertResponseIsSuccessful();

        return $view;
    }

    /**
     * @param array<string, mixed> $preflight
     *
     * @return array<string, mixed>
     */
    private function fellVerdict(array $preflight): array
    {
        foreach ($preflight['lines'] as $line) {
            if ($line['lineStableId'] === $this->s['fellLineId']) {
                return $line;
            }
        }
        self::fail('Fell/Ass is not in the preflight.');
    }

    private function dutyCount(): int
    {
        return (int) $this->db()->fetchOne("SELECT COUNT(*) FROM duties d JOIN planning_periods p ON p.id = d.planning_period_id JOIN planning_lines l ON l.id = p.planning_line_id WHERE l.name = 'Fell/Ass'");
    }

    private function stableIdOf(int $dutyId): string
    {
        return (string) $this->db()->fetchOne('SELECT stable_id FROM duties WHERE id = ?', [$dutyId]);
    }

    /** @return list<array{id: int, weekday: int, block: ?int, holder: ?string}> */
    private function triggeredSourceRows(): array
    {
        return array_values(array_filter(
            $this->sourceRows(),
            static fn (array $r): bool => self::ADMIN === $r['holder'] && \in_array($r['weekday'], self::TRIGGERING_WEEKDAYS, true),
        ));
    }

    /**
     * Fell/Ass members join the day after the first reinforcement unit: that
     * unit is required, and nobody belongs to the line yet — the Trauma Delta case.
     *
     * @return array{first: list<array{id: int, weekday: int, block: ?int, holder: ?string}>, joinedOn: string}
     */
    private function firstUnitBeforeAnyMembership(): array
    {
        $this->fillNow($this->client, $this->s, [$this->s['primaryLineId']]);
        $triggered = $this->triggeredSourceRows();
        self::assertNotEmpty($triggered);
        $block = $triggered[0]['block'];
        $first = null === $block ? [$triggered[0]] : array_values(array_filter($triggered, static fn (array $r): bool => $r['block'] === $block));
        $ids = implode(',', array_map(static fn (array $r): int => $r['id'], $first));
        $joinedOn = (new \DateTimeImmutable((string) $this->db()->fetchOne("SELECT MAX(local_date) FROM duties WHERE id IN ({$ids})")))->modify('+1 day')->format('Y-m-d');

        $this->db()->executeStatement(
            'UPDATE planning_team_members SET membership_start = ? WHERE planning_team_id = (SELECT l.planning_team_id FROM planning_lines l WHERE l.stable_id = ?)',
            [$joinedOn, $this->s['fellLineId']],
        );
        $this->fillNow($this->client, $this->s, [$this->s['fellLineId']]);

        return ['first' => $first, 'joinedOn' => $joinedOn];
    }

    public function testASurgeonWithoutTriggerAsksForNothingAndThePreflightCountsNothing(): void
    {
        $this->fillNow($this->client, $this->s, [$this->s['primaryLineId'], $this->s['fellLineId']]);

        self::assertCount(\count($this->triggeredSourceRows()), $this->fellRows(), 'One reinforcement per triggered source day, none for anybody else.');
        self::assertLessThan(\count($this->sourceRows()), \count($this->fellRows()));

        $preflight = $this->preflight();
        self::assertSame([], $preflight['uncoveredDuties']);
        self::assertSame([], $preflight['uncoveredUnits']);
        self::assertSame([], $this->fellVerdict($preflight)['blockers']);
    }

    public function testARequiredPostNobodyCanHoldIsReportedWithItsDatesSourceHolderAndReason(): void
    {
        ['first' => $first, 'joinedOn' => $joinedOn] = $this->firstUnitBeforeAnyMembership();

        $preflight = $this->preflight();
        $verdict = $this->fellVerdict($preflight);
        self::assertSame(['UNCOVERED_DUTIES'], $verdict['blockers'], 'A real vacancy keeps blocking: the protection is never bypassed.');
        self::assertFalse($verdict['firstPublishable']);
        self::assertCount(\count($first), $preflight['uncoveredDuties']);

        self::assertCount(1, $preflight['uncoveredUnits'], 'One unit: a block once, with all its days.');
        $unit = $preflight['uncoveredUnits'][0];
        self::assertSame('Fell/Ass', $unit['lineName']);
        self::assertTrue($unit['conditional']);
        self::assertTrue($unit['materialized']);
        self::assertCount(\count($first), $unit['days']);
        self::assertSame(array_column($preflight['uncoveredDuties'], 'date'), $unit['dates']);
        self::assertSame(array_column($preflight['uncoveredDuties'], 'dutyStableId'), $unit['dutyStableIds']);
        $adminFirstName = (string) $this->db()->fetchOne('SELECT first_name FROM users WHERE email = ?', [self::ADMIN]);
        foreach ($unit['days'] as $day) {
            self::assertSame('TRIGGERED', $day['reason']);
            self::assertSame($adminFirstName, $day['sourceHolder']['firstName']);
            self::assertNotNull($day['sourceDutyStableId']);
            self::assertContains($day['weekday'], ['TUESDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY']);
        }
        self::assertSame('NO_MEMBER_ON_DATE', $unit['vacancy']['code']);
        self::assertSame(0, $unit['vacancy']['assignableCount']);
        self::assertSame(3, $unit['vacancy']['memberCount']);
        self::assertSame($joinedOn, $unit['vacancy']['firstMembershipStart']);
    }

    public function testAMemberWhoCanStillTakeTheVacancyIsCounted(): void
    {
        $this->fillNow($this->client, $this->s, [$this->s['primaryLineId'], $this->s['fellLineId']]);
        $row = array_values(array_filter($this->fellRows(), static fn (array $r): bool => null === $r['block'] && null !== $r['holder']))[0] ?? self::fail('A standalone reinforcement.');
        $this->unassignDuty($this->client, $this->s, $this->stableIdOf($row['id']));
        self::assertResponseIsSuccessful();

        $preflight = $this->preflight();

        self::assertSame(['UNCOVERED_DUTIES'], $this->fellVerdict($preflight)['blockers']);
        $vacancy = $preflight['uncoveredUnits'][0]['vacancy'];
        self::assertSame('ASSIGNABLE_MEMBERS', $vacancy['code']);
        self::assertGreaterThan(0, $vacancy['assignableCount']);
        self::assertNull($vacancy['firstMembershipStart']);
    }

    public function testTheSourceReassignedToASurgeonWithoutTriggerLiftsTheVacancy(): void
    {
        ['first' => $first] = $this->firstUnitBeforeAnyMembership();
        self::assertSame(['UNCOVERED_DUTIES'], $this->fellVerdict($this->preflight())['blockers']);

        $sourceStableId = $this->stableIdOf($first[0]['id']);
        $view = $this->candidatesFor($this->client, $this->s, $sourceStableId);
        self::assertNotEmpty($view['candidates']);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$sourceStableId}/reassign", [
            'teamMemberStableId' => $view['candidates'][0]['teamMemberStableId'],
            'expectedCurrentTeamMemberStableId' => $view['currentTeamMemberStableId'],
        ], $this->s['creator']);
        self::assertResponseIsSuccessful();

        $preflight = $this->preflight();
        self::assertSame([], $preflight['uncoveredDuties'], 'No active trigger any more: the duty still exists, nothing asks for it.');
        self::assertSame([], $preflight['uncoveredUnits']);
        self::assertSame([], $this->fellVerdict($preflight)['blockers']);
        self::assertTrue($this->fellVerdict($preflight)['firstPublishable']);
    }

    public function testAReinforcementRequiredSinceAReassignmentIsNeverMissedByThePreflight(): void
    {
        $this->fillNow($this->client, $this->s, [$this->s['primaryLineId'], $this->s['fellLineId']]);
        self::assertSame([], $this->fellVerdict($this->preflight())['blockers']);

        // A Tuesday of the source held by somebody who triggers nothing: no reinforcement duty exists for it.
        $tuesday = array_values(array_filter($this->sourceRows(), static fn (array $r): bool => 2 === $r['weekday'] && self::ADMIN !== $r['holder']))[0] ?? self::fail('A Tuesday not held by admin.');
        $this->reassignTo($this->client, $this->s, $this->stableIdOf($tuesday['id']), self::ADMIN);
        self::assertResponseIsSuccessful();
        $duties = $this->dutyCount();

        $preflight = $this->preflight();

        self::assertSame($duties, $this->dutyCount(), 'The preflight only reads: the duty is created by the completion.');
        $verdict = $this->fellVerdict($preflight);
        self::assertSame(['MISSING_REINFORCEMENTS'], $verdict['blockers']);
        self::assertFalse($verdict['firstPublishable']);
        self::assertSame([], $preflight['uncoveredDuties'], 'No duty yet: nothing to locate in the calendar.');
        self::assertCount(1, $preflight['uncoveredUnits']);
        $unit = $preflight['uncoveredUnits'][0];
        self::assertFalse($unit['materialized']);
        self::assertSame('NOT_CREATED', $unit['vacancy']['code']);
        self::assertSame([], $unit['dutyStableIds']);
        self::assertNull($unit['days'][0]['dutyStableId']);
        self::assertNotNull($unit['days'][0]['sourceHolder']);
        self::assertSame('TRIGGERED', $unit['days'][0]['reason']);
        self::assertSame('TUESDAY', $unit['days'][0]['weekday']);

        // "Compléter la période" creates and fills it: nothing is left.
        $this->fillNow($this->client, $this->s, [$this->s['fellLineId']]);
        self::assertSame($duties + 1, $this->dutyCount());
        $after = $this->preflight();
        self::assertSame([], $this->fellVerdict($after)['blockers']);
        self::assertSame([], $after['uncoveredUnits']);
    }

    public function testATriggerAddedAfterTheGenerationWaitsForTheNextGeneration(): void
    {
        $this->fillNow($this->client, $this->s, [$this->s['primaryLineId'], $this->s['fellLineId']]);

        // docs/decisions.md D164: the generated calendar is read with the rules its generation froze.
        $this->configureFell($this->client, $this->s, self::ADMIN_ONLY + ['bob@example.com' => ['FRIDAY', 'SATURDAY', 'SUNDAY', 'TUESDAY']]);

        $preflight = $this->preflight();
        self::assertSame([], $this->fellVerdict($preflight)['blockers']);
        self::assertSame([], $preflight['uncoveredUnits']);
    }
}
