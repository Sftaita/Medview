<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Eligibility\ExclusionReason;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningLine;
use App\Entity\PlanningSnapshot;
use App\Repository\DutyAssignmentRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\PlanningSnapshotRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\UserRepository;
use App\Service\DutyAssignmentService;
use App\Service\EligibilityMatrixBuilder;
use App\Service\SnapshotHasher;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Cross-line constraints on the same PERSON (docs/decisions.md D161), end to
 * end through the real API, the real worker and a real OR-Tools solve.
 *
 * Scenario: the main line (index 0) has admin, alice and bob; a secondary
 * line (index 1) has alice and carol — alice belongs to both lines (D160),
 * with one stint on each.
 */
final class CrossLineConstraintsTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;

    private const PRIMARY = 0;
    private const SECONDARY = 1;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    /**
     * @return array<string, mixed>
     */
    private function twoLineScenario(KernelBrowser $client): array
    {
        $s = $this->pilotScenario($client);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/lines", ['name' => 'Renfort'], $s['creator']);
        self::assertResponseStatusCodeSame(201);
        $s['secondaryTeamId'] = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'][1]['team']['stableId'];

        $s['carol'] = $this->userToken($client, 'carol@example.com');
        foreach (['alice@example.com', 'carol@example.com'] as $email) {
            $this->addMemberAs($client, $s['creator'], $s['planningId'], $s['secondaryTeamId'], $email, 'MEMBER');
        }

        return $s;
    }

    /** Only alice can take the main line's duty that day: admin and bob are away. */
    private function onlyAliceOnThePrimaryLine(KernelBrowser $client, array $s, string $from, string $to): void
    {
        foreach (['admin', 'bob'] as $who) {
            $this->declareRange($client, $s[$who], $from, $to);
        }
    }

    private function line(string $planningStableId, int $index): PlanningLine
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);

        return $container->get(PlanningLineRepository::class)->findByPlanning($planning)[$index];
    }

    private function currentGeneration(string $planningStableId, int $lineIndex): PlanningGeneration
    {
        $generation = static::getContainer()->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($this->line($planningStableId, $lineIndex)->getPlanningPeriod());
        self::assertNotNull($generation);

        return $generation;
    }

    private function snapshotOf(PlanningGeneration $generation): PlanningSnapshot
    {
        $snapshot = static::getContainer()->get(PlanningSnapshotRepository::class)->findOneByGeneration($generation);
        self::assertNotNull($snapshot);

        return $snapshot;
    }

    /**
     * The frozen eligibility of $email for the unit of line $lineIndex on $date, in that line's current generation.
     *
     * @return list<ExclusionReason>
     */
    private function frozenExclusionReasons(string $planningStableId, int $lineIndex, string $date, string $email): array
    {
        $container = static::getContainer();
        $snapshot = $this->snapshotOf($this->currentGeneration($planningStableId, $lineIndex));
        $matrix = $container->get(EligibilityMatrixBuilder::class)->build($snapshot);
        $userStableId = (string) $container->get(UserRepository::class)->findOneByEmail($email)->getStableId();

        foreach ($matrix->getDutyUnits() as $unit) {
            if ($unit->getDuties()[0]->getLocalDate()->format('Y-m-d') !== $date) {
                continue;
            }
            foreach ($snapshot->getMembers() as $member) {
                if ((string) $member->getSourceUserStableId() === $userStableId) {
                    return array_map(static fn ($e) => $e->reason, $matrix->get($unit, $member)->exclusions);
                }
            }
        }

        self::fail("No unit on {$date} / no stint of {$email} in line {$lineIndex}.");
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return list<string> the teamMemberStableIds offered as replacements
     */
    private function candidateIds(KernelBrowser $client, array $s, string $dutyStableId): array
    {
        return array_column($this->candidatesFor($client, $s, $dutyStableId)['candidates'], 'teamMemberStableId');
    }

    // --- generation: frozen commitments ------------------------------------------------------

    public function testTheSecondaryLineNeverGivesThePrimaryHolderASimultaneousDuty(): void
    {
        $client = static::createClient();
        $s = $this->twoLineScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: self::PRIMARY);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: self::SECONDARY);
        $this->onlyAliceOnThePrimaryLine($client, $s, '2027-01-05', '2027-01-06');
        // carol is away too: alice is the ONLY member of the secondary line available that day.
        $this->declareRange($client, $s['carol'], '2027-01-05', '2027-01-06');

        $this->generate($client, $s);

        $calendar = $this->currentCalendar($s['planningId']);
        self::assertSame('alice@example.com', $calendar['0|2027-01-05|ONCALL']);
        self::assertNull($calendar['1|2027-01-05|ONCALL'], 'alice already holds the main line that day: the reinforcement stays uncovered rather than doubled onto her.');
        self::assertSame([ExclusionReason::CROSS_LINE_CONFLICT], $this->frozenExclusionReasons($s['planningId'], self::SECONDARY, '2027-01-05', 'alice@example.com'));

        // The commitment is frozen in the secondary line's snapshot, as values.
        $commitments = $this->snapshotOf($this->currentGeneration($s['planningId'], self::SECONDARY))->getExternalCommitments()->toArray();
        self::assertCount(1, $commitments);
        $commitment = $commitments[0];
        $container = static::getContainer();
        self::assertSame((string) $container->get(UserRepository::class)->findOneByEmail('alice@example.com')->getStableId(), (string) $commitment->getSourceUserStableId());
        self::assertSame($this->dutyOn($s['planningId'], '2027-01-05', self::PRIMARY), (string) $commitment->getSourceDutyStableId());
        self::assertSame((string) $this->line($s['planningId'], self::PRIMARY)->getStableId(), (string) $commitment->getSourceLineStableId());
        self::assertSame((string) $this->currentGeneration($s['planningId'], self::PRIMARY)->getStableId(), (string) $commitment->getSourceGenerationStableId());
    }

    public function testCrossLineRestFollowsTheRestPolicyChosenAtLaunch(): void
    {
        $client = static::createClient();
        $s = $this->twoLineScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: self::PRIMARY);
        $this->prepareLine($s['planningId'], [['2027-01-06', '2027-01-07']], lineIndex: self::SECONDARY);
        $this->onlyAliceOnThePrimaryLine($client, $s, '2027-01-05', '2027-01-06');
        $this->declareRange($client, $s['carol'], '2027-01-06', '2027-01-07');

        // Main line Tuesday, secondary line Wednesday: back to back, 0 h of rest in between.
        $this->generateNow($client, $s['planningId'], $s['creator'], ['teamMinRestEnabled' => true, 'teamMinRestHours' => 12]);
        self::assertNull($this->currentCalendar($s['planningId'])['1|2027-01-06|ONCALL']);
        self::assertSame([ExclusionReason::CROSS_LINE_TEAM_MIN_REST], $this->frozenExclusionReasons($s['planningId'], self::SECONDARY, '2027-01-06', 'alice@example.com'));

        // Without any rest rule, back-to-back duties on two lines are compatible.
        $this->generateNow($client, $s['planningId'], $s['creator']);
        self::assertSame('alice@example.com', $this->currentCalendar($s['planningId'])['1|2027-01-06|ONCALL']);
        self::assertSame([], $this->frozenExclusionReasons($s['planningId'], self::SECONDARY, '2027-01-06', 'alice@example.com'));
    }

    public function testALineIsOnlyConstrainedByTheLinesSolvedBeforeIt(): void
    {
        $client = static::createClient();
        $s = $this->twoLineScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: self::PRIMARY);
        $this->prepareLine($s['planningId'], [['2027-01-06', '2027-01-07']], lineIndex: self::SECONDARY);
        $this->onlyAliceOnThePrimaryLine($client, $s, '2027-01-05', '2027-01-06');
        $this->declareRange($client, $s['carol'], '2027-01-06', '2027-01-07');
        $this->generate($client, $s);
        self::assertSame('alice@example.com', $this->currentCalendar($s['planningId'])['1|2027-01-06|ONCALL'], 'Precondition: alice currently holds the secondary line on Wednesday.');

        $this->generate($client, $s);

        // Main line first (position order): it is about to be followed by a regeneration of the secondary line,
        // so the secondary line's current assignments never constrain it.
        self::assertCount(0, $this->snapshotOf($this->currentGeneration($s['planningId'], self::PRIMARY))->getExternalCommitments());
        // The secondary line, solved second, sees the main line's fresh result.
        self::assertCount(1, $this->snapshotOf($this->currentGeneration($s['planningId'], self::SECONDARY))->getExternalCommitments());
    }

    public function testOnlyTheCommitmentsOfPeopleOfTheLineAreFrozen(): void
    {
        $client = static::createClient();
        $s = $this->twoLineScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-08', '2027-01-09']], lineIndex: self::PRIMARY);
        $this->prepareLine($s['planningId'], [['2027-01-12', '2027-01-13']], lineIndex: self::SECONDARY);
        $this->onlyAliceOnThePrimaryLine($client, $s, '2027-01-05', '2027-01-06');
        // Friday 8 can only go to bob, who does not belong to the secondary line.
        foreach (['admin', 'alice'] as $who) {
            $this->declareRange($client, $s[$who], '2027-01-08', '2027-01-09');
        }

        $this->generate($client, $s);

        $calendar = $this->currentCalendar($s['planningId']);
        self::assertSame('bob@example.com', $calendar['0|2027-01-08|ONCALL']);
        $commitments = $this->snapshotOf($this->currentGeneration($s['planningId'], self::SECONDARY))->getExternalCommitments()->toArray();
        self::assertCount(1, $commitments, "Only alice's main-line duty: bob is not a candidate of the secondary line.");
        self::assertSame($this->dutyOn($s['planningId'], '2027-01-05', self::PRIMARY), (string) $commitments[0]->getSourceDutyStableId());
    }

    public function testAFrozenCommitmentNeverChangesAfterTheOtherLineIsEdited(): void
    {
        $client = static::createClient();
        $s = $this->twoLineScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: self::PRIMARY);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: self::SECONDARY);
        $this->onlyAliceOnThePrimaryLine($client, $s, '2027-01-05', '2027-01-06');
        $this->generate($client, $s);
        $secondary = $this->currentGeneration($s['planningId'], self::SECONDARY);

        // alice leaves the main line's Tuesday afterwards.
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-05', self::PRIMARY));
        self::assertResponseIsSuccessful();

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $secondary = static::getContainer()->get(PlanningGenerationRepository::class)->find($secondary->getId());
        $snapshot = $this->snapshotOf($secondary);
        self::assertCount(1, $snapshot->getExternalCommitments(), 'The old generation keeps explaining its own solve.');
        self::assertSame([ExclusionReason::CROSS_LINE_CONFLICT], $this->frozenExclusionReasons($s['planningId'], self::SECONDARY, '2027-01-05', 'alice@example.com'));
        self::assertSame($secondary->getSnapshotHash(), static::getContainer()->get(SnapshotHasher::class)->hash($snapshot), 'Same hash: the frozen input did not move.');
    }

    // --- live: manual reassignment, both directions --------------------------------------------

    public function testReassignmentRefusesACrossLineConflictInBothDirections(): void
    {
        $client = static::createClient();
        $s = $this->twoLineScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-07', '2027-01-08']], lineIndex: self::PRIMARY);
        $this->prepareLine($s['planningId'], [['2027-01-07', '2027-01-08']], lineIndex: self::SECONDARY);
        $this->generate($client, $s);
        $primaryDuty = $this->dutyOn($s['planningId'], '2027-01-07', self::PRIMARY);
        $secondaryDuty = $this->dutyOn($s['planningId'], '2027-01-07', self::SECONDARY);

        // bob on the main line, alice on the secondary line — built through the API.
        $this->unassignDuty($client, $s, $primaryDuty);
        $this->unassignDuty($client, $s, $secondaryDuty);
        $this->reassignTo($client, $s, $primaryDuty, 'bob@example.com', lineIndex: self::PRIMARY);
        self::assertResponseIsSuccessful();
        $this->reassignTo($client, $s, $secondaryDuty, 'alice@example.com', lineIndex: self::SECONDARY);
        self::assertResponseIsSuccessful();

        // Main line edited: alice (busy on the secondary line) is never offered, and refused if forced.
        self::assertNotContains($this->memberStableIdIn($s['planningId'], 'alice@example.com', self::PRIMARY), $this->candidateIds($client, $s, $primaryDuty));
        $refused = $this->reassignTo($client, $s, $primaryDuty, 'alice@example.com', lineIndex: self::PRIMARY);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('invalid_candidate', $refused['error']);
        self::assertStringContainsString('CROSS_LINE_CONFLICT', $refused['message']);

        // Swap: alice on the main line, carol on the secondary line; now the secondary line is edited.
        $this->reassignTo($client, $s, $secondaryDuty, 'carol@example.com', lineIndex: self::SECONDARY);
        self::assertResponseIsSuccessful();
        $this->reassignTo($client, $s, $primaryDuty, 'alice@example.com', lineIndex: self::PRIMARY);
        self::assertResponseIsSuccessful();
        self::assertNotContains($this->memberStableIdIn($s['planningId'], 'alice@example.com', self::SECONDARY), $this->candidateIds($client, $s, $secondaryDuty));
        $refused = $this->reassignTo($client, $s, $secondaryDuty, 'alice@example.com', lineIndex: self::SECONDARY);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('CROSS_LINE_CONFLICT', $refused['message']);

        $calendar = $this->currentCalendar($s['planningId']);
        self::assertSame('alice@example.com', $calendar['0|2027-01-07|ONCALL']);
        self::assertSame('carol@example.com', $calendar['1|2027-01-07|ONCALL'], 'A refused save changes nothing.');
    }

    public function testReassignmentRefusesACrossLineRestViolationInBothDirections(): void
    {
        $client = static::createClient();
        $s = $this->twoLineScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: self::PRIMARY);
        $this->prepareLine($s['planningId'], [['2027-01-06', '2027-01-07']], lineIndex: self::SECONDARY);
        $this->generateNow($client, $s['planningId'], $s['creator'], ['teamMinRestEnabled' => true, 'teamMinRestHours' => 12]);
        $primaryDuty = $this->dutyOn($s['planningId'], '2027-01-05', self::PRIMARY);
        $secondaryDuty = $this->dutyOn($s['planningId'], '2027-01-06', self::SECONDARY);
        $this->unassignDuty($client, $s, $primaryDuty);
        $this->unassignDuty($client, $s, $secondaryDuty);

        // alice on the main line Tuesday: the secondary line's Wednesday (0 h later) is refused to her.
        $this->reassignTo($client, $s, $primaryDuty, 'alice@example.com', lineIndex: self::PRIMARY);
        self::assertResponseIsSuccessful();
        $refused = $this->reassignTo($client, $s, $secondaryDuty, 'alice@example.com', lineIndex: self::SECONDARY);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('CROSS_LINE_TEAM_MIN_REST', $refused['message']);

        // The other way round: alice on the secondary Wednesday first, then the main line's Tuesday is refused.
        $this->unassignDuty($client, $s, $primaryDuty);
        $this->reassignTo($client, $s, $secondaryDuty, 'alice@example.com', lineIndex: self::SECONDARY);
        self::assertResponseIsSuccessful();
        $refused = $this->reassignTo($client, $s, $primaryDuty, 'alice@example.com', lineIndex: self::PRIMARY);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('CROSS_LINE_TEAM_MIN_REST', $refused['message']);
    }

    // --- live: completion ------------------------------------------------------------------------

    public function testCompletionNeverGivesOnePersonTwoSimultaneousDutiesOnTwoLines(): void
    {
        $client = static::createClient();
        $s = $this->twoLineScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: self::PRIMARY);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: self::SECONDARY);
        $this->onlyAliceOnThePrimaryLine($client, $s, '2027-01-05', '2027-01-06');
        $this->generate($client, $s);
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-05', self::PRIMARY));
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-05', self::SECONDARY));
        // carol becomes unavailable meanwhile: for the secondary hole, only alice is left — but the main line's
        // hole, completed first, is about to go to alice.
        $this->declareRange($client, $s['carol'], '2027-01-05', '2027-01-06');

        $outcome = $this->completePlanning($client, $s);

        self::assertSame('SUCCEEDED', $outcome['job']['status'], 'Never calendar_changed: the planned main-line fill is taken into account before writing.');
        $calendar = $this->currentCalendar($s['planningId']);
        self::assertSame('alice@example.com', $calendar['0|2027-01-05|ONCALL']);
        self::assertNull($calendar['1|2027-01-05|ONCALL'], 'alice is never doubled onto the secondary line the same day.');
    }

    public function testCompletionFillsBothLinesWithDifferentPeople(): void
    {
        $client = static::createClient();
        $s = $this->twoLineScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: self::PRIMARY);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: self::SECONDARY);
        $this->onlyAliceOnThePrimaryLine($client, $s, '2027-01-05', '2027-01-06');
        $this->generate($client, $s);
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-05', self::PRIMARY));
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-05', self::SECONDARY));

        $outcome = $this->completePlanning($client, $s);

        self::assertSame('SUCCEEDED', $outcome['job']['status']);
        $calendar = $this->currentCalendar($s['planningId']);
        self::assertSame('alice@example.com', $calendar['0|2027-01-05|ONCALL']);
        self::assertSame('carol@example.com', $calendar['1|2027-01-05|ONCALL']);
    }

    // --- publication preflight --------------------------------------------------------------------

    public function testThePublicationPreflightReportsACrossLineConflictBuiltBehindTheApplicationsBack(): void
    {
        $client = static::createClient();
        $s = $this->twoLineScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: self::PRIMARY);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: self::SECONDARY);
        $this->onlyAliceOnThePrimaryLine($client, $s, '2027-01-05', '2027-01-06');
        $this->generate($client, $s);
        self::assertSame('carol@example.com', $this->currentCalendar($s['planningId'])['1|2027-01-05|ONCALL']);

        // An incoherent state no application path produces: alice put on the secondary line too, directly.
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $generation = $this->currentGeneration($s['planningId'], self::SECONDARY);
        $duty = $this->dutyEntityOn($s['planningId'], '2027-01-05', self::SECONDARY);
        $container->get(DutyAssignmentRepository::class)->findCurrentByGenerationAndDuty($generation, $duty)->markSuperseded();
        $em->flush();
        $aliceOnSecondary = $container->get(PlanningTeamMemberRepository::class)->findOneByStableId($this->memberStableIdIn($s['planningId'], 'alice@example.com', self::SECONDARY));
        $container->get(DutyAssignmentService::class)->createAuto($generation, $this->snapshotOf($generation), $duty, $aliceOnSecondary);
        $em->flush();

        $preflight = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/publication-preflight", token: $s['creator']);

        self::assertResponseIsSuccessful();
        self::assertFalse($preflight['publishable']);
        $reasons = array_column($preflight['conflicts'], 'reason');
        self::assertContains('déjà de garde au même moment sur une autre ligne', $reasons);
    }
}
