<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Demand\DemandReason;
use App\Demand\Weekday;
use App\Eligibility\ExclusionReason;
use App\Entity\Duty;
use App\Entity\DutyDemandType;
use App\Entity\PlanningGenerationStatus;
use App\Fairness\FairnessDimensionKey;
use App\Fairness\OptimizationProblem;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningRepository;
use App\Service\DutyMaterializationService;
use App\Service\EligibilityMatrixBuilder;
use App\Service\FairnessContextBuilder;
use App\Service\LiveDemandViewFactory;
use App\Service\SnapshotDemandViewFactory;
use App\Service\SnapshotHasher;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\ConditionalLineTestHelpers;
use App\Tests\FaultInjectingPlanningSolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Generating a conditional line (docs/decisions.md D164), end to end: the
 * real asynchronous job (D149) run by the worker, real OR-Tools solves.
 *
 * Scenario: the pilot planning's main line (admin, alice, bob) and a
 * "Renfort" line (carol, dave — plus alice when a test says so) depending
 * on it. Dr A = admin (no trigger), Dr B = alice (every day), Dr C = bob
 * (Friday to Sunday). Renfort's weekly structure: Tuesday alone, a
 * Friday–Sunday block, nothing on Monday, Wednesday, Thursday. Main-line
 * holders are forced by declaring the two other people unavailable.
 */
