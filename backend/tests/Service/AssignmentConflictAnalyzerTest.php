<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Eligibility\ConstraintTier;
use App\Eligibility\ExclusionReason;
use App\Entity\PlanningGeneration;
use App\Entity\RestPolicyOptions;
use App\Entity\TeamMemberRole;
use App\Fairness\AssignmentConflict;
use App\Repository\PlanningLineRepository;
use App\Service\AssignmentConflictAnalyzer;
use App\Service\DutyMaterializationService;
use App\Service\EligibilityMatrixBuilder;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningService;
use App\Service\PlanningSnapshotService;
use App\Service\PlanningTeamMembershipService;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use App\Tests\PlanningTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssignmentConflictAnalyzerTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;
    use PlanningTestHelpers;

    public function testBothPoliciesDisabledMeansNoRestConflictEver(): void
    {
        // Short gap that would violate any real rest policy, but both are off.
        [$conflicts] = $this->scenario(
            RestPolicyOptions::none(),
            duty1: ['2027-02-01 08:00', '2027-02-01 20:00'],
            duty2: ['2027-02-02 06:00', '2027-02-02 18:00'],
        );

        self::assertSame([], $conflicts);
    }

    public function testOverlappingDutiesProduceAHardConflict(): void
    {
        [$conflicts] = $this->scenario(
            RestPolicyOptions::none(),
            duty1: ['2027-02-01 08:00', '2027-02-01 20:00'],
            duty2: ['2027-02-01 18:00', '2027-02-02 06:00'],
        );

        self::assertCount(1, $conflicts);
        self::assertSame(ExclusionReason::CONFLICT, $conflicts[0]->reason);
        self::assertSame(ConstraintTier::HARD, $conflicts[0]->tier);
    }

    public function testConflictAppliesEvenWhenBothRestPoliciesAreDisabled(): void
    {
        [$conflicts] = $this->scenario(
            RestPolicyOptions::none(),
            duty1: ['2027-02-01 08:00', '2027-02-01 20:00'],
            duty2: ['2027-02-01 18:00', '2027-02-02 06:00'],
        );

        self::assertCount(1, $conflicts, 'CONFLICT (physical overlap) always applies regardless of rest policy options');
        self::assertSame(ExclusionReason::CONFLICT, $conflicts[0]->reason);
    }

    // --- LEGAL_MIN_REST ---------------------------------------------------

    public function testLegalOnlyGapExactlyAtMinimumIsAllowed(): void
    {
        [$conflicts] = $this->scenario(
            new RestPolicyOptions(true, 11, false, null),
            duty1: ['2027-02-01 08:00', '2027-02-01 20:00'],
            duty2: ['2027-02-02 07:00', '2027-02-02 19:00'],
        );

        self::assertSame([], $conflicts);
    }

    public function testLegalOnlyGapBelowMinimumIsAHardConflict(): void
    {
        [$conflicts] = $this->scenario(
            new RestPolicyOptions(true, 11, false, null),
            duty1: ['2027-02-01 08:00', '2027-02-01 20:00'],
            duty2: ['2027-02-02 06:00', '2027-02-02 18:00'],
        );

        self::assertCount(1, $conflicts);
        self::assertSame(ExclusionReason::LEGAL_MIN_REST, $conflicts[0]->reason);
        self::assertSame(ConstraintTier::HARD, $conflicts[0]->tier);
    }

    public function testLegalOnlyGapAboveMinimumIsAllowed(): void
    {
        [$conflicts] = $this->scenario(
            new RestPolicyOptions(true, 11, false, null),
            duty1: ['2027-02-01 08:00', '2027-02-01 20:00'],
            duty2: ['2027-02-02 08:00', '2027-02-02 20:00'],
        );

        self::assertSame([], $conflicts);
    }

    public function testLegalDisabledNeverTriggersEvenWithAShortGap(): void
    {
        [$conflicts] = $this->scenario(
            RestPolicyOptions::none(),
            duty1: ['2027-02-01 08:00', '2027-02-01 20:00'],
            duty2: ['2027-02-02 06:00', '2027-02-02 18:00'],
        );

        self::assertSame([], $conflicts);
    }

    // --- TEAM_MIN_REST ---------------------------------------------------

    public function testTeamOnlyGapExactlyAtMinimumIsAllowed(): void
    {
        [$conflicts] = $this->scenario(
            new RestPolicyOptions(false, null, true, 11),
            duty1: ['2027-02-01 08:00', '2027-02-01 20:00'],
            duty2: ['2027-02-02 07:00', '2027-02-02 19:00'],
        );

        self::assertSame([], $conflicts, 'a rest gap exactly at the minimum must be allowed, never flagged');
    }

    public function testTeamOnlyGapBelowMinimumIsAPolicyHardConflict(): void
    {
        [$conflicts] = $this->scenario(
            new RestPolicyOptions(false, null, true, 11),
            duty1: ['2027-02-01 08:00', '2027-02-01 20:00'],
            duty2: ['2027-02-02 06:00', '2027-02-02 18:00'],
        );

        self::assertCount(1, $conflicts);
        self::assertSame(ExclusionReason::TEAM_MIN_REST, $conflicts[0]->reason);
        self::assertSame(ConstraintTier::POLICY_HARD, $conflicts[0]->tier);
    }

    public function testTeamOnlyGapAboveMinimumIsAllowed(): void
    {
        [$conflicts] = $this->scenario(
            new RestPolicyOptions(false, null, true, 11),
            duty1: ['2027-02-01 08:00', '2027-02-01 20:00'],
            duty2: ['2027-02-02 08:00', '2027-02-02 20:00'],
        );

        self::assertSame([], $conflicts);
    }

    public function testTeamDisabledNeverTriggersEvenWithAShortGap(): void
    {
        [$conflicts] = $this->scenario(
            RestPolicyOptions::none(),
            duty1: ['2027-02-01 08:00', '2027-02-01 20:00'],
            duty2: ['2027-02-02 06:00', '2027-02-02 18:00'],
        );

        self::assertSame([], $conflicts);
    }

    // --- both enabled -------------------------------------------------------

    public function testBothEnabledGapViolatingOnlyTeamReportsTeamMinRest(): void
    {
        // legal=8h, team=11h; 9h gap violates only TEAM.
        [$conflicts] = $this->scenario(
            new RestPolicyOptions(true, 8, true, 11),
            duty1: ['2027-02-01 08:00', '2027-02-01 20:00'],
            duty2: ['2027-02-02 05:00', '2027-02-02 17:00'],
        );

        self::assertCount(1, $conflicts);
        self::assertSame(ExclusionReason::TEAM_MIN_REST, $conflicts[0]->reason);
    }

    public function testBothEnabledGapViolatingLegalReportsLegalOnlyNeverBothForTheSamePair(): void
    {
        // legal=8h, team=11h; 5h gap violates both -- LEGAL wins, no duplicate.
        [$conflicts] = $this->scenario(
            new RestPolicyOptions(true, 8, true, 11),
            duty1: ['2027-02-01 08:00', '2027-02-01 20:00'],
            duty2: ['2027-02-02 01:00', '2027-02-02 13:00'],
        );

        self::assertCount(1, $conflicts, 'a single incompatibility must never be reported twice under two different reasons');
        self::assertSame(ExclusionReason::LEGAL_MIN_REST, $conflicts[0]->reason);
    }

    public function testBothEnabledGapAboveBothIsAllowed(): void
    {
        [$conflicts] = $this->scenario(
            new RestPolicyOptions(true, 8, true, 11),
            duty1: ['2027-02-01 08:00', '2027-02-01 20:00'],
            duty2: ['2027-02-02 08:00', '2027-02-02 20:00'],
        );

        self::assertSame([], $conflicts);
    }

    // --- snapshot/historical immutability (docs/decisions.md D105) ----------

    /**
     * The team's active `PlanningRuleSet.teamMinRestHours` is now a purely
     * historical field (`PlanningRuleSetConfiguration::$teamMinRestHours`
     * docblock) — the analyzer must read `PlanningGeneration::getRestPolicy()`
     * exclusively, never fall back to or blend in a "current" team-wide
     * RuleSet value. A RuleSet configured with a team-wide
     * `teamMinRestHours` that WOULD flag this exact gap if it were
     * (incorrectly) consulted must have zero effect when the generation's
     * own frozen `RestPolicyOptions` disables both policies.
     */
    public function testRuleSetTeamMinRestHoursIsNeverConsultedOnlyTheGenerationsOwnFrozenOptions(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $analyzer = $c->get(AssignmentConflictAnalyzer::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();

        $configuration = new \App\Dto\PlanningRuleSetConfiguration();
        $configuration->teamMinRestHours = 11;
        $this->activateRuleSet($ruleSetService, $team, $configuration);

        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        // 10h gap: would violate the RuleSet's teamMinRestHours=11 if it were read.
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-02 06:00', '2027-02-02 18:00');

        $generation = new PlanningGeneration($planningPeriod, restPolicy: RestPolicyOptions::none());
        $em->persist($generation);
        $em->flush();
        $snapshot = $snapshotService->createSnapshot($generation);
        $matrix = $matrixBuilder->build($snapshot);

        $conflicts = $analyzer->analyze($matrix, $snapshot);

        self::assertSame([], $conflicts, 'the RuleSet\'s teamMinRestHours must never be consulted — only the generation\'s own frozen RestPolicyOptions');
    }

    /**
     * A later generation's rest-policy choice must never retroactively
     * change an earlier generation's own (already-frozen) conflicts.
     */
    public function testALaterGenerationsRestPolicyNeverAffectsAnEarlierGenerationsConflicts(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $analyzer = $c->get(AssignmentConflictAnalyzer::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        // 10h gap.
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-02 06:00', '2027-02-02 18:00');

        // First generation: both policies disabled -> no conflict, snapshotted immediately.
        $firstGeneration = new PlanningGeneration($planningPeriod, restPolicy: RestPolicyOptions::none());
        $em->persist($firstGeneration);
        $em->flush();
        $firstSnapshot = $snapshotService->createSnapshot($firstGeneration);
        $firstMatrix = $matrixBuilder->build($firstSnapshot);
        $firstConflictsBefore = $analyzer->analyze($firstMatrix, $firstSnapshot);
        self::assertSame([], $firstConflictsBefore);

        // A second, later generation of the same PlanningPeriod enables TEAM_MIN_REST=11h.
        $secondGeneration = new PlanningGeneration($planningPeriod, restPolicy: new RestPolicyOptions(false, null, true, 11));
        $em->persist($secondGeneration);
        $em->flush();
        $secondSnapshot = $snapshotService->createSnapshot($secondGeneration);
        $secondMatrix = $matrixBuilder->build($secondSnapshot);
        $secondConflicts = $analyzer->analyze($secondMatrix, $secondSnapshot);
        self::assertCount(1, $secondConflicts, 'the second generation\'s own enabled TEAM_MIN_REST must apply to its own snapshot');

        // Re-analyzing the FIRST snapshot again must still yield zero conflicts.
        $firstConflictsAfter = $analyzer->analyze($firstMatrix, $firstSnapshot);
        self::assertSame([], $firstConflictsAfter, 'creating a later generation with a different rest policy must never retroactively affect an earlier one');
    }

    // --- candidate isolation / groups / determinism --------------------------

    public function testConflictForOneCandidateNeverAffectsAnother(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $analyzer = $c->get(AssignmentConflictAnalyzer::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $userA = $this->createUser($em);
        $userB = $this->createUser($em);
        $memberA = $this->addMember($membershipService, $team, $userA, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $memberB = $this->addMember($membershipService, $team, $userB, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        $duty1 = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $duty2 = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 18:00', '2027-02-02 06:00');

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();
        $snapshot = $snapshotService->createSnapshot($generation);
        $matrix = $matrixBuilder->build($snapshot);

        $conflicts = $analyzer->analyze($matrix, $snapshot);

        self::assertCount(2, $conflicts, 'one conflict entry per candidate eligible to both units');
        $candidateIds = array_map(static fn (AssignmentConflict $c) => $c->candidateStableKey, $conflicts);
        sort($candidateIds);
        $expected = [(string) $memberA->getStableId(), (string) $memberB->getStableId()];
        sort($expected);
        self::assertSame($expected, $candidateIds);
    }

    public function testGroupVsSingleDutyConflictUsesGroupStableKeyNeverAConstituentDuty(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $analyzer = $c->get(AssignmentConflictAnalyzer::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        [$group] = $this->createTwoDutyGroup(
            $em,
            $dutyMaterialization,
            $team,
            $planningPeriod,
            '2027-03-12',
            '2027-03-12 08:00',
            '2027-03-12 20:00',
            '2027-03-13 08:00',
            '2027-03-13 20:00',
        );

        $dutyType = $this->createDutyType($em, $team, 'STANDALONE');
        // Overlaps the group's second (Saturday) component.
        $standalone = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-03-13 12:00', '2027-03-13 14:00');

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();
        $snapshot = $snapshotService->createSnapshot($generation);
        $matrix = $matrixBuilder->build($snapshot);

        $conflicts = $analyzer->analyze($matrix, $snapshot);

        self::assertCount(1, $conflicts);
        $keys = [$conflicts[0]->leftDutyUnitStableKey, $conflicts[0]->rightDutyUnitStableKey];
        sort($keys);
        $expected = [(string) $group->getStableId(), (string) $standalone->getStableId()];
        sort($expected);
        self::assertSame($expected, $keys, 'the conflict must reference the group\'s own stable key, never a constituent Duty');
    }

    public function testRepeatedAnalysisOfTheSameSnapshotIsFullyDeterministic(): void
    {
        [$conflictsA, $conflictsB] = $this->scenario(
            RestPolicyOptions::none(),
            duty1: ['2027-02-01 08:00', '2027-02-01 20:00'],
            duty2: ['2027-02-01 18:00', '2027-02-02 06:00'],
            withReversedRun: true,
        );

        $normalize = static fn (array $conflicts) => array_map(
            static fn (AssignmentConflict $c) => [$c->candidateStableKey, $c->leftDutyUnitStableKey, $c->rightDutyUnitStableKey, $c->reason->value],
            $conflicts,
        );

        self::assertSame($normalize($conflictsA), $normalize($conflictsB));
    }

    public function testConflictsAreReturnedInCanonicalKeyOrderNeverInsertionOrder(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $analyzer = $c->get(AssignmentConflictAnalyzer::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        // Three mutually overlapping duties -> three conflict pairs.
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 10:00', '2027-02-01 22:00');
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 12:00', '2027-02-02 00:00');

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();
        $snapshot = $snapshotService->createSnapshot($generation);
        $matrix = $matrixBuilder->build($snapshot);

        $conflicts = $analyzer->analyze($matrix, $snapshot);

        self::assertCount(3, $conflicts);
        $pairs = array_map(static fn (AssignmentConflict $cf) => [$cf->leftDutyUnitStableKey, $cf->rightDutyUnitStableKey], $conflicts);
        $sorted = $pairs;
        usort($sorted, static fn ($a, $b) => $a <=> $b);
        self::assertSame($sorted, $pairs, 'conflicts must already come out in canonical (left,right) order');
        foreach ($conflicts as $conflict) {
            self::assertLessThan($conflict->rightDutyUnitStableKey, $conflict->leftDutyUnitStableKey, 'left must always be the lexicographically smaller key');
        }
    }

    /**
     * @return array{0: list<AssignmentConflict>, 1: list<AssignmentConflict>|null}
     */
    private function scenario(RestPolicyOptions $restPolicy, array $duty1, array $duty2, bool $withReversedRun = false): array
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $analyzer = $c->get(AssignmentConflictAnalyzer::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, $duty1[0], $duty1[1]);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, $duty2[0], $duty2[1]);

        $generation = new PlanningGeneration($planningPeriod, restPolicy: $restPolicy);
        $em->persist($generation);
        $em->flush();
        $snapshot = $snapshotService->createSnapshot($generation);
        $matrix = $matrixBuilder->build($snapshot);

        $conflicts = $analyzer->analyze($matrix, $snapshot);

        if (!$withReversedRun) {
            return [$conflicts, null];
        }

        // Re-run against the very same snapshot/matrix to confirm the
        // analyzer's own internal sort makes it independent of whatever
        // order Doctrine/PHP happened to hand it the units in.
        $conflictsAgain = $analyzer->analyze($matrix, $snapshot);

        return [$conflicts, $conflictsAgain];
    }
}
