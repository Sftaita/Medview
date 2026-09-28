<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Planning;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use App\Repository\PlanningTeamMemberRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningTeamMembershipService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * PlanningVoter once a User may hold several open memberships in one
 * Planning (docs/decisions.md D160, relaxing D080): the day-to-day
 * management rights come from *any* OWNER/ADMIN membership, and the
 * decision never depends on which membership row a lookup returns first.
 */
final class PlanningVoterTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    private const MANAGEMENT_ATTRIBUTES = [
        PlanningVoter::MANAGE_AVAILABILITY,
        PlanningVoter::GENERATE,
        PlanningVoter::MANAGE_CALENDAR,
        PlanningVoter::PUBLISH,
        PlanningVoter::MANAGE_LINE_STRUCTURE,
        PlanningVoter::MANAGE_RULE_SET,
    ];

    /**
     * @return iterable<string, array{0: bool}>
     */
    public static function adminMembershipOrder(): iterable
    {
        yield 'ADMIN membership created first' => [true];
        yield 'ADMIN membership created second' => [false];
    }

    #[DataProvider('adminMembershipOrder')]
    public function testAnAdminRoleInAnyTeamGrantsManagementWhateverTheMembershipOrder(bool $adminFirst): void
    {
        [$voter, $planning, $teamA, $teamB, $membershipService, $em] = $this->setUpPlanningWithTwoTeams();
        $user = $this->createUser($em);

        [$firstTeam, $firstRole, $secondTeam, $secondRole] = $adminFirst
            ? [$teamA, TeamMemberRole::ADMIN, $teamB, TeamMemberRole::MEMBER]
            : [$teamA, TeamMemberRole::MEMBER, $teamB, TeamMemberRole::ADMIN];
        $membershipService->addMember($firstTeam, $user, $firstRole, $this->date('2027-01-01'));
        $membershipService->addMember($secondTeam, $user, $secondRole, $this->date('2027-01-01'));

        foreach (self::MANAGEMENT_ATTRIBUTES as $attribute) {
            self::assertSame(Voter::ACCESS_GRANTED, $voter->vote($this->tokenFor($user), $planning, [$attribute]), $attribute);
        }
        self::assertSame(Voter::ACCESS_GRANTED, $voter->vote($this->tokenFor($user), $planning, [PlanningVoter::VIEW]));
        // Structure stays the creator's alone, however many managing roles a member holds (D071).
        self::assertSame(Voter::ACCESS_DENIED, $voter->vote($this->tokenFor($user), $planning, [PlanningVoter::MANAGE]));
    }

    public function testTwoPlainMembershipsGrantViewButNoManagement(): void
    {
        [$voter, $planning, $teamA, $teamB, $membershipService, $em] = $this->setUpPlanningWithTwoTeams();
        $user = $this->createUser($em);
        $membershipService->addMember($teamA, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));
        $membershipService->addMember($teamB, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));

        self::assertSame(Voter::ACCESS_GRANTED, $voter->vote($this->tokenFor($user), $planning, [PlanningVoter::VIEW]));
        foreach (self::MANAGEMENT_ATTRIBUTES as $attribute) {
            self::assertSame(Voter::ACCESS_DENIED, $voter->vote($this->tokenFor($user), $planning, [$attribute]), $attribute);
        }
    }

    public function testAnEndedAdminMembershipNoLongerGrantsManagementWhileTheOpenMemberOneStillGrantsView(): void
    {
        [$voter, $planning, $teamA, $teamB, $membershipService, $em] = $this->setUpPlanningWithTwoTeams();
        $user = $this->createUser($em);
        $admin = $membershipService->addMember($teamA, $user, TeamMemberRole::ADMIN, $this->date('2027-01-01'));
        $membershipService->addMember($teamB, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));

        $membershipService->endMembership($admin, $this->date('2027-03-01'));

        self::assertSame(Voter::ACCESS_DENIED, $voter->vote($this->tokenFor($user), $planning, [PlanningVoter::MANAGE_CALENDAR]));
        self::assertSame(Voter::ACCESS_GRANTED, $voter->vote($this->tokenFor($user), $planning, [PlanningVoter::VIEW]));
    }

    public function testAnOutsiderGetsNothing(): void
    {
        [$voter, $planning, , , , $em] = $this->setUpPlanningWithTwoTeams();
        $outsider = $this->createUser($em);

        self::assertSame(Voter::ACCESS_DENIED, $voter->vote($this->tokenFor($outsider), $planning, [PlanningVoter::VIEW]));
        self::assertSame(Voter::ACCESS_DENIED, $voter->vote($this->tokenFor($outsider), $planning, [PlanningVoter::MANAGE_CALENDAR]));
    }

    /**
     * @return array{0: PlanningVoter, 1: Planning, 2: \App\Entity\PlanningTeam, 3: \App\Entity\PlanningTeam, 4: PlanningTeamMembershipService, 5: EntityManagerInterface}
     */
    private function setUpPlanningWithTwoTeams(): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $voter = new PlanningVoter(self::getContainer()->get(PlanningTeamMemberRepository::class));
        $planning = $this->createStandalonePlanning($em);

        return [
            $voter,
            $planning,
            $this->createTeam($em, 'Principale', $planning),
            $this->createTeam($em, 'Renfort', $planning),
            self::getContainer()->get(PlanningTeamMembershipService::class),
            $em,
        ];
    }

    private function tokenFor(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'api', $user->getRoles());
    }
}
