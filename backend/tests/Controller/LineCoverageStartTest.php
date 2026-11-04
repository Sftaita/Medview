<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\InheritedReinforcementTestHelpers;
use App\Tests\LineFillTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D233 — "Début de couverture de cette ligne": from when a
 * duty of the line is needed, independent of memberships. Before it nothing is
 * demanded; nothing anybody holds is ever touched; what is retired stays in the
 * history. Real OR-Tools solves on the pilot planning (from 2027-01-01).
 */
final class LineCoverageStartTest extends WebTestCase
{
    use LineFillTestHelpers;
    use InheritedReinforcementTestHelpers;

    private const ADMIN = 'admin@example.com';
    private const ADMIN_ONLY = [self::ADMIN => ['FRIDAY', 'SATURDAY', 'SUNDAY', 'TUESDAY']];

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
    private function verdict(array $preflight, string $lineId): array
    {
        foreach ($preflight['lines'] as $line) {
            if ($line['lineStableId'] === $lineId) {
                return $line;
            }
        }
        self::fail('The line is not in the preflight.');
    }

    /** @return array<string, mixed> */
    private function previewStart(?string $date, ?string $lineId = null): array
    {
        $plan = $this->api($this->client, 'POST', '/api/planning-lines/'.($lineId ?? $this->s['fellLineId']).'/coverage-start/preview', ['coverageStartsOn' => $date], $this->s['creator']);
        self::assertResponseIsSuccessful();

        return $plan;
    }

    /** @return array<string, mixed> */
    private function confirmStart(?string $date, ?string $lineId = null): array
    {
        $plan = $this->previewStart($date, $lineId);
        $outcome = $this->api($this->client, 'PUT', '/api/planning-lines/'.($lineId ?? $this->s['fellLineId']).'/coverage-start', ['coverageStartsOn' => $date, 'confirmation' => $plan['confirmation']], $this->s['creator']);
        self::assertResponseIsSuccessful();

        return $outcome;
    }

    private function stableIdOf(int $dutyId): string
    {
        return (string) $this->db()->fetchOne('SELECT stable_id FROM duties WHERE id = ?', [$dutyId]);
    }

    /**
     * The Trauma Delta situation: the source line generated, every Fell/Ass
     * member joining after the first reinforcement unit, the line generated
     * with that unit vacant.
     *
     * @return array{joinedOn: string, vacantDutyIds: list<int>}
     */
    private function vacancyBeforeAnyMembership(): array
    {
        $this->fillNow($this->client, $this->s, [$this->s['primaryLineId']]);
        $triggered = array_values(array_filter($this->sourceRows(), static fn (array $r): bool => self::ADMIN === $r['holder'] && \in_array($r['weekday'], [2, 5, 6, 7], true)));
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

        $vacant = array_values(array_filter($this->fellRows(), static fn (array $r): bool => null === $r['holder']));
        self::assertNotEmpty($vacant);

        return ['joinedOn' => $joinedOn, 'vacantDutyIds' => array_column($vacant, 'id')];
    }

    /** @return array<string, string> md5 of the rows that must never change */
    private function untouchable(): array
    {
        $hashes = [];
        foreach ([
            'assignments' => 'SELECT id, duty_id, team_member_id, generation_id, source, current FROM duty_assignments ORDER BY id',
            'events' => 'SELECT id FROM duty_assignment_events ORDER BY id',
            'publications' => 'SELECT id FROM planning_publications ORDER BY id',
            'entries' => 'SELECT id, duty_id, team_member_id FROM planning_publication_entries ORDER BY id',
            'memberships' => 'SELECT id, membership_start, membership_end FROM planning_team_members ORDER BY id',
            'decisions' => 'SELECT id, duty_id, required, reason, day_reason FROM planning_snapshot_demand_decisions ORDER BY id',
        ] as $name => $sql) {
            $hashes[$name] = md5((string) json_encode($this->db()->fetchAllAssociative($sql)));
        }

        return $hashes;
    }

