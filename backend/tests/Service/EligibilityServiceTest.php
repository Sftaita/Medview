<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Eligibility\ConstraintTier;
use App\Eligibility\DutyGroupUnit;
use App\Eligibility\ExclusionReason;
use App\Eligibility\SingleDutyUnit;
use App\Entity\Duty;
use App\Entity\DutyType;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningSnapshotAvailabilityPeriod;
use App\Entity\PlanningSnapshotMember;
use App\Entity\PlanningSnapshotNonParticipationPeriod;
use App\Entity\PlanningTeam;
use App\Entity\TeamMemberRole;
use App\Entity\UserAvailabilityType;
use App\Service\DutyMaterializationService;
use App\Service\EligibilityService;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Unit-level tests for EligibilityService, built directly against
 * PlanningSnapshot/PlanningSnapshotMember (bypassing PlanningSnapshotService)
 * since EligibilityService only ever reads snapshot entities — see
 * PlanningSnapshotServiceTest / EligibilityMatrixBuilderTest for the
 * round-trip tests that also exercise the snapshot pipeline and multi-team
 * isolation.
 */
final class EligibilityServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;

    /**
     * Duty instants are resolved in the Team's timezone (Europe/Brussels,
     * the createTeam() default — see DutyMaterializationService::resolveInstant()).
     * Snapshot availability/non-participation periods are constructed
     * directly here (bypassing that resolution), so their instants must be
     * anchored to the same zone — otherwise a "touching" boundary test
     * would be comparing instants offset by the PHP default timezone
     * instead of the real UTC+1/+2 Brussels offset, silently breaking the
     * exact-instant overlap check under test.
     */
    private function localInstant(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Brussels'));
    }

    private function snapshotMember(
        EntityManagerInterface $em,
        PlanningSnapshot $snapshot,
        string $membershipStart = '2027-01-01',
        ?string $membershipEnd = null,
        bool $active = true,
        TeamMemberRole $role = TeamMemberRole::MEMBER,
    ): PlanningSnapshotMember {
        $member = new PlanningSnapshotMember(
            $snapshot,
            Uuid::v7(),
            Uuid::v7(),
            $this->date($membershipStart),
            null === $membershipEnd ? null : $this->date($membershipEnd),
            $role,
            $active,
        );
        $em->persist($member);
        $em->flush();

        return $member;
    }

    private function newSnapshot(EntityManagerInterface $em, PlanningPeriod $planningPeriod): PlanningSnapshot
    {
        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $snapshot = new PlanningSnapshot($generation);
        $em->persist($snapshot);
        $em->flush();

        return $snapshot;
    }

    private function addSnapshotAvailability(
        EntityManagerInterface $em,
        PlanningSnapshotMember $member,
        UserAvailabilityType $type,
        string $startsAt,
        string $endsAt,
    ): PlanningSnapshotAvailabilityPeriod {
        $period = new PlanningSnapshotAvailabilityPeriod(
            $member,
            Uuid::v7(),
            $type,
            $this->localInstant($startsAt),
            $this->localInstant($endsAt),
            $this->date('2027-01-01'),
            $this->date('2027-01-01'),
        );
        $em->persist($period);
        $em->flush();

        return $period;
    }

    private function addSnapshotNonParticipation(
        EntityManagerInterface $em,
        PlanningSnapshotMember $member,
        string $startsAt,
        string $endsAt,
    ): PlanningSnapshotNonParticipationPeriod {
        $period = new PlanningSnapshotNonParticipationPeriod(
            $member,
            Uuid::v7(),
            $this->localInstant($startsAt),
            $this->localInstant($endsAt),
            $this->date('2027-01-01'),
            $this->date('2027-01-01'),
        );
        $em->persist($period);
        $em->flush();

        return $period;
    }

    /**
     * @return array{0: EntityManagerInterface, 1: EligibilityService, 2: PlanningTeam, 3: PlanningPeriod, 4: DutyType}
     */
    private function boot(): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $eligibilityService = self::getContainer()->get(EligibilityService::class);
        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $dutyType = $this->createDutyType($em, $team);

        return [$em, $eligibilityService, $team, $planningPeriod, $dutyType];
    }

    public function testActiveMemberValidMembershipNoAbsenceIsEligible(): void
    {
        [$em, $service, , $planningPeriod, $dutyType] = $this->boot();
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);

        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $snapshot = $this->newSnapshot($em, $planningPeriod);
        $member = $this->snapshotMember($em, $snapshot, '2027-01-01', null);

        $result = $service->evaluate($snapshot, new SingleDutyUnit($duty), $member);

        self::assertTrue($result->eligible);
        self::assertSame([], $result->exclusions);
        self::assertTrue($result->structuralOpportunity);
        self::assertFalse($result->preferred);
    }

    public function testMembershipStartingAfterDutyIsOutOfRange(): void
    {
        [$em, $service, , $planningPeriod, $dutyType] = $this->boot();
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);

        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $snapshot = $this->newSnapshot($em, $planningPeriod);
        $member = $this->snapshotMember($em, $snapshot, '2027-03-01', null);

        $result = $service->evaluate($snapshot, new SingleDutyUnit($duty), $member);

        self::assertFalse($result->eligible);
        self::assertCount(1, $result->exclusions);
        self::assertSame(ExclusionReason::MEMBERSHIP_OUT_OF_RANGE, $result->exclusions[0]->reason);
    }

    public function testMembershipEndingBeforeDutyIsOutOfRange(): void
    {
        [$em, $service, , $planningPeriod, $dutyType] = $this->boot();
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);

        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $snapshot = $this->newSnapshot($em, $planningPeriod);
        $member = $this->snapshotMember($em, $snapshot, '2027-01-01', '2027-01-15');

        $result = $service->evaluate($snapshot, new SingleDutyUnit($duty), $member);

        self::assertFalse($result->eligible);
        self::assertSame(ExclusionReason::MEMBERSHIP_OUT_OF_RANGE, $result->exclusions[0]->reason);
    }

    public function testUnavailableOverlappingTheDutyExcludes(): void
    {
        [$em, $service, , $planningPeriod, $dutyType] = $this->boot();
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);

        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $snapshot = $this->newSnapshot($em, $planningPeriod);
        $member = $this->snapshotMember($em, $snapshot);
        $this->addSnapshotAvailability($em, $member, UserAvailabilityType::UNAVAILABLE, '2027-02-01 08:00', '2027-02-01 20:00');

        $result = $service->evaluate($snapshot, new SingleDutyUnit($duty), $member);

        self::assertFalse($result->eligible);
        self::assertSame(ExclusionReason::UNAVAILABLE, $result->exclusions[0]->reason);
    }

    public function testUnavailableJustBeforeWithoutOverlapIsEligible(): void
    {
        [$em, $service, , $planningPeriod, $dutyType] = $this->boot();
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);

        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $snapshot = $this->newSnapshot($em, $planningPeriod);
        $member = $this->snapshotMember($em, $snapshot);
        // Ends exactly when the duty starts — touching, not overlapping.
        $this->addSnapshotAvailability($em, $member, UserAvailabilityType::UNAVAILABLE, '2027-01-31 20:00', '2027-02-01 08:00');

        $result = $service->evaluate($snapshot, new SingleDutyUnit($duty), $member);

        self::assertTrue($result->eligible, 'a touching-but-not-overlapping UNAVAILABLE period must not exclude');
    }

    public function testPreferDutyOverlappingIsStillEligible(): void
    {
        [$em, $service, , $planningPeriod, $dutyType] = $this->boot();
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);

        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $snapshot = $this->newSnapshot($em, $planningPeriod);
        $member = $this->snapshotMember($em, $snapshot);
        $this->addSnapshotAvailability($em, $member, UserAvailabilityType::PREFER_DUTY, '2027-02-01 08:00', '2027-02-01 20:00');

        $result = $service->evaluate($snapshot, new SingleDutyUnit($duty), $member);

        self::assertTrue($result->eligible);
        self::assertSame([], $result->exclusions);
        self::assertTrue($result->structuralOpportunity);
        self::assertTrue($result->preferred, 'PREFER_DUTY must surface as the auxiliary preferred flag');
    }

    public function testNonParticipationOverlappingExcludes(): void
    {
        [$em, $service, , $planningPeriod, $dutyType] = $this->boot();
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);

        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $snapshot = $this->newSnapshot($em, $planningPeriod);
        $member = $this->snapshotMember($em, $snapshot);
        $this->addSnapshotNonParticipation($em, $member, '2027-02-01 00:00', '2027-02-05 00:00');

        $result = $service->evaluate($snapshot, new SingleDutyUnit($duty), $member);

        self::assertFalse($result->eligible);
        self::assertSame(ExclusionReason::NON_PARTICIPATION, $result->exclusions[0]->reason);
    }

    public function testUserInactiveExcludes(): void
    {
        [$em, $service, , $planningPeriod, $dutyType] = $this->boot();
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);

        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $snapshot = $this->newSnapshot($em, $planningPeriod);
        $member = $this->snapshotMember($em, $snapshot, active: false);

        $result = $service->evaluate($snapshot, new SingleDutyUnit($duty), $member);

        self::assertFalse($result->eligible);
        self::assertSame(ExclusionReason::USER_INACTIVE, $result->exclusions[0]->reason);
        self::assertFalse($result->structuralOpportunity, 'a deactivated account is a structural fact, not a personal declaration');
    }

    // --- structuralOpportunity ---------------------------------------

    public function testUnavailableEligibleFalseStructuralOpportunityTrue(): void
    {
        [$em, $service, , $planningPeriod, $dutyType] = $this->boot();
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);

        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $snapshot = $this->newSnapshot($em, $planningPeriod);
        $member = $this->snapshotMember($em, $snapshot);
        $this->addSnapshotAvailability($em, $member, UserAvailabilityType::UNAVAILABLE, '2027-02-01 08:00', '2027-02-01 20:00');

        $result = $service->evaluate($snapshot, new SingleDutyUnit($duty), $member);

        self::assertFalse($result->eligible);
        self::assertTrue($result->structuralOpportunity, 'a personal unavailability must never reduce structural exposure — resistance to gaming');
    }

    public function testNonParticipationEligibleFalseStructuralOpportunityFalse(): void
    {
        [$em, $service, , $planningPeriod, $dutyType] = $this->boot();
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);

        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $snapshot = $this->newSnapshot($em, $planningPeriod);
        $member = $this->snapshotMember($em, $snapshot);
        $this->addSnapshotNonParticipation($em, $member, '2027-02-01 00:00', '2027-02-05 00:00');

        $result = $service->evaluate($snapshot, new SingleDutyUnit($duty), $member);

        self::assertFalse($result->eligible);
        self::assertFalse($result->structuralOpportunity);
    }

    public function testMembershipOutOfRangeEligibleFalseStructuralOpportunityFalse(): void
    {
        [$em, $service, , $planningPeriod, $dutyType] = $this->boot();
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);

        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $snapshot = $this->newSnapshot($em, $planningPeriod);
        $member = $this->snapshotMember($em, $snapshot, '2027-03-01', null);

        $result = $service->evaluate($snapshot, new SingleDutyUnit($duty), $member);

        self::assertFalse($result->eligible);
        self::assertFalse($result->structuralOpportunity);
    }

    // --- groups --------------------------------------------------------

    public function testGroupAvailableOnBothDutiesIsEligible(): void
    {
        [$em, $service, $team, $planningPeriod] = $this->boot();
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);

        [$group] = $this->createTwoDutyGroup(
            $em,
            $dutyMaterialization,
            $team,
            $planningPeriod,
            '2027-02-06',
            '2027-02-06 08:00',
            '2027-02-06 20:00',
            '2027-02-07 08:00',
            '2027-02-07 20:00',
        );
        $snapshot = $this->newSnapshot($em, $planningPeriod);
        $member = $this->snapshotMember($em, $snapshot);

        $result = $service->evaluate($snapshot, new DutyGroupUnit($group, $group->getDuties()->toArray()), $member);

        self::assertTrue($result->eligible);
    }

    public function testGroupUnavailableOnOneDutyExcludesTheWholeGroup(): void
    {
        [$em, $service, $team, $planningPeriod] = $this->boot();
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);

        [$group, $day0, $day1] = $this->createTwoDutyGroup(
            $em,
            $dutyMaterialization,
            $team,
            $planningPeriod,
            '2027-02-06',
            '2027-02-06 08:00',
            '2027-02-06 20:00',
            '2027-02-07 08:00',
            '2027-02-07 20:00',
        );
        $snapshot = $this->newSnapshot($em, $planningPeriod);
        $member = $this->snapshotMember($em, $snapshot);
        $this->addSnapshotAvailability($em, $member, UserAvailabilityType::UNAVAILABLE, '2027-02-07 08:00', '2027-02-07 20:00');

        $result = $service->evaluate($snapshot, new DutyGroupUnit($group, [$day0, $day1]), $member);

        self::assertFalse($result->eligible, 'a single excluded component must exclude the whole group — never a partial group assignment');
        self::assertSame(ExclusionReason::GROUP_UNAVAILABLE, $result->exclusions[0]->reason);
        self::assertSame(ConstraintTier::HARD, $result->exclusions[0]->tier);
        self::assertSame('UNAVAILABLE', $result->exclusions[0]->context['rootCauses'][0]['reason'], 'the root cause must survive in context for audit');
    }

    public function testGroupMembershipCoveringOnlyOneDutyExcludesTheWholeGroup(): void
    {
        [$em, $service, $team, $planningPeriod] = $this->boot();
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);

        [$group, $day0, $day1] = $this->createTwoDutyGroup(
            $em,
            $dutyMaterialization,
            $team,
            $planningPeriod,
            '2027-02-06',
            '2027-02-06 08:00',
            '2027-02-06 20:00',
            '2027-02-07 08:00',
            '2027-02-07 20:00',
        );
        $snapshot = $this->newSnapshot($em, $planningPeriod);
        // Membership ends right on day1's date, so only day0 is covered.
        $member = $this->snapshotMember($em, $snapshot, '2027-01-01', '2027-02-07');

        $result = $service->evaluate($snapshot, new DutyGroupUnit($group, [$day0, $day1]), $member);

        self::assertFalse($result->eligible);
        self::assertSame(ExclusionReason::MEMBERSHIP_OUT_OF_RANGE, $result->exclusions[0]->reason, 'membership-caused exclusions are reported directly, not wrapped as GROUP_UNAVAILABLE');
    }
}
