<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DutyType;
use App\Entity\PlanningPeriod;
use App\Entity\UserAvailabilityType;
use App\Fairness\OptimizationProblem;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\UserRepository;
use App\Service\DutyMaterializationService;
use App\Service\TeamMemberNonParticipationService;
use App\Service\UserAvailabilityService;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\ConditionalLineTestHelpers;
use App\Tests\FaultInjectingPlanningSolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A launch with a conditional line (D162-D165) never writes a reinforcement
 * calendar that contradicts its source, nor a source result incompatible
 * with the reinforcements that stay (docs/decisions.md D191). Real OR-Tools.
 *
 * Fixture (ConditionalLineTestHelpers::scenario): primary line with one
 * duty, Tuesday 5 January (whole day); the Renfort line reinforces it when
 * Alice holds it; Carol is on both lines here.
 */
final class PlanningLaunchConditionalConsistencyTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ConditionalLineTestHelpers;

    protected function tearDown(): void
    {
        FaultInjectingPlanningSolver::reset();
        parent::tearDown();
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function unavailableOn5(string $email): void
    {
        static::getContainer()->get(UserAvailabilityService::class)->create(
            static::getContainer()->get(UserRepository::class)->findOneByEmail($email),
            UserAvailabilityType::UNAVAILABLE,
            new \DateTimeImmutable('2027-01-04 12:00 UTC'),
            new \DateTimeImmutable('2027-01-06 12:00 UTC'),
        );
    }

    /** Current holder of the 5 January duty of line $lineIndex. */
    private function holderOn5(array $s, int $lineIndex): ?string
    {
        return $this->currentCalendar($s['planningId'])[$lineIndex.'|2027-01-05|ONCALL']
            ?? $this->currentCalendar($s['planningId'])[$lineIndex.'|2027-01-05|GARDE']
            ?? null;
    }

    private function fingerprint(): string
    {
        return (string) $this->db()->fetchOne("SELECT md5(string_agg(a.generation_id || ':' || a.duty_id || ':' || a.team_member_id, '|' ORDER BY a.id)) FROM duty_assignments a WHERE a.current");
    }

    /**
     * Launch 1: Alice holds the source day (Carol does not take part in the
     * primary line that day), so the Renfort day is required — Carol holds it.
     *
     * @return array<string, mixed>
     */
    private function carolReinforcesAlice(): array
    {
        $client = static::getClient() ?? static::createClient();
        $s = $this->scenario($client, [['2027-01-05', '2027-01-06']]);
        $this->addMemberAs($client, $s['creator'], $s['planningId'], (string) $this->line($s['planningId'], 0)->getPlanningTeam()->getStableId(), 'carol@example.com', 'MEMBER');
        $carolOnPrimary = static::getContainer()->get(PlanningTeamMemberRepository::class)->findOneBy(['planningTeam' => $this->line($s['planningId'], 0)->getPlanningTeam(), 'user' => static::getContainer()->get(UserRepository::class)->findOneByEmail('carol@example.com')]);
        $s['carolAway'] = static::getContainer()->get(TeamMemberNonParticipationService::class)->create($carolOnPrimary, new \DateTimeImmutable('2027-01-04 12:00 UTC'), new \DateTimeImmutable('2027-01-06 12:00 UTC'));
        $this->unavailableOn5('admin@example.com');
        $this->unavailableOn5('bob@example.com');
        $this->unavailableOn5('dave@example.com');

        self::assertSame('SUCCEEDED', $this->launch($client, $s)['status']);
        self::assertSame('alice@example.com', $this->holderOn5($s, 0));
        self::assertSame('carol@example.com', $this->holderOn5($s, 1));

        return $s;
    }

    /**
     * Launch 2: Carol takes the source day; the Renfort solve fails and the
     * Renfort keeps Carol on the same day. Written, Carol would hold the
     * source and its reinforcement at once: nothing is written.
     */
    public function testASourceResultIncompatibleWithTheReinforcementThatStaysIsNotWritten(): void
    {
        $client = static::createClient();
        $s = $this->carolReinforcesAlice();
        $before = $this->fingerprint();
        $this->db()->executeStatement('DELETE FROM team_member_non_participation_periods WHERE id = ?', [$s['carolAway']->getId()]);
        $this->unavailableOn5('alice@example.com');
        // The Renfort problem is the one with no unit at all (Carol is no trigger: no reinforcement required).
        FaultInjectingPlanningSolver::$errorWhen = static fn (OptimizationProblem $p): bool => [] === $p->getRequiredDutyUnits() && [] === $p->getOptionalDutyUnits();

        $job = $this->launch($client, $s);

        $lines = array_column($job['outcome']['lines'], null, 'lineName');
        $primary = array_values(array_filter($lines, static fn (array $l): bool => 'Renfort' !== $l['lineName']))[0];
        self::assertSame('launch_inconsistent', $primary['error']);
        self::assertSame('CROSS_LINE_CONFLICT', $primary['conflicts'][0]['reason'], 'the same day, the same person, twice');
        self::assertSame('FAILED', $lines['Renfort']['status']);
        self::assertSame($before, $this->fingerprint(), 'nothing written, nothing removed');
        self::assertSame('alice@example.com', $this->holderOn5($s, 0));
        self::assertSame('carol@example.com', $this->holderOn5($s, 1));
    }

    /**
     * The source line's result is not written (its duties changed during the
     * launch): the reinforcement line, whose demand was decided from it, is
     * not written either.
     */
    public function testAReinforcementLineIsNeverWrittenWithoutItsSourcesResult(): void
    {
        $client = static::createClient();
        $s = $this->carolReinforcesAlice();
        $before = $this->fingerprint();
        $container = static::getContainer();
        $primaryPeriodId = (int) $this->line($s['planningId'], 0)->requireSingleCyclePeriod()->getId();
        // During the Renfort solve (the second one), a duty is added to the source line: its result is stale.
        FaultInjectingPlanningSolver::$duringSolve = static function () use ($container, $primaryPeriodId): void {
            FaultInjectingPlanningSolver::$duringSolve = static function () use ($container, $primaryPeriodId): void {
                $em = $container->get(EntityManagerInterface::class);
                $period = $em->find(PlanningPeriod::class, $primaryPeriodId);
                $type = $em->getRepository(DutyType::class)->findOneBy(['team' => $period->getTeam()]);
                $container->get(DutyMaterializationService::class)->createStandaloneDuty($period, $type, new \DateTimeImmutable('2027-01-19'), new \DateTimeImmutable('2027-01-20'));
            };
        };

        $job = $this->launch($client, $s);

        $lines = array_column($job['outcome']['lines'], null, 'lineName');
        $primary = array_values(array_filter($lines, static fn (array $l): bool => 'Renfort' !== $l['lineName']))[0];
        self::assertSame('stale_generation_data', $primary['error']);
        self::assertSame('source_not_written', $lines['Renfort']['error']);
        self::assertSame($before, $this->fingerprint());
    }
}
