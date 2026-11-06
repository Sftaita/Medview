<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\InheritedReinforcementTestHelpers;
use App\Tests\LineFillTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D234 — « Compléter la période » is never offered for
 * posts nobody can fill. The Trauma Delta case (D232): every Fell/Ass member
 * joins after the first reinforcement unit, the line is generated with that
 * unit vacant. Real generations (OR-Tools), real endpoints.
 */
final class FillUnfillableHolesTest extends WebTestCase
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

    /** @return array<string, mixed> the plan of the Fell/Ass line */
    private function fellPlan(): array
    {
        foreach ($this->fillPreview($this->client, $this->s)['lines'] as $line) {
            if ($line['lineStableId'] === $this->s['fellLineId']) {
                return $line;
            }
        }
        self::fail('Fell/Ass is not in the preview.');
    }

    /**
     * Same as LineCoverageStartTest: the source generated, every Fell/Ass member joining the day after the first
     * triggered unit, Fell/Ass generated with that unit vacant.
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

    /** @return list<array<string, mixed>> the preflight's uncovered units of Fell/Ass */
    private function fellUncoveredUnits(): array
    {
        $preflight = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-preflight", token: $this->s['creator']);
        self::assertResponseIsSuccessful();

        return array_values(array_filter($preflight['uncoveredUnits'], fn (array $unit): bool => $unit['lineStableId'] === $this->s['fellLineId']));
    }

    public function testPostsWithoutAnyMemberOnTheirDatesAreNeverOfferedToTheCompletion(): void
    {
        ['joinedOn' => $joinedOn, 'vacantDutyIds' => $vacant] = $this->vacancyBeforeAnyMembership();

        $plan = $this->fellPlan();
        self::assertSame(['NOTHING', 'no_eligible_member', false, false], [$plan['action'], $plan['reason'], $plan['selectable'], $plan['selectedByDefault']]);
        self::assertSame(\count($vacant), $plan['uncoveredRequiredDutyCount'], 'the posts are still required: nothing is removed');
        self::assertSame(\count($vacant), $plan['unfillable']['dutyCount']);
        self::assertSame(\count($vacant), $plan['unfillable']['noMemberOnDateCount']);
        self::assertSame(0, $plan['unfillable']['noEligibleMemberCount']);
        self::assertSame($joinedOn, $plan['unfillable']['firstMembershipStart']);
        $dates = array_column(array_values(array_filter($this->fellRows(), static fn (array $r): bool => null === $r['holder'])), 'date');
        self::assertSame([min($dates), max($dates)], [$plan['unfillable']['from'], $plan['unfillable']['to']]);

        // The preflight says the same, post by post.
        foreach ($this->fellUncoveredUnits() as $unit) {
            self::assertSame('NO_MEMBER_ON_DATE', $unit['vacancy']['code']);
            self::assertTrue($unit['vacancy']['unfillable']);
        }

        // Asked anyway: refused before any job, nothing is written, the posts stay required and vacant, no date moves.
        $before = $this->protectedRows();
        $refusal = $this->fillNow($this->client, $this->s, [$this->s['fellLineId']]);
        self::assertSame([409, 'nothing_to_fill'], [$refusal['httpStatus'] ?? null, $refusal['error'] ?? null]);
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM planning_jobs WHERE status IN ('QUEUED', 'RUNNING')"));
        $this->assertNoExistingRowChanged($before);
        self::assertSame(0, $this->newRowCount($before, 'duty_assignments'));
        self::assertNull($this->db()->fetchOne('SELECT coverage_starts_on FROM planning_lines WHERE stable_id = ?', [$this->s['fellLineId']]));
        self::assertSame(\count($vacant), (int) $this->db()->fetchOne('SELECT COUNT(*) FROM duties WHERE id IN ('.implode(',', $vacant).') AND retired_at IS NULL'));
    }

    public function testCompletablePostsAreCompletedNextToImpossibleOnesWhichAreToldApart(): void
    {
        ['vacantDutyIds' => $vacant] = $this->vacancyBeforeAnyMembership();

        // A post after the members joined, freed by hand: someone can take it.
        $held = array_values(array_filter($this->fellRows(), static fn (array $r): bool => null !== $r['holder']));
        self::assertNotEmpty($held);
        $freed = $held[0];
        $dutyStableId = (string) $this->db()->fetchOne('SELECT stable_id FROM duties WHERE id = ?', [$freed['id']]);
        $member = (string) $this->db()->fetchOne('SELECT m.stable_id FROM duty_assignments a JOIN planning_team_members m ON m.id = a.team_member_id WHERE a.id = ?', [$freed['assignmentId']]);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$dutyStableId}/unassign", ['expectedCurrentTeamMemberStableId' => $member], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $freedIds = array_column(array_values(array_filter($this->fellRows(), static fn (array $r): bool => null === $r['holder'] && !\in_array($r['id'], $vacant, true))), 'id');
        self::assertNotEmpty($freedIds);

        $plan = $this->fellPlan();
        self::assertSame(['COMPLETE', true], [$plan['action'], $plan['selectable']], 'never blocked for a few impossible posts');
        self::assertSame(\count($vacant) + \count($freedIds), $plan['uncoveredRequiredDutyCount']);
        self::assertSame(\count($vacant), $plan['unfillable']['dutyCount'], 'the impossible posts are counted apart');

        $units = $this->fellUncoveredUnits();
        self::assertContains(false, array_map(static fn (array $unit): bool => $unit['vacancy']['unfillable'], $units));
        self::assertContains(true, array_map(static fn (array $unit): bool => $unit['vacancy']['unfillable'], $units));

        $job = $this->fillNow($this->client, $this->s, [$this->s['fellLineId']]);
        self::assertSame('SUCCEEDED', $job['status'] ?? null, (string) json_encode($job));
        $rows = array_column($this->fellRows(), null, 'id');
        foreach ($freedIds as $id) {
            self::assertNotNull($rows[$id]['holder'], 'the completable post is completed');
        }
        foreach ($vacant as $id) {
            self::assertNull($rows[$id]['holder'], 'the impossible posts stay vacant, still required');
        }
        self::assertSame(['NOTHING', 'no_eligible_member'], [$this->fellPlan()['action'], $this->fellPlan()['reason']]);
    }
}