    public function testARealVacancyBeforeTheStartIsRetiredWithItsTraceAndNothingElseMoves(): void
    {
        ['joinedOn' => $joinedOn, 'vacantDutyIds' => $vacant] = $this->vacancyBeforeAnyMembership();
        self::assertSame(['UNCOVERED_DUTIES'], $this->verdict($this->preflight(), $this->s['fellLineId'])['blockers']);
        $before = $this->untouchable();

        // Previewing writes nothing.
        $plan = $this->previewStart($joinedOn);
        self::assertTrue($plan['confirmationRequired']);
        self::assertCount(\count($vacant), $plan['retired']);
        self::assertSame([], $plan['kept']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_line_coverage_changes'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM duties WHERE retired_at IS NOT NULL'));

        // Without the hash of the plan: refused, nothing written.
        $refused = $this->api($this->client, 'PUT', "/api/planning-lines/{$this->s['fellLineId']}/coverage-start", ['coverageStartsOn' => $joinedOn], $this->s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('coverage_start_confirmation_required', $refused['error']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM duties WHERE retired_at IS NOT NULL'));

        $outcome = $this->confirmStart($joinedOn);

        self::assertSame($joinedOn, $outcome['coverageStartsOn']);
        self::assertSame(\count($vacant), $outcome['retiredDutyCount']);
        self::assertSame($before, $this->untouchable(), 'Assignments, events, publications, memberships and frozen decisions are identical.');

        $ids = implode(',', $vacant);
        self::assertSame(\count($vacant), (int) $this->db()->fetchOne("SELECT COUNT(*) FROM duties WHERE id IN ({$ids}) AND retired_at IS NOT NULL AND retired_by_coverage_change_id IS NOT NULL"), 'The rows stay, marked.');
        $journal = $this->db()->fetchAssociative('SELECT kind, previous_starts_on, new_starts_on, impact FROM planning_line_coverage_changes');
        self::assertSame('COVERAGE_START', $journal['kind']);
        self::assertNull($journal['previous_starts_on']);
        self::assertSame($joinedOn, $journal['new_starts_on']);
        self::assertCount(\count($vacant), json_decode((string) $journal['impact'], true)['retired']);

        $preflight = $this->preflight();
        self::assertSame([], $preflight['uncoveredDuties']);
        self::assertSame([], $preflight['uncoveredUnits']);
        self::assertSame([], $this->verdict($preflight, $this->s['fellLineId'])['blockers']);
        self::assertTrue($this->verdict($preflight, $this->s['fellLineId'])['firstPublishable']);

        // A completion recreates nothing before the date.
        $this->fillNow($this->client, $this->s, [$this->s['fellLineId']]);
        self::assertSame([], array_values(array_filter($this->fellRows(), static fn (array $r): bool => $r['date'] < $joinedOn)));
        self::assertSame($before['entries'], $this->untouchable()['entries']);
    }

    public function testADateInTheMiddleOfAPeriodAppliesToAFirstGenerationAndTheRulesStillApplyAfterIt(): void
    {
        // A Saturday of the period: a Friday-Sunday block of the source is cut, its later days still form one unit.
        $saturday = '2027-02-06';
        $this->confirmStart($saturday);
        $this->fillNow($this->client, $this->s, [$this->s['primaryLineId'], $this->s['fellLineId']]);

        $rows = $this->fellRows();
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertGreaterThanOrEqual($saturday, $row['date'], 'Nothing before the date.');
        }
        $expected = array_filter($this->sourceRows(), fn (array $r): bool => self::ADMIN === $r['holder'] && \in_array($r['weekday'], [2, 5, 6, 7], true)
            && (string) $this->db()->fetchOne('SELECT local_date FROM duties WHERE id = ?', [$r['id']]) >= $saturday);
        self::assertCount(\count($expected), $rows, 'From the date on, every triggered day has its reinforcement.');
        self::assertSame([], array_filter($rows, static fn (array $r): bool => null === $r['holder']), 'And they are covered.');
        self::assertSame([], $this->verdict($this->preflight(), $this->s['fellLineId'])['blockers']);
    }