final class ConditionalGenerationTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ConditionalLineTestHelpers;
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        FaultInjectingPlanningSolver::reset();
    }

    protected function tearDown(): void
    {
        FaultInjectingPlanningSolver::reset();
        parent::tearDown();
    }

    // --- the running example ------------------------------------------------------------------------

    public function testDrAEverywhereGivesAZeroUnitCompletedTarget(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->forceHolders($client, $s, ['2027-01-05' => 'admin@example.com', '2027-01-08' => 'admin@example.com', '2027-01-09' => 'admin@example.com', '2027-01-10' => 'admin@example.com']);

        // The generation preflight never counts a reinforcement before its source line is assigned (D167).
        $preflightLines = array_column($this->api($client, 'GET', "/api/plannings/{$s['planningId']}/generation-preflight", token: $s['creator'])['lines'], null, 'name');
        self::assertNull($preflightLines['Seniors']['demandSourceLineName']);
        self::assertSame('Seniors', $preflightLines['Renfort']['demandSourceLineName']);
        self::assertSame([], $preflightLines['Renfort']['familyUnitCounts']);

        $job = $this->launch($client, $s);

        self::assertSame('SUCCEEDED', $job['status']);
        self::assertSame('COMPLETE', $job['outcome']['coverage']);
        $renfortOutcome = $job['outcome']['lines'][1];
        self::assertSame('COMPLETED', $renfortOutcome['status']);
        self::assertSame(['requiredUnitCount' => 0, 'notRequiredUnitCount' => 2, 'undeterminedUnitCount' => 0], $renfortOutcome['demand']);
        self::assertSame(0, $renfortOutcome['assignmentCount']);
        self::assertSame([], array_filter($this->renfortCalendar($s)));

        // A real, complete historical record: snapshot, frozen decisions, hash.
        $generation = $this->currentGeneration($s, 1);
        self::assertSame(PlanningGenerationStatus::COMPLETED, $generation->getStatus());
        self::assertNotNull($generation->getSnapshotHash());
        $snapshot = $this->snapshotOf($generation);
        self::assertCount(4, $snapshot->getDemandDecisions());
        foreach ($snapshot->getDemandDecisions() as $decision) {
            self::assertFalse($decision->getRequired());
            self::assertSame(DemandReason::HOLDER_HAS_NO_TRIGGER, $decision->getDayReason());
            self::assertSame($this->userId('admin@example.com'), (string) $decision->getSourceUserStableId());
        }
        self::assertSame([], [...$this->renfortProblem($s)->getRequiredDutyUnits(), ...$this->renfortProblem($s)->getOptionalDutyUnits()], 'The solver received an empty problem — never an optional unit.');
    }

    public function testDrBRequiresEveryReinforcementWhichIsGeneratedAndAssigned(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->forceHolders($client, $s, ['2027-01-05' => 'alice@example.com', '2027-01-08' => 'alice@example.com', '2027-01-09' => 'alice@example.com', '2027-01-10' => 'alice@example.com']);

        $job = $this->launch($client, $s);

        self::assertSame('SUCCEEDED', $job['status']);
        self::assertSame(['requiredUnitCount' => 2, 'notRequiredUnitCount' => 0, 'undeterminedUnitCount' => 0], $job['outcome']['lines'][1]['demand']);
        $calendar = $this->renfortCalendar($s);
        self::assertNotContains(null, $calendar, 'Every reinforcement is covered.');
        self::assertSame($calendar['2027-01-08'], $calendar['2027-01-10'], 'The block went to one person.');
        foreach ($calendar as $holder) {
            self::assertContains($holder, ['carol@example.com', 'dave@example.com']);
        }
        foreach ($this->renfortDuties($s) as $duty) {
            self::assertSame(DutyDemandType::CONDITIONAL, $duty->getDemandType(), 'Generation never rewrites the duty.');
        }
    }

    public function testDrCOnlyOnTheirWeekdays(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->forceHolders($client, $s, ['2027-01-05' => 'bob@example.com', '2027-01-08' => 'bob@example.com', '2027-01-09' => 'bob@example.com', '2027-01-10' => 'bob@example.com']);

        $this->launch($client, $s);

        $calendar = $this->renfortCalendar($s);
        self::assertNull($calendar['2027-01-05'], 'Tuesday: Dr C does not trigger.');
        self::assertNotNull($calendar['2027-01-08']);
        $decisions = $this->decisionsByDate($s);
        self::assertSame(DemandReason::WEEKDAY_NOT_TRIGGERED, $decisions['2027-01-05']->getDayReason());
        self::assertFalse($decisions['2027-01-05']->getRequired());
        self::assertSame(Weekday::TUESDAY, $decisions['2027-01-05']->getWeekday());
        self::assertTrue($decisions['2027-01-09']->getRequired());
    }

    /**
     * @return array<string, \App\Entity\PlanningSnapshotDemandDecision>
     */
    private function decisionsByDate(array $s): array
    {
        $decisions = [];
        foreach ($this->snapshotOf($this->currentGeneration($s, 1))->getDemandDecisions() as $decision) {
            $decisions[$decision->getDuty()->getLocalDate()->format('Y-m-d')] = $decision;
        }

        return $decisions;
    }

    public function testOneTriggeredDayPutsTheWholeBlockAndOnlyItInTheProblem(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->forceHolders($client, $s, ['2027-01-05' => 'admin@example.com', '2027-01-08' => 'admin@example.com', '2027-01-09' => 'bob@example.com', '2027-01-10' => 'admin@example.com']);

        $this->launch($client, $s);

        // The problem the solver received: the Friday–Sunday block only — never Tuesday.
        $problem = $this->renfortProblem($s);
        self::assertSame([], $problem->getOptionalDutyUnits());
        self::assertCount(1, $problem->getRequiredDutyUnits());
        $blockDates = array_map(static fn (Duty $d): string => $d->getLocalDate()->format('Y-m-d'), $problem->getRequiredDutyUnits()[0]->getDuties());
        sort($blockDates);
        self::assertSame(['2027-01-08', '2027-01-09', '2027-01-10'], $blockDates);

        // Explained day by day in the history.
        $decisions = $this->decisionsByDate($s);
        self::assertSame(DemandReason::TRIGGERED, $decisions['2027-01-09']->getReason());
        self::assertSame(DemandReason::TRIGGERED_BY_BLOCK, $decisions['2027-01-08']->getReason());
        self::assertSame(DemandReason::HOLDER_HAS_NO_TRIGGER, $decisions['2027-01-08']->getDayReason());
        self::assertTrue($decisions['2027-01-08']->getRequired());
        self::assertFalse($decisions['2027-01-05']->getRequired());
        $view = static::getContainer()->get(SnapshotDemandViewFactory::class)->forSnapshot($this->snapshotOf($this->currentGeneration($s, 1)));
        self::assertSame(['2027-01-09'], array_map(static fn (Duty $d): string => $d->getLocalDate()->format('Y-m-d'), $view->forDuty($this->renfortDuties($s)['2027-01-08'])->triggeringDuties));

        $calendar = $this->renfortCalendar($s);
        self::assertNull($calendar['2027-01-05']);
        self::assertNotNull($calendar['2027-01-08']);
        self::assertSame($calendar['2027-01-08'], $calendar['2027-01-10']);
    }

    // --- the source line ---------------------------------------------------------------------------------

    public function testAFailedSourceLeavesTheTargetUnresolved(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $primaryPeriodId = $this->line($s['planningId'], 0)->getPlanningPeriod()->getId();
        FaultInjectingPlanningSolver::$errorWhen = static function (OptimizationProblem $problem) use ($primaryPeriodId): bool {
            $units = $problem->getRequiredDutyUnits();

            return [] !== $units && $units[0]->getDuties()[0]->getPlanningPeriod()->getId() === $primaryPeriodId;
        };

        $job = $this->launch($client, $s);

        self::assertSame('FAILED', $job['status']);
        self::assertSame('FAILED', $job['outcome']['lines'][0]['status']);
        $renfort = $job['outcome']['lines'][1];
        self::assertSame('source_generation_failed', $renfort['error']);
        self::assertNull($renfort['generationStableId'], 'The target was never resolved — no generation at all.');
        self::assertNull($this->currentGeneration($s, 1));
        self::assertNull($this->renfortProblem($s), 'The solver never saw the conditional line.');
    }

    public function testAnUnassignedSourceMakesTheDemandUndeterminedNeverNotRequired(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        // Nobody can hold the main line's Tuesday; Friday–Sunday go to Dr A.
        foreach (['admin', 'alice', 'bob'] as $who) {
            $this->declareRange($client, $s[$who], '2027-01-05', '2027-01-06');
        }
        $this->forceHolders($client, $s, ['2027-01-08' => 'admin@example.com', '2027-01-09' => 'admin@example.com', '2027-01-10' => 'admin@example.com']);

        $job = $this->launch($client, $s);

        self::assertSame('SUCCEEDED', $job['status']);
        self::assertSame('INCOMPLETE', $job['outcome']['coverage'], 'Never reported complete while a reinforcement could not be evaluated.');
        self::assertSame('COMPLETED', $job['outcome']['lines'][1]['status']);
        self::assertSame(['requiredUnitCount' => 0, 'notRequiredUnitCount' => 1, 'undeterminedUnitCount' => 1], $job['outcome']['lines'][1]['demand']);
        $tuesday = $this->decisionsByDate($s)['2027-01-05'];
        self::assertNull($tuesday->getRequired(), 'Undetermined — never recorded as "not required".');
        self::assertSame(DemandReason::SOURCE_UNASSIGNED, $tuesday->getReason());
        self::assertNull($tuesday->getSourceUserStableId());
        self::assertFalse(static::getContainer()->get(SnapshotDemandViewFactory::class)->forSnapshot($this->snapshotOf($this->currentGeneration($s, 1)))->forDuty($this->renfortDuties($s)['2027-01-05'])->determined);
    }

    // --- history vs live -----------------------------------------------------------------------------------

    public function testTheHistoricalDemandNeverMovesButTheLiveOneFollowsTheCalendar(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->forceHolders($client, $s, ['2027-01-05' => 'bob@example.com', '2027-01-08' => 'admin@example.com', '2027-01-09' => 'admin@example.com', '2027-01-10' => 'admin@example.com']);
        $this->launch($client, $s);
        $generation = $this->currentGeneration($s, 1);
        $hash = $generation->getSnapshotHash();
        $tuesday = $this->renfortDuties($s)['2027-01-05'];

        // 1. The policy changes: Dr C now triggers every day.
        $this->setPolicy($client, $s, [['alice@example.com', self::ALL_DAYS], ['bob@example.com', self::ALL_DAYS]]);
        // The live calendar keeps the policy version its generation was built with (D164): bob still holds Tuesday,
        // and Tuesday is still not triggered — the new version only applies from the next generation.
        $liveBefore = static::getContainer()->get(LiveDemandViewFactory::class)->forPlanning(static::getContainer()->get(PlanningRepository::class)->findOneByStableId($s['planningId']))->forDuty($tuesday);
        self::assertFalse($liveBefore->required);
        self::assertSame(DemandReason::WEEKDAY_NOT_TRIGGERED, $liveBefore->reason);
        // 2. The main line's Tuesday loses its holder.
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-05'));
        self::assertResponseIsSuccessful();

        $container = static::getContainer();
        $container->get(EntityManagerInterface::class)->clear();
        $generation = $container->get(PlanningGenerationRepository::class)->find($generation->getId());
        $snapshot = $this->snapshotOf($generation);
        $tuesday = $container->get(DutyRepository::class)->find($tuesday->getId());

        // The history is untouched: frozen holder, frozen trigger (Friday–Sunday), frozen answer, same hash.
        $historical = $container->get(SnapshotDemandViewFactory::class)->forSnapshot($snapshot)->forDuty($tuesday);
        self::assertFalse($historical->required);
        self::assertSame(DemandReason::WEEKDAY_NOT_TRIGGERED, $historical->reason);
        self::assertSame($this->userId('bob@example.com'), $historical->ownDay->sourceHolderUserStableId);
        self::assertSame([Weekday::FRIDAY, Weekday::SATURDAY, Weekday::SUNDAY], $historical->ownDay->trigger?->weekdays, 'The frozen trigger, never the policy in force.');
        self::assertSame($hash, $container->get(SnapshotHasher::class)->hash($snapshot));

        // The live calendar follows the holders at once: Tuesday now has none.
        $live = $container->get(LiveDemandViewFactory::class)->forPlanning($container->get(PlanningRepository::class)->findOneByStableId($s['planningId']))->forDuty($tuesday);
        self::assertSame(DemandReason::SOURCE_UNASSIGNED, $live->reason);
        self::assertFalse($live->determined);
    }

    public function testTheHashFollowsTheDemandDecisions(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->forceHolders($client, $s, ['2027-01-05' => 'bob@example.com', '2027-01-08' => 'admin@example.com', '2027-01-09' => 'admin@example.com', '2027-01-10' => 'admin@example.com']);
        $this->launch($client, $s);
        $first = $this->currentGeneration($s, 1)->getSnapshotHash();

        // Same data, but Dr C now also triggers on Tuesday: another decision, another hash.
        $this->setPolicy($client, $s, [['alice@example.com', self::ALL_DAYS], ['bob@example.com', ['TUESDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY']]]);
        $this->launch($client, $s);

        self::assertNotSame($first, $this->currentGeneration($s, 1)->getSnapshotHash());
        self::assertTrue($this->decisionsByDate($s)['2027-01-05']->getRequired());
    }

    public function testAnIndependentSnapshotKeepsItsLegacyCanonicalForm(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->launch($client, $s);

        $canonical = static::getContainer()->get(SnapshotHasher::class)->canonicalForm($this->snapshotOf($this->currentGeneration($s, 0)));

        self::assertSame(['restPolicy', 'members', 'duties'], array_keys($canonical), 'No new section for an independent line.');
        self::assertSame(['stableId', 'startsAt', 'endsAt', 'dutyTypeStableId', 'demandType', 'criticality', 'groupInstanceStableId', 'allocationFamilyStableId'], array_keys($canonical['duties'][0]), 'An intrinsic duty keeps exactly its pre-D164 canonical form.');
        self::assertSame(hash('sha256', json_encode($canonical, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)), $this->currentGeneration($s, 0)->getSnapshotHash());
    }

    // --- fairness ----------------------------------------------------------------------------------------------

    public function testFairnessAndExposureOnlySeeTheTriggeredDemand(): void
    {
        $client = static::createClient();
        $tenDays = array_map(static fn (int $d): array => [\sprintf('2027-01-%02d', $d), \sprintf('2027-01-%02d', $d + 1)], range(4, 13));
        $s = $this->scenario($client, $tenDays, ['blocks' => [], 'solo' => ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'], 'soloFamily' => '', 'excluded' => []]);
        $holders = [];
        foreach ($tenDays as [$day]) {
            $holders[$day] = \in_array($day, ['2027-01-08', '2027-01-09', '2027-01-10'], true) ? 'bob@example.com' : 'admin@example.com';
        }
        $this->forceHolders($client, $s, $holders);

        $this->launch($client, $s);

        // 10 structural possibilities, 3 triggered (Dr C, Friday to Sunday).
        self::assertCount(10, $this->renfortDuties($s));
        $container = static::getContainer();
        $snapshot = $this->snapshotOf($this->currentGeneration($s, 1));
        $matrix = $container->get(EligibilityMatrixBuilder::class)->build($snapshot);
        $context = $container->get(FairnessContextBuilder::class)->build($snapshot, $matrix);
        $total = FairnessDimensionKey::totalDuties();

        self::assertCount(3, $matrix->getDutyUnits());
        self::assertSame(3.0, $context->getRequiredDemand()->get($total));
        self::assertSame(3.0, $context->getEffectiveExposure($this->userId('carol@example.com'))->get($total), 'Exposure over the 3 required units, never the 10 possible ones.');
        self::assertSame(1.5, $context->getGrossTarget($this->userId('carol@example.com'))->get($total));
        self::assertSame(1.0, $context->getRequiredDemand()->get(FairnessDimensionKey::friday()));

        // The independent main line keeps D084 exactly: exposure over all its duties.
        $primarySnapshot = $this->snapshotOf($this->currentGeneration($s, 0));
        $primaryContext = $container->get(FairnessContextBuilder::class)->build($primarySnapshot, $container->get(EligibilityMatrixBuilder::class)->build($primarySnapshot));
        self::assertSame(10.0, $primaryContext->getEffectiveExposure($this->userId('admin@example.com'))->get($total));
        self::assertSame(10.0, $primaryContext->getRequiredDemand()->get($total));
    }

    // --- people ---------------------------------------------------------------------------------------------------

    public function testNobodyIsTheirOwnReinforcementEvenWithoutAnyOverlap(): void
    {
        $client = static::createClient();
        // Main line Saturday 08:00–12:00; its reinforcement Saturday 14:00–20:00 — no overlap at all.
        $s = $this->scenario($client, [['2027-01-09 08:00', '2027-01-09 12:00']], ['blocks' => [], 'solo' => [], 'soloFamily' => '', 'excluded' => ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM']], ['alice@example.com', 'carol@example.com']);
        $container = static::getContainer();
        $renfort = $this->line($s['planningId'], 1);
        $source = $this->dutyEntityOn($s['planningId'], '2027-01-09');
        $type = new \App\Entity\DutyType($renfort->getPlanningTeam(), 'RENFORT', 'Renfort');
        $container->get(EntityManagerInterface::class)->persist($type);
        $container->get(DutyMaterializationService::class)->createStandaloneDuty($renfort->getPlanningPeriod(), $type, new \DateTimeImmutable('2027-01-09 14:00'), new \DateTimeImmutable('2027-01-09 20:00'), DutyDemandType::CONDITIONAL, coverageSource: $source);
        // alice holds the main line's Saturday (Dr B: triggers it); carol, the only other reinforcement, is away.
        $this->forceHolders($client, $s, ['2027-01-09' => 'alice@example.com']);
        $this->declareRange($client, $s['carol'], '2027-01-09', '2027-01-10');

        $job = $this->launch($client, $s);

        self::assertSame('SUCCEEDED', $job['status']);
        self::assertSame(['2027-01-09' => null], $this->renfortCalendar($s), 'Uncovered rather than given to alice.');
        $snapshot = $this->snapshotOf($this->currentGeneration($s, 1));
        $matrix = $container->get(EligibilityMatrixBuilder::class)->build($snapshot);
        $unit = $matrix->getDutyUnits()[0];
        foreach ($snapshot->getMembers() as $member) {
            if ((string) $member->getSourceUserStableId() === $this->userId('alice@example.com')) {
                self::assertSame([ExclusionReason::SELF_COVERAGE], array_map(static fn ($e) => $e->reason, $matrix->get($unit, $member)->exclusions));
            }
        }
    }

    public function testCrossLineRestIsRespectedForTheReinforcement(): void
    {
        $client = static::createClient();
        // Main line: Friday (alice) and Saturday (bob, Dr C: triggers). Renfort: Saturday only; alice and carol.
        $s = $this->scenario($client, [['2027-01-08', '2027-01-09'], ['2027-01-09', '2027-01-10']], ['blocks' => [], 'solo' => ['SAM'], 'soloFamily' => '', 'excluded' => ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'DIM']], ['alice@example.com', 'carol@example.com']);
        // Friday: only alice can hold the main line. Saturday: admin is away and alice, who ends Friday at midnight,
        // cannot rest 12 h — so bob holds it. alice herself stays available on Saturday (never UNAVAILABLE).
        $this->declareRange($client, $s['admin'], '2027-01-08', '2027-01-10');
        $this->declareRange($client, $s['bob'], '2027-01-08', '2027-01-09');
        $this->declareRange($client, $s['carol'], '2027-01-09', '2027-01-10');

        $this->launch($client, $s, ['teamMinRestEnabled' => true, 'teamMinRestHours' => 12]);

        self::assertSame('alice@example.com', $this->currentCalendar($s['planningId'])['0|2027-01-08|ONCALL']);
        self::assertSame('bob@example.com', $this->currentCalendar($s['planningId'])['0|2027-01-09|ONCALL']);

        self::assertSame(['2027-01-09' => null], $this->renfortCalendar($s), 'alice ends her main-line Friday at midnight: 0 h of rest before Saturday.');
        $container = static::getContainer();
        $snapshot = $this->snapshotOf($this->currentGeneration($s, 1));
        $matrix = $container->get(EligibilityMatrixBuilder::class)->build($snapshot);
        foreach ($snapshot->getMembers() as $member) {
            if ((string) $member->getSourceUserStableId() === $this->userId('alice@example.com')) {
                self::assertSame([ExclusionReason::CROSS_LINE_TEAM_MIN_REST], array_map(static fn ($e) => $e->reason, $matrix->get($matrix->getDutyUnits()[0], $member)->exclusions));
            }
        }
    }

    // --- order -------------------------------------------------------------------------------------------------------

    public function testTheSourceIsSolvedFirstAndItsFreshResultIsReadEvenAcrossThreeLines(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        // A third, independent line "Autre" (position 2) becomes Renfort's source (Renfort is at position 1).
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/lines", ['name' => 'Autre'], $s['creator']);
        $autre = $this->line($s['planningId'], 2);
        $this->prepareLine($s['planningId'], self::WEEK, lineIndex: 2);
        $autreTeamId = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'][2]['team']['stableId'];
        $this->addMemberAs($client, $s['creator'], $s['planningId'], $autreTeamId, 'dave@example.com', 'MEMBER');
        $this->api($client, 'PUT', "/api/planning-lines/{$s['renfortLineId']}/demand-policy", [
            'schemaVersion' => 1,
            'mode' => 'CONDITIONAL_ON_SOURCE_ASSIGNMENT',
            'source' => ['lineStableId' => (string) $autre->getStableId()],
            'triggers' => [['userStableId' => $this->userId('dave@example.com'), 'weekdays' => self::ALL_DAYS, 'increment' => 1]],
        ], $s['creator']);
        self::assertResponseIsSuccessful();

        $job = $this->launch($client, $s);

        self::assertSame('SUCCEEDED', $job['status']);
        $primary = $this->currentGeneration($s, 0);
        $renfort = $this->currentGeneration($s, 1);
        $other = $this->currentGeneration($s, 2);
        self::assertLessThan($other->getId(), $primary->getId(), 'Main line first (position 0)...');
        self::assertLessThan($renfort->getId(), $other->getId(), '...then Renfort\'s source, then Renfort.');
        self::assertSame((string) $other->getStableId(), (string) $this->snapshotOf($renfort)->getDemandPolicy()->getSourceGenerationStableId(), 'Renfort read THIS launch\'s result of its source.');
        self::assertSame([$s['primaryLineId'], (string) $autre->getStableId(), $s['renfortLineId']], array_column($job['outcome']['lines'], 'lineStableId'), 'The outcome follows the resolution order.');
        self::assertSame(2, $job['outcome']['lines'][2]['demand']['requiredUnitCount'], 'dave holds "Autre" every day and triggers every reinforcement.');
    }
}
