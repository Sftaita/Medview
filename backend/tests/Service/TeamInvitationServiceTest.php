<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\TeamInvitation;
use App\Entity\TeamMemberRole;
use App\Exception\InactiveAccountForTeamException;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\TeamInvitationRepository;
use App\Repository\UserRepository;
use App\Service\InvitationMailer;
use App\Service\InviteStatus;
use App\Service\PlanningTeamMembershipService;
use App\Service\TeamInvitationService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * "Ajouter un membre" by first name, last name and email (docs/decisions.md
 * D221): the entry date is the day of the addition in the planning's time
 * zone, a deactivated account is never added nor notified, and the email
 * sent to an existing account names the planning and links to it.
 */
final class TeamInvitationServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    /** 23:30 UTC on 1 November = 00:30 on 2 November in Brussels. */
    private const AFTER_MIDNIGHT_IN_BRUSSELS = '2026-11-01 23:30:00 UTC';

    public function testAnExistingAccountJoinsOnTheDayOfTheAdditionInThePlanningsTimeZone(): void
    {
        $em = $this->em();
        $team = $this->createTeam($em);
        $user = $this->createUser($em);

        $outcome = $this->service(self::AFTER_MIDNIGHT_IN_BRUSSELS)->invite($team, $team->getPlanning()->getCreator(), $user->getEmail(), 'X', 'Y');

        self::assertSame(InviteStatus::USER_ADDED, $outcome->status);
        self::assertNotNull($outcome->member);
        self::assertSame(TeamMemberRole::MEMBER, $outcome->member->getRole());
        self::assertSame('2026-11-02', $outcome->member->getMembershipStart()->format('Y-m-d'), 'Never the UTC day before.');
    }

    public function testAnInvitationConsumedAtRegistrationAlsoStartsOnTheLocalDay(): void
    {
        $em = $this->em();
        $team = $this->createTeam($em);
        $user = $this->createUser($em);
        $em->persist(new TeamInvitation($team, $user->getEmail(), 'X', 'Y', $team->getPlanning()->getCreator(), TeamInvitation::hashToken('t'), new \DateTimeImmutable('2026-11-08 00:00:00 UTC')));
        $em->flush();

        $service = $this->service(self::AFTER_MIDNIGHT_IN_BRUSSELS);
        $consumed = $em->wrapInTransaction(static fn () => $service->consumePendingFor($user));

        self::assertCount(1, $consumed);
        self::assertSame('2026-11-02', $consumed[0]->member->getMembershipStart()->format('Y-m-d'));
    }

    public function testADeactivatedAccountIsNeitherAddedNorNotified(): void
    {
        $em = $this->em();
        $team = $this->createTeam($em);
        $user = $this->createUser($em);
        $user->setActive(false);
        $em->flush();

        try {
            $this->service(self::AFTER_MIDNIGHT_IN_BRUSSELS)->invite($team, $team->getPlanning()->getCreator(), strtoupper($user->getEmail()), 'X', 'Y');
            self::fail('A deactivated account must be refused.');
        } catch (InactiveAccountForTeamException) {
        }

        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM planning_team_members WHERE user_id = ?', [$user->getId()]));
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM team_invitations WHERE email = ?', [$user->getEmail()]));
        self::assertEmailCount(0);
    }

    public function testTheEmailToAnExistingAccountNamesThePlanningAndLinksToIt(): void
    {
        $em = $this->em();
        $team = $this->createTeam($em, 'Chirurgie');
        $user = $this->createUser($em);
        $planning = $team->getPlanning();

        $outcome = $this->service(self::AFTER_MIDNIGHT_IN_BRUSSELS)->invite($team, $planning->getCreator(), $user->getEmail(), 'X', 'Y');

        self::assertTrue($outcome->emailSent);
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertEmailAddressContains($email, 'to', $user->getEmail());
        $link = rtrim((string) $_ENV['APP_FRONTEND_URL'], '/').'/plannings/'.$planning->getStableId();
        foreach ([(string) $email->getHtmlBody(), (string) $email->getTextBody()] as $body) {
            self::assertStringContainsString($planning->getName(), $body);
            self::assertStringContainsString('Chirurgie', $body);
            self::assertStringContainsString($link, $body);
        }
    }

    private function em(): EntityManagerInterface
    {
        self::bootKernel();

        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function service(string $now): TeamInvitationService
    {
        $container = self::getContainer();

        return new TeamInvitationService(
            $container->get(TeamInvitationRepository::class),
            $container->get(UserRepository::class),
            $container->get(PlanningTeamMemberRepository::class),
            $container->get(PlanningTeamMembershipService::class),
            $container->get(InvitationMailer::class),
            $container->get(EntityManagerInterface::class),
            new MockClock($now),
            new NullLogger(),
            168,
        );
    }
}