    public function testADutySomeoneHoldsBeforeTheDateIsNeverTouched(): void
    {
        $this->fillNow($this->client, $this->s, [$this->s['primaryLineId'], $this->s['fellLineId']]);
        $rows = $this->fellRows();
        self::assertNotEmpty($rows);
        $start = (new \DateTimeImmutable($rows[\count($rows) - 1]['date']))->modify('+1 day')->format('Y-m-d');
        $before = $this->untouchable();

        $plan = $this->previewStart($start);
        self::assertSame([], $plan['retired']);
        self::assertCount(\count($rows), $plan['kept']);
        self::assertTrue($plan['confirmationRequired'], 'Told before it applies.');
        $outcome = $this->confirmStart($start);

        self::assertSame(0, $outcome['retiredDutyCount']);
        self::assertSame($before, $this->untouchable());
        self::assertCount(\count($rows), $this->fellRows());
        // Still held, no longer required: a warning, never a blocker.
        $preflight = $this->preflight();
        self::assertSame([], $this->verdict($preflight, $this->s['fellLineId'])['blockers']);
        self::assertNotEmpty($preflight['superfluousCoverages']);
        self::assertStringContainsString('début de couverture', $preflight['superfluousCoverages'][0]['explanation']);
    }

    public function testMovingTheDateBackNeverResurrectsARetiredDutyAndClearingItAsksAgain(): void
    {
        ['joinedOn' => $joinedOn, 'vacantDutyIds' => $vacant] = $this->vacancyBeforeAnyMembership();
        $this->confirmStart($joinedOn);

        $this->confirmStart(null);

        $ids = implode(',', $vacant);
        self::assertSame(\count($vacant), (int) $this->db()->fetchOne("SELECT COUNT(*) FROM duties WHERE id IN ({$ids}) AND retired_at IS NOT NULL"));
        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_line_coverage_changes'));
        // Required again by the triggers: told, not created behind anybody's back.
        $preflight = $this->preflight();
        self::assertSame(['MISSING_REINFORCEMENTS'], $this->verdict($preflight, $this->s['fellLineId'])['blockers']);
    }

    public function testAnIndependentLineCoversNothingBeforeItsStart(): void
    {
        $start = '2027-02-01';
        $this->confirmStart($start, $this->s['primaryLineId']);
        $this->fillNow($this->client, $this->s, [$this->s['primaryLineId']]);

        $dates = $this->db()->fetchFirstColumn("SELECT d.local_date FROM duties d JOIN planning_periods p ON p.id = d.planning_period_id JOIN planning_lines l ON l.id = p.planning_line_id WHERE l.stable_id = ? AND d.retired_at IS NULL ORDER BY 1", [$this->s['primaryLineId']]);
        self::assertNotEmpty($dates);
        self::assertGreaterThanOrEqual($start, $dates[0], 'The weekly structure creates nothing before the date.');
        self::assertSame([], $this->preflight()['uncoveredDuties']);
    }

    public function testTheJournalIsAppendOnly(): void
    {
        $this->confirmStart('2027-02-01');

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->db()->executeStatement("UPDATE planning_line_coverage_changes SET kind = 'REINFORCEMENTS_CREATED'");
    }

