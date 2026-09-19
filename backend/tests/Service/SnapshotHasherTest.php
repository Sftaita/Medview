<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PlanningGeneration;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningSnapshotAvailabilityPeriod;
use App\Entity\PlanningSnapshotMember;
use App\Entity\RestPolicyOptions;
use App\Entity\TeamMemberRole;
use App\Entity\UserAvailabilityType;
use App\Service\SnapshotHasher;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * docs/decisions.md D106, docs/allocation-algorithm.md §14 — canonical
 * snapshotHash determinism. Builds `PlanningSnapshot`/members directly
 * (never through `PlanningSnapshotService`, which needs a real team/
 * membership/RuleSet pipeline this suite does not need) — `SnapshotHasher`
 * only ever reads `Uuid` *values* off `PlanningSnapshotMember`, so a
 * synthetic stableId is exactly as real to it as one that came from an
 * actual `PlanningTeamMember`.
 */
final class SnapshotHasherTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    public function testHashingTheSameSnapshotTwiceIsIdentical(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(SnapshotHasher::class);

        $snapshot = $this->buildSnapshot($em, [$this->syntheticMember('2027-01-01T00:00:00+00:00')]);

        self::assertSame($hasher->hash($snapshot), $hasher->hash($snapshot));
    }

    public function testMemberInsertionOrderNeverAffectsTheHash(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(SnapshotHasher::class);
        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);

        $memberA = ['id' => Uuid::v4(), 'user' => Uuid::v4(), 'availabilityId' => Uuid::v4()];
        $memberB = ['id' => Uuid::v4(), 'user' => Uuid::v4(), 'availabilityId' => Uuid::v4()];

        $snapshotAB = $this->buildSnapshotOn($em, $planningPeriod, [$this->syntheticMemberFrom($memberA), $this->syntheticMemberFrom($memberB)]);
        $snapshotBA = $this->buildSnapshotOn($em, $planningPeriod, [$this->syntheticMemberFrom($memberB), $this->syntheticMemberFrom($memberA)]);

        self::assertSame($hasher->hash($snapshotAB), $hasher->hash($snapshotBA));
    }

    public function testDifferentRealBusinessDataProducesADifferentHash(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(SnapshotHasher::class);

        $snapshot1 = $this->buildSnapshot($em, [$this->syntheticMember('2027-01-01T00:00:00+00:00')]);
        $snapshot2 = $this->buildSnapshot($em, [$this->syntheticMember('2027-06-01T00:00:00+00:00')]);

        self::assertNotSame($hasher->hash($snapshot1), $hasher->hash($snapshot2));
    }

    public function testDifferentSourceTimestampsOnAvailabilityNeverAffectTheHash(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(SnapshotHasher::class);
        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);

        $member = ['id' => Uuid::v4(), 'user' => Uuid::v4(), 'availabilityId' => Uuid::v4()];

        $snapshot1 = $this->buildSnapshotOn($em, $planningPeriod, [$this->syntheticMemberFrom($member)], sourceTimestamp: '2020-01-01T00:00:00+00:00');
        $snapshot2 = $this->buildSnapshotOn($em, $planningPeriod, [$this->syntheticMemberFrom($member)], sourceTimestamp: '2025-06-15T00:00:00+00:00');

        self::assertSame($hasher->hash($snapshot1), $hasher->hash($snapshot2), 'sourceCreatedAt/sourceUpdatedAt are technical audit timestamps, never business data — excluded from the hash');
    }

    public function testDifferentRestPolicyProducesADifferentHash(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(SnapshotHasher::class);
        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);
        $member = $this->syntheticMember('2027-01-01T00:00:00+00:00');

        $generation1 = new PlanningGeneration($planningPeriod, restPolicy: RestPolicyOptions::none());
        $em->persist($generation1);
        $snapshot1 = new PlanningSnapshot($generation1);
        $em->persist($snapshot1);
        $this->attachMember($em, $snapshot1, $member);
        $em->flush();

        $generation2 = new PlanningGeneration($planningPeriod, restPolicy: new RestPolicyOptions(true, 11, false, null));
        $em->persist($generation2);
        $snapshot2 = new PlanningSnapshot($generation2);
        $em->persist($snapshot2);
        $this->attachMember($em, $snapshot2, $member);
        $em->flush();

        self::assertNotSame($hasher->hash($snapshot1), $hasher->hash($snapshot2), 'RestPolicyOptions is genuinely consumed by AssignmentConflictAnalyzer — a real difference');
    }

    /**
     * @return array{id: Uuid, user: Uuid, availabilityId: Uuid, activityAt: string}
     */
    private function syntheticMember(string $activityAt): array
    {
        return ['id' => Uuid::v4(), 'user' => Uuid::v4(), 'availabilityId' => Uuid::v4(), 'activityAt' => $activityAt];
    }

    /**
     * @param array{id: Uuid, user: Uuid} $base
     *
     * @return array{id: Uuid, user: Uuid, availabilityId: Uuid, activityAt: string}
     */
    private function syntheticMemberFrom(array $base): array
    {
        return [...$base, 'availabilityId' => $base['availabilityId'] ?? Uuid::v4(), 'activityAt' => '2027-01-01T00:00:00+00:00'];
    }

    /**
     * @param list<array{id: Uuid, user: Uuid, activityAt: string}> $members
     */
    private function buildSnapshot(EntityManagerInterface $em, array $members): PlanningSnapshot
    {
        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team);

        return $this->buildSnapshotOn($em, $planningPeriod, $members);
    }

    /**
     * @param list<array{id: Uuid, user: Uuid, activityAt: string}> $members
     */
    private function buildSnapshotOn(EntityManagerInterface $em, PlanningPeriod $planningPeriod, array $members, string $sourceTimestamp = '2020-01-01T00:00:00+00:00'): PlanningSnapshot
    {
        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $snapshot = new PlanningSnapshot($generation);
        $em->persist($snapshot);

        foreach ($members as $member) {
            $this->attachMember($em, $snapshot, $member, $sourceTimestamp);
        }

        $em->flush();

        return $snapshot;
    }

    /**
     * @param array{id: Uuid, user: Uuid, availabilityId?: Uuid, activityAt?: string} $member
     */
    private function attachMember(EntityManagerInterface $em, PlanningSnapshot $snapshot, array $member, string $sourceTimestamp = '2020-01-01T00:00:00+00:00'): void
    {
        $snapshotMember = new PlanningSnapshotMember(
            $snapshot,
            $member['id'],
            $member['user'],
            new \DateTimeImmutable('2027-01-01'),
            null,
            TeamMemberRole::MEMBER,
            true,
        );
        $em->persist($snapshotMember);

        $startsAt = new \DateTimeImmutable($member['activityAt'] ?? '2027-02-01T08:00:00+00:00');
        $em->persist(new PlanningSnapshotAvailabilityPeriod(
            $snapshotMember,
            $member['availabilityId'] ?? Uuid::v4(),
            UserAvailabilityType::UNAVAILABLE,
            $startsAt,
            $startsAt->modify('+8 hours'),
            new \DateTimeImmutable($sourceTimestamp),
            new \DateTimeImmutable($sourceTimestamp),
        ));
    }
}
