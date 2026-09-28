<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Demand\DemandReason;
use App\Eligibility\DutyGroupUnit;
use App\Eligibility\ExclusionReason;
use App\Entity\Duty;
use App\Entity\DutyDemandType;
use App\Entity\PlanningLine;
use App\Exception\InvalidConditionalDutyException;
use App\Repository\DutyRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\UserRepository;
use App\Service\DutyMaterializationService;
use App\Service\EligibilityMatrixBuilder;
use App\Service\LiveDemandViewFactory;
use App\Service\PlanningGenerationService;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningSnapshotService;
use App\Service\ReassignmentCandidateService;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Conditional duties, their explicit coverage source and the LIVE demand
 * (docs/decisions.md D163), end to end on the real API and database.
 *
 * Scenario: the pilot planning's main line (admin, alice, bob) has duties
 * on Tuesday 5, Wednesday 6, Friday 8, Saturday 9 and Sunday 10 January
 * 2027. A "Renfort" line (alice, carol) depends on it — Dr A = admin (no
 * trigger), Dr B = alice (every day), Dr C = bob (Friday to Sunday) — with
 * its own weekly structure: a Friday–Sunday block, Tuesday alone, Monday,
 * Wednesday and Thursday excluded.
 */
final class ConditionalLineTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;

    private const PRIMARY_DAYS = [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07'], ['2027-01-08', '2027-01-09'], ['2027-01-09', '2027-01-10'], ['2027-01-10', '2027-01-11']];

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    /**
     * @param list<array{0: string, 1: string}> $primaryDays
     *
     * @return array<string, mixed>
     */
    private function scenario(KernelBrowser $client, bool $generatePrimary = true, array $primaryDays = self::PRIMARY_DAYS): array
    {
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], $primaryDays);
        if ($generatePrimary) {
            $this->generate($client, $s);
        }

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/lines", ['name' => 'Renfort'], $s['creator']);
        self::assertResponseStatusCodeSame(201);
        $lines = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'];
        $s['primaryLineId'] = $lines[0]['stableId'];
        $s['renfortLineId'] = $lines[1]['stableId'];
        $s['renfortTeamId'] = $lines[1]['team']['stableId'];
        $s['carol'] = $this->userToken($client, 'carol@example.com');
        foreach (['alice@example.com', 'carol@example.com'] as $email) {
            $this->addMemberAs($client, $s['creator'], $s['planningId'], $s['renfortTeamId'], $email, 'MEMBER');
        }

        $this->api($client, 'PUT', "/api/planning-lines/{$s['renfortLineId']}/demand-policy", [
            'schemaVersion' => 1,
            'mode' => 'CONDITIONAL_ON_SOURCE_ASSIGNMENT',
            'source' => ['lineStableId' => $s['primaryLineId']],
            'triggers' => [
                ['userStableId' => $this->userId('alice@example.com'), 'weekdays' => ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'], 'increment' => 1],
                ['userStableId' => $this->userId('bob@example.com'), 'weekdays' => ['FRIDAY', 'SATURDAY', 'SUNDAY'], 'increment' => 1],
            ],
        ], $s['creator']);
        self::assertResponseIsSuccessful();

        $this->api($client, 'PUT', "/api/planning-lines/{$s['renfortLineId']}/week-structure", [
            'blocks' => [['name' => 'Week-end', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => '']],
            'solo' => ['MAR'],
            'soloFamily' => '',
            'excluded' => ['LUN', 'MER', 'JEU'],
        ], $s['creator']);
        self::assertResponseIsSuccessful();

        return $s;
    }

    /**
     * @return array<string, mixed> the preflight, which materializes both lines, sources first
     */
    private function preflight(KernelBrowser $client, array $s): array
    {
        $preflight = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/generation-preflight", token: $s['creator']);
        self::assertResponseIsSuccessful();

        return $preflight;
    }

    private function userId(string $email): string
    {
        return (string) static::getContainer()->get(UserRepository::class)->findOneByEmail($email)->getStableId();
    }

    private function line(string $planningStableId, int $index): PlanningLine
    {
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($planningStableId);

        return static::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[$index];
    }

    /**
     * @return array<string, Duty> the Renfort line's duties by local date
     */
    private function renfortDuties(string $planningStableId): array
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $duties = [];
        foreach (static::getContainer()->get(DutyRepository::class)->findByPlanningPeriod($this->line($planningStableId, 1)->getPlanningPeriod()) as $duty) {
            $duties[$duty->getLocalDate()->format('Y-m-d')] = $duty;
        }
        ksort($duties);

        return $duties;
    }

    /** Puts $email on the main line's duty of $date (unassigning first, so the target is never the holder already). */
    private function holdPrimary(KernelBrowser $client, array $s, string $date, ?string $email): void
    {
        $duty = $this->dutyOn($s['planningId'], $date);
        if (null !== $this->currentCalendar($s['planningId'])['0|'.$date.'|ONCALL']) {
            $this->unassignDuty($client, $s, $duty);
            self::assertResponseIsSuccessful();
        }
        if (null !== $email) {
            $this->reassignTo($client, $s, $duty, $email);
            self::assertResponseIsSuccessful();
        }
    }

    private function liveDemand(string $planningStableId, string $date): \App\Demand\DutyDemand
    {
        $container = static::getContainer();
        $container->get(EntityManagerInterface::class)->clear();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $duty = $this->renfortDuties($planningStableId)[$date];

        return $container->get(LiveDemandViewFactory::class)->forPlanning($planning)->forDuty($duty);
    }

    // --- materialization ---------------------------------------------------------------------------

    public function testAConditionalLineMaterializesConditionalDutiesLinkedToTheSourceDutyOfTheSameDay(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $preflight = $this->preflight($client, $s);

        $duties = $this->renfortDuties($s['planningId']);
        self::assertSame(['2027-01-05', '2027-01-08', '2027-01-09', '2027-01-10'], array_keys($duties), 'Tuesday alone and the Friday–Sunday block; never Wednesday (excluded), though alice triggers every day.');
        foreach ($duties as $date => $duty) {
            self::assertSame(DutyDemandType::CONDITIONAL, $duty->getDemandType());
            self::assertSame($this->dutyOn($s['planningId'], $date), (string) $duty->getCoverageSource()->getStableId(), "{$date} is linked to the main line's duty of that day.");
        }
        self::assertNull($duties['2027-01-05']->getGroupInstance());
        self::assertNotNull($duties['2027-01-08']->getGroupInstance());
        self::assertSame($duties['2027-01-08']->getGroupInstance(), $duties['2027-01-10']->getGroupInstance(), 'The block is one atomic unit.');

        // Explicit states in the preflight: generation of a conditional line is not available yet; the other weeks have
        // no main-line duty to depend on.
        self::assertContains(['code' => 'CONDITIONAL_GENERATION_NOT_YET_AVAILABLE', 'lineStableId' => $s['renfortLineId'], 'lineName' => 'Renfort'], $preflight['blockers']);
        self::assertContains(['code' => 'COVERAGE_SOURCE_MISSING', 'lineStableId' => $s['renfortLineId'], 'lineName' => 'Renfort'], $preflight['warnings']);
        self::assertFalse($preflight['canGenerate']);
    }

    public function testMaterializationIsDeterministicAndIdempotent(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->preflight($client, $s);
        $first = array_map(static fn (Duty $d): string => (string) $d->getCoverageSource()->getStableId(), $this->renfortDuties($s['planningId']));

        $this->preflight($client, $s);

        self::assertSame($first, array_map(static fn (Duty $d): string => (string) $d->getCoverageSource()->getStableId(), $this->renfortDuties($s['planningId'])), 'Same duties, same sources, never duplicated.');
    }

    public function testABlockWithADayWithoutSourceIsNeverMaterializedPartially(): void
    {
        $client = static::createClient();
        // No main-line duty on Sunday 10.
        $s = $this->scenario($client, primaryDays: [['2027-01-05', '2027-01-06'], ['2027-01-08', '2027-01-09'], ['2027-01-09', '2027-01-10']]);

        $this->preflight($client, $s);

        self::assertSame(['2027-01-05'], array_keys($this->renfortDuties($s['planningId'])), 'The Friday–Sunday block is atomic: without a Sunday source, none of it exists.');
    }

    public function testAnAmbiguousSourceIsReportedNeverChosen(): void
    {
        $client = static::createClient();
        // Two main-line duties on Tuesday 5.
        $s = $this->scenario($client, primaryDays: [['2027-01-05', '2027-01-06'], ['2027-01-05 08:00', '2027-01-05 20:00']]);

        $preflight = $this->preflight($client, $s);

        self::assertArrayNotHasKey('2027-01-05', $this->renfortDuties($s['planningId']));
        self::assertContains(['code' => 'AMBIGUOUS_COVERAGE_SOURCE', 'lineStableId' => $s['renfortLineId'], 'lineName' => 'Renfort'], $preflight['blockers']);
    }

    public function testTheSourceLineIsMaterializedBeforeItsConditionalLine(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        // The main line only gets its weekly structure — no duty exists yet on either line.
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/lines", ['name' => 'Renfort'], $s['creator']);
        $lines = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'];
        $this->api($client, 'PUT', "/api/planning-lines/{$lines[0]['stableId']}/week-structure", ['blocks' => [], 'solo' => ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'], 'soloFamily' => '', 'excluded' => []], $s['creator']);
        $this->api($client, 'PUT', "/api/planning-lines/{$lines[1]['stableId']}/demand-policy", ['schemaVersion' => 1, 'mode' => 'CONDITIONAL_ON_SOURCE_ASSIGNMENT', 'source' => ['lineStableId' => $lines[0]['stableId']], 'triggers' => []], $s['creator']);
        $this->api($client, 'PUT', "/api/planning-lines/{$lines[1]['stableId']}/week-structure", ['blocks' => [], 'solo' => ['MAR'], 'soloFamily' => '', 'excluded' => ['LUN', 'MER', 'JEU', 'VEN', 'SAM', 'DIM']], $s['creator']);

        // One single preflight: the source calendar is built first, so every Tuesday of the conditional line finds its source.
        $this->preflight($client, $s);

        $renfort = $this->renfortDuties($s['planningId']);
        self::assertNotEmpty($renfort);
        foreach ($renfort as $date => $duty) {
            self::assertSame('2', $duty->getLocalDate()->format('N'), 'Tuesdays only.');
            self::assertSame($date, $duty->getCoverageSource()->getLocalDate()->format('Y-m-d'));
        }
    }

    // --- guards ------------------------------------------------------------------------------------

    public function testOnlyConditionalDutiesOfTheSourceLineCanBeCreatedOnAConditionalLine(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->preflight($client, $s);
        $container = static::getContainer();
        $materializer = $container->get(DutyMaterializationService::class);
        $renfort = $this->line($s['planningId'], 1);
        $primary = $this->line($s['planningId'], 0);
        $renfortType = $this->renfortDuties($s['planningId'])['2027-01-05']->getDutyType();
        $primarySunday = $this->dutyEntityOn($s['planningId'], '2027-01-10');
        $renfortType = $container->get(EntityManagerInterface::class)->find(\App\Entity\DutyType::class, $renfortType->getId());
        $renfort = $container->get(PlanningLineRepository::class)->find($renfort->getId());
        $primary = $container->get(PlanningLineRepository::class)->find($primary->getId());

        foreach ([
            'an intrinsic duty on a conditional line' => fn () => $materializer->createStandaloneDuty($renfort->getPlanningPeriod(), $renfortType, new \DateTimeImmutable('2027-01-17'), new \DateTimeImmutable('2027-01-18')),
            'a conditional duty on an independent line' => fn () => $materializer->createStandaloneDuty($primary->getPlanningPeriod(), $primarySunday->getDutyType(), new \DateTimeImmutable('2027-01-17'), new \DateTimeImmutable('2027-01-18'), DutyDemandType::CONDITIONAL, coverageSource: $this->renfortDuties($s['planningId'])['2027-01-05']),
        ] as $case => $attempt) {
            try {
                $attempt();
                self::fail("Must be refused: {$case}.");
            } catch (InvalidConditionalDutyException|\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testACoverageSourceFromAnotherLineThanThePolicySourceIsRefused(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/lines", ['name' => 'Autre'], $s['creator']);
        $this->prepareLine($s['planningId'], [['2027-01-12', '2027-01-13']], lineIndex: 2);
        $container = static::getContainer();
        $container->get(EntityManagerInterface::class)->clear();
        $other = $this->dutyEntityOn($s['planningId'], '2027-01-12', 2);
        $renfort = $this->line($s['planningId'], 1);
        $type = new \App\Entity\DutyType($renfort->getPlanningTeam(), 'X'.bin2hex(random_bytes(2)), 'X');
        $container->get(EntityManagerInterface::class)->persist($type);

        $this->expectException(InvalidConditionalDutyException::class);
        $this->expectExceptionMessage('source line');
        $container->get(DutyMaterializationService::class)->createStandaloneDuty($renfort->getPlanningPeriod(), $type, new \DateTimeImmutable('2027-01-12'), new \DateTimeImmutable('2027-01-13'), DutyDemandType::CONDITIONAL, coverageSource: $other);
    }

    public function testTheDatabaseRefusesAConditionalDutyWithoutSourceAndASelfReference(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->preflight($client, $s);
        $id = (int) $this->renfortDuties($s['planningId'])['2027-01-05']->getId();
        $connection = static::getContainer()->get(Connection::class);

        foreach (['UPDATE duties SET coverage_source_id = NULL WHERE id = :id', 'UPDATE duties SET coverage_source_id = id WHERE id = :id', "UPDATE duties SET demand_type = 'REQUIRED' WHERE id = :id"] as $sql) {
            $connection->executeStatement('SAVEPOINT chk');
            try {
                $connection->executeStatement($sql, ['id' => $id]);
                self::fail("The database must refuse: {$sql}");
            } catch (DriverException) {
                $connection->executeStatement('ROLLBACK TO SAVEPOINT chk');
            }
        }
    }

    // --- LIVE demand -----------------------------------------------------------------------------------

    public function testTheLiveDemandFollowsTheCurrentHolderOfTheSourceWithoutEverTouchingTheDuty(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->preflight($client, $s);

        // Dr A (admin) on Tuesday: no trigger.
        $this->holdPrimary($client, $s, '2027-01-05', 'admin@example.com');
        $demand = $this->liveDemand($s['planningId'], '2027-01-05');
        self::assertFalse($demand->required);
        self::assertSame(DemandReason::HOLDER_HAS_NO_TRIGGER, $demand->reason);
        self::assertSame($this->userId('admin@example.com'), $demand->ownDay->sourceHolderUserStableId);

        // Replaced by Dr B (alice): immediately required.
        $this->holdPrimary($client, $s, '2027-01-05', 'alice@example.com');
        $demand = $this->liveDemand($s['planningId'], '2027-01-05');
        self::assertTrue($demand->required);
        self::assertSame(DemandReason::TRIGGERED, $demand->reason);
        self::assertSame($this->dutyOn($s['planningId'], '2027-01-05'), (string) $demand->ownDay->sourceDuty->getStableId());

        // Dr C (bob) does not trigger on a Tuesday.
        $this->holdPrimary($client, $s, '2027-01-05', 'bob@example.com');
        self::assertSame(DemandReason::WEEKDAY_NOT_TRIGGERED, $this->liveDemand($s['planningId'], '2027-01-05')->reason);

        // Nobody on the main line's Tuesday.
        $this->holdPrimary($client, $s, '2027-01-05', null);
        self::assertSame(DemandReason::SOURCE_UNASSIGNED, $this->liveDemand($s['planningId'], '2027-01-05')->reason);

        // The duty itself never changed.
        self::assertSame(DutyDemandType::CONDITIONAL, $this->renfortDuties($s['planningId'])['2027-01-05']->getDemandType());
        self::assertSame(['CONDITIONAL'], array_values(array_unique(static::getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT demand_type FROM duties WHERE planning_period_id = ?',
            [$this->line($s['planningId'], 1)->getPlanningPeriod()->getId()],
        ))));
    }

    public function testOneTriggeredDayOfTheBlockRequiresTheWholeBlockLive(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->preflight($client, $s);
        $this->holdPrimary($client, $s, '2027-01-08', 'admin@example.com');
        $this->holdPrimary($client, $s, '2027-01-09', 'bob@example.com');
        $this->holdPrimary($client, $s, '2027-01-10', 'admin@example.com');

        $friday = $this->liveDemand($s['planningId'], '2027-01-08');
        $saturday = $this->liveDemand($s['planningId'], '2027-01-09');

        self::assertTrue($friday->required);
        self::assertSame(DemandReason::TRIGGERED_BY_BLOCK, $friday->reason);
        self::assertSame(DemandReason::HOLDER_HAS_NO_TRIGGER, $friday->ownDay->reason);
        self::assertSame(DemandReason::TRIGGERED, $saturday->reason);
        self::assertSame(['2027-01-09'], array_map(static fn (Duty $d): string => $d->getLocalDate()->format('Y-m-d'), $friday->triggeringDuties), 'Saturday triggered it.');

        // Saturday back to Dr A: nobody triggers anything any more.
        $this->holdPrimary($client, $s, '2027-01-09', 'admin@example.com');
        self::assertFalse($this->liveDemand($s['planningId'], '2027-01-10')->required);
    }

    public function testASourceLineNeverGeneratedIsExplicit(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, generatePrimary: false);
        $this->preflight($client, $s);

        $demand = $this->liveDemand($s['planningId'], '2027-01-05');

        self::assertFalse($demand->required);
        self::assertSame(DemandReason::SOURCE_LINE_NOT_GENERATED, $demand->reason);
    }

    public function testIndependentLinesKeepTheirIntrinsicDemand(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->preflight($client, $s);
        $container = static::getContainer();
        $container->get(EntityManagerInterface::class)->clear();
        $view = $container->get(LiveDemandViewFactory::class)->forPlanning($container->get(PlanningRepository::class)->findOneByStableId($s['planningId']));

        $demand = $view->forDuty($this->dutyEntityOn($s['planningId'], '2027-01-05'));

        self::assertTrue($demand->required);
        self::assertSame(DemandReason::INTRINSIC_REQUIRED, $demand->reason);
    }

    // --- SELF_COVERAGE ------------------------------------------------------------------------------------

    public function testNobodyCanBeTheirOwnReinforcementFrozenOrLive(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->preflight($client, $s);
        // alice (member of both lines) holds the main line's Saturday: she triggers the Friday–Sunday block.
        $this->holdPrimary($client, $s, '2027-01-09', 'alice@example.com');

        $container = static::getContainer();
        $container->get(EntityManagerInterface::class)->clear();
        $renfort = $this->line($s['planningId'], 1);
        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $renfort->getPlanningTeam());
        $generation = $container->get(PlanningGenerationService::class)->create($renfort->getPlanningPeriod(), null);
        $snapshot = $container->get(PlanningSnapshotService::class)->createSnapshot($generation);
        $block = array_values(array_filter($this->renfortDuties($s['planningId']), static fn (Duty $d): bool => null !== $d->getGroupInstance()));
        $block = array_map(static fn (Duty $d): Duty => $container->get(DutyRepository::class)->find($d->getId()), $block);
        $aliceOnRenfort = $container->get(PlanningTeamMemberRepository::class)->findOneByStableId($this->memberStableIdIn($s['planningId'], 'alice@example.com', 1));

        // Frozen path (generation): her main-line Saturday is a commitment of the snapshot.
        $matrix = $container->get(EligibilityMatrixBuilder::class)->build($snapshot);
        $unit = new DutyGroupUnit($block[0]->getGroupInstance(), $block);
        foreach ($snapshot->getMembers() as $member) {
            if ((string) $member->getSourceUserStableId() === $this->userId('alice@example.com')) {
                self::assertSame([ExclusionReason::SELF_COVERAGE], array_map(static fn ($e) => $e->reason, $matrix->get($unit, $member)->exclusions), 'SELF_COVERAGE, not merely a cross-line conflict.');
            }
        }

        // Live path (reassignment, completion, publication preflight).
        self::assertSame(ExclusionReason::SELF_COVERAGE, $container->get(ReassignmentCandidateService::class)->firstBlockingReason($generation, $block, $aliceOnRenfort));
    }
}
