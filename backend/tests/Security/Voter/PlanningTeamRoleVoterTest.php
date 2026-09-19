<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\TeamMemberRole;
use App\Repository\PlanningTeamMemberRepository;
use App\Security\Voter\PlanningTeamRoleVoter;
use App\Service\PlanningTeamMembershipService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class PlanningTeamRoleVoterTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    private function dt(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }

    public function testAnyOpenMemberCanViewTheTeam(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $voter = new PlanningTeamRoleVoter(self::getContainer()->get(PlanningTeamMemberRepository::class));

        $team = $this->createTeam($em);
        $member = $this->createUser($em);
        $membershipService->addMember($team, $member, TeamMemberRole::MEMBER, $this->dt('2026-01-01'));
        $outsider = $this->createUser($em);

        self::assertSame(Voter::ACCESS_GRANTED, $voter->vote($this->tokenFor($member), $team, [PlanningTeamRoleVoter::VIEW_TEAM]));
        self::assertSame(Voter::ACCESS_DENIED, $voter->vote($this->tokenFor($outsider), $team, [PlanningTeamRoleVoter::VIEW_TEAM]));
    }

    public function testOnlyOwnerOrAdminCanManageNonParticipation(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $voter = new PlanningTeamRoleVoter(self::getContainer()->get(PlanningTeamMemberRepository::class));

        $team = $this->createTeam($em);
        $owner = $this->createUser($em);
        $admin = $this->createUser($em);
        $plainMember = $this->createUser($em);
        $membershipService->addMember($team, $owner, TeamMemberRole::OWNER, $this->dt('2026-01-01'));
        $membershipService->addMember($team, $admin, TeamMemberRole::ADMIN, $this->dt('2026-01-01'));
        $targetMember = $membershipService->addMember($team, $plainMember, TeamMemberRole::MEMBER, $this->dt('2026-01-01'));

        self::assertSame(Voter::ACCESS_GRANTED, $voter->vote($this->tokenFor($owner), $targetMember, [PlanningTeamRoleVoter::MANAGE_NON_PARTICIPATION]));
        self::assertSame(Voter::ACCESS_GRANTED, $voter->vote($this->tokenFor($admin), $targetMember, [PlanningTeamRoleVoter::MANAGE_NON_PARTICIPATION]));
        self::assertSame(Voter::ACCESS_DENIED, $voter->vote($this->tokenFor($plainMember), $targetMember, [PlanningTeamRoleVoter::MANAGE_NON_PARTICIPATION]));
    }

    public function testMemberCanViewTheirOwnNonParticipationButNotSomeoneElses(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $voter = new PlanningTeamRoleVoter(self::getContainer()->get(PlanningTeamMemberRepository::class));

        $team = $this->createTeam($em);
        $memberOneUser = $this->createUser($em);
        $memberTwoUser = $this->createUser($em);
        $memberOne = $membershipService->addMember($team, $memberOneUser, TeamMemberRole::MEMBER, $this->dt('2026-01-01'));
        $membershipService->addMember($team, $memberTwoUser, TeamMemberRole::MEMBER, $this->dt('2026-01-01'));

        self::assertSame(Voter::ACCESS_GRANTED, $voter->vote($this->tokenFor($memberOneUser), $memberOne, [PlanningTeamRoleVoter::VIEW_NON_PARTICIPATION]));
        self::assertSame(Voter::ACCESS_DENIED, $voter->vote($this->tokenFor($memberTwoUser), $memberOne, [PlanningTeamRoleVoter::VIEW_NON_PARTICIPATION]));
    }

    private function tokenFor(\App\Entity\User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'api', $user->getRoles());
    }
}