    public function testAMemberCannotSetIt(): void
    {
        $this->api($this->client, 'PUT', "/api/planning-lines/{$this->s['fellLineId']}/coverage-start", ['coverageStartsOn' => '2027-02-01'], $this->s['carol']);
        self::assertResponseStatusCodeSame(403);
        $this->api($this->client, 'POST', "/api/planning-lines/{$this->s['fellLineId']}/coverage-start/preview", ['coverageStartsOn' => '2027-02-01'], $this->s['carol']);
        self::assertResponseStatusCodeSame(403);
        $this->api($this->client, 'PUT', "/api/planning-lines/{$this->s['fellLineId']}/coverage-start", ['coverageStartsOn' => 'demain'], $this->s['creator']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testANeedCreatedAfterPublicationIsToldBeforeRepublishingAndCreatedOnlyOnRequest(): void
    {
        $this->fillNow($this->client, $this->s, [$this->s['primaryLineId'], $this->s['fellLineId']]);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", ['lines' => [$this->s['primaryLineId'], $this->s['fellLineId']]], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $assignments = $this->db()->fetchAllAssociative('SELECT id, duty_id, team_member_id FROM duty_assignments WHERE current ORDER BY id');
        $publications = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_publications');
        $dutyCount = \count($this->fellRows());

        $tuesday = array_values(array_filter($this->sourceRows(), static fn (array $r): bool => 2 === $r['weekday'] && self::ADMIN !== $r['holder']))[0] ?? self::fail('A Tuesday not held by admin.');
        $this->reassignTo($this->client, $this->s, $this->stableIdOf($tuesday['id']), self::ADMIN);
        self::assertResponseIsSuccessful();

        // Detected, shown as vacant, and not a blocker: the republication stays possible, as for any published vacancy.
        $preflight = $this->preflight();
        self::assertSame($dutyCount, \count($this->fellRows()), 'Reading creates nothing.');
        self::assertSame([], $this->verdict($preflight, $this->s['fellLineId'])['blockers']);
        self::assertTrue($preflight['republishable']);
        self::assertCount(1, $preflight['uncoveredUnits']);
        $unit = $preflight['uncoveredUnits'][0];
        self::assertTrue($unit['linePublished']);
        self::assertFalse($unit['materialized']);
        self::assertSame('NOT_CREATED', $unit['vacancy']['code']);

        // Created empty, on an explicit request, with a trace; nothing else moves.
        $created = $this->api($this->client, 'POST', "/api/planning-lines/{$this->s['fellLineId']}/missing-reinforcements", ['periodStableId' => $preflight['period']['stableId']], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $created['createdDutyCount']);
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM planning_line_coverage_changes WHERE kind = 'REINFORCEMENTS_CREATED'"));
        self::assertSame($publications, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_publications'));

        $current = $this->db()->fetchAllAssociative('SELECT id, duty_id, team_member_id FROM duty_assignments WHERE current ORDER BY id');
        $sourceBefore = array_filter($assignments, static fn (array $a): bool => (int) $a['duty_id'] !== $tuesday['id']);
        foreach ($sourceBefore as $row) {
            self::assertContains($row, $current, 'Every other assignment is the same row.');
        }

        $after = $this->preflight();
        self::assertTrue($after['uncoveredUnits'][0]['materialized'], 'Now a vacant duty of the calendar.');
        self::assertTrue($after['uncoveredUnits'][0]['linePublished']);
        self::assertTrue($after['republishable']);

        // Assignable like any vacant post of a published line, then announced by a republication.
        $newDuty = $after['uncoveredUnits'][0]['dutyStableIds'][0];
        $view = $this->candidatesFor($this->client, $this->s, $newDuty);
        self::assertNotEmpty($view['candidates']);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$newDuty}/reassign", ['teamMemberStableId' => $view['candidates'][0]['teamMemberStableId'], 'expectedCurrentTeamMemberStableId' => null], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->preflight()['uncoveredUnits']);

        // Asking twice creates nothing more.
        $again = $this->api($this->client, 'POST', "/api/planning-lines/{$this->s['fellLineId']}/missing-reinforcements", ['periodStableId' => $preflight['period']['stableId']], $this->s['creator']);
        self::assertSame(0, $again['createdDutyCount']);
    }

    public function testACompletionNeverCreatesWhatTheFrozenRulesDoNotRequire(): void
    {
        $this->fillNow($this->client, $this->s, [$this->s['primaryLineId'], $this->s['fellLineId']]);
        $before = \count($this->fellRows());

        // A new version: bob triggers too. The current generation keeps its frozen rules (D164).
        $triggers = self::ADMIN_ONLY + ['bob@example.com' => ['FRIDAY', 'SATURDAY', 'SUNDAY', 'TUESDAY']];
        $this->configureFell($this->client, $this->s, $triggers);
        self::assertGreaterThan(0, \count(array_filter($this->sourceRows(), static fn (array $r): bool => 'bob@example.com' === $r['holder'] && \in_array($r['weekday'], [2, 5, 6, 7], true))), 'bob holds source days.');

        self::assertSame([], $this->preflight()['uncoveredUnits'], 'The preflight sees nothing to create.');
        $this->fillNow($this->client, $this->s, [$this->s['fellLineId']]);

        self::assertCount($before, $this->fellRows(), 'Neither does the completion: the two read the same rules.');
        self::assertSame([], $this->verdict($this->preflight(), $this->s['fellLineId'])['blockers']);
    }
}
