<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Planning;
use App\Entity\PlanningTeam;
use App\Entity\PlanningTeamMember;
use App\Entity\TeamInvitation;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use App\Service\ConsumedInvitation;
use App\Service\InvitationMailer;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Rendering of the three transactional emails against the real Twig
 * templates (templates/email/, copied from docs/Design/emails_medvue).
 */
final class InvitationMailerTest extends KernelTestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private function mailer(): InvitationMailer
    {
        self::bootKernel();

        return new InvitationMailer(
            self::getContainer()->get('mailer.mailer'),
            new NullLogger(),
            'https://medvue.example/',
            'MedVue <no-reply@medvue.example>',
            'help@medvue.example',
        );
    }

    private function team(string $teamName = 'Ligne principale', string $planningName = 'Gardes 2027'): PlanningTeam
    {
        $creator = new User('creator@example.com', 'Alice', 'Martin', 'h');
        $planning = new Planning($planningName, $creator, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'), 'Europe/Brussels');

        return new PlanningTeam($planning, $teamName);
    }

    private function invitation(PlanningTeam $team, string $first = 'Marie', string $email = 'marie@example.com'): TeamInvitation
    {
        $inviter = new User('inviter@example.com', 'Alice', 'Martin', 'h');

        return new TeamInvitation($team, $email, $first, 'Dupont', $inviter, TeamInvitation::hashToken(self::TOKEN), new \DateTimeImmutable('+7 days'));
    }

    public function testInvitationEmail(): void
    {
        $mailer = $this->mailer();
        $invitation = $this->invitation($this->team('Cardio'));

        self::assertTrue($mailer->sendInvitation($invitation, self::TOKEN));

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertSame('Vous êtes invité à rejoindre une équipe sur MedVue', $email->getSubject());
        self::assertEmailAddressContains($email, 'to', 'marie@example.com');
        self::assertEmailAddressContains($email, 'from', 'no-reply@medvue.example');

        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();
        $link = 'https://medvue.example/invitations/'.self::TOKEN;
        self::assertStringContainsString('href="'.$link.'"', $html, 'The button links to the frontend invitation page (absolute URL).');
        self::assertStringContainsString($link, $text);

        foreach ([$html, $text] as $body) {
            self::assertStringContainsString('Marie', $body);
            self::assertStringContainsString('Alice Martin', $body);
            self::assertStringContainsString('Cardio', $body);
            self::assertStringContainsString('Vous ne possédez pas encore de compte MedVue', str_replace('&nbsp;', ' ', $body));
            self::assertStringContainsString('ne doit pas être transmis à une autre personne', $body);
        }
        self::assertStringContainsString('Créer mon compte MedVue', $html);
        self::assertStringContainsString('#2D7FF9', $html, 'The blue "action required" accent of the reference design.');
        self::assertStringContainsString('Bien à vous', $html);
    }

    public function testNeitherTheHashNorAnyTechnicalDataLeaksIntoTheEmail(): void
    {
        $mailer = $this->mailer();
        $invitation = $this->invitation($this->team());

        $mailer->sendInvitation($invitation, self::TOKEN);

        $email = self::getMailerMessage(0);
        foreach ([(string) $email->getHtmlBody(), (string) $email->getTextBody()] as $body) {
            self::assertStringNotContainsString($invitation->getTokenHash(), $body);
        }
        self::assertSame(3, substr_count((string) $email->getHtmlBody(), self::TOKEN), 'Only in the button href and its fallback link (href + visible text).');
    }

    /**
     * The reference design ships demo defaults (a foreign support domain, a
     * made-up postal address, sample names/URLs). None of them may reach a
     * real email: the footer contact comes from SUPPORT_EMAIL and no postal
     * address is printed until a real one is confirmed.
     */
    public function testNoDemoPlaceholderFromTheReferenceDesignReachesARealEmail(): void
    {
        $mailer = $this->mailer();
        $user = new User('marie@example.com', 'Marie', 'Dupont', 'h');

        $mailer->sendInvitation($this->invitation($this->team()), self::TOKEN);
        $mailer->sendAddedToTeam($user, new User('i@example.com', 'Alice', 'Martin', 'h'), $this->team());
        $mailer->sendWelcome($user, $this->consumed('Cardio'));
        $mailer->sendWelcome($user, $this->consumed('Team A', 'Team B'));

        self::assertEmailCount(4);
        foreach ([0, 1, 2, 3] as $i) {
            $email = self::getMailerMessage($i);
            foreach ([(string) $email->getHtmlBody(), (string) $email->getTextBody()] as $body) {
                foreach (['medvue.app', 'Rue de la Loi', 'support@medvue.app', 'Vandersmissen', 'Camille', 'inv_8f3k29dqp1', 'bloc-ortho-delta', 'lorem', 'ipsum'] as $demo) {
                    self::assertStringNotContainsStringIgnoringCase($demo, $body, "Email #{$i} contains the demo value \"{$demo}\".");
                }
                // No unrendered Twig/placeholder syntax.
                self::assertDoesNotMatchRegularExpression('/\{\{|\}\}|\{%|%\}|\[(Pr[ée]nom|Nom|[ÉE]quipe|Nom de l.[ée]quipe)\]/u', $body, "Email #{$i} contains an unrendered placeholder.");
            }
            $html = (string) $email->getHtmlBody();
            self::assertStringContainsString('href="mailto:help@medvue.example"', $html, 'The footer contact is the configured one.');
            self::assertStringNotContainsString('Bruxelles, Belgique', $html);
        }
    }

    public function testNoEmailMentionsAnInstitution(): void
    {
        $mailer = $this->mailer();
        $user = new User('marie@example.com', 'Marie', 'Dupont', 'h');

        $mailer->sendInvitation($this->invitation($this->team()), self::TOKEN);
        $mailer->sendAddedToTeam($user, new User('i@example.com', 'Alice', 'Martin', 'h'), $this->team());
        $mailer->sendWelcome($user, $this->consumed('Cardio'));

        foreach ([0, 1, 2] as $i) {
            $email = self::getMailerMessage($i);
            foreach ([(string) $email->getHtmlBody(), (string) $email->getTextBody()] as $body) {
                self::assertDoesNotMatchRegularExpression('/h[oô]pital|hospital|institution/iu', $body, "Email #{$i} must not mention an institution (D115).");
            }
        }
    }

    public function testExistingUserEmail(): void
    {
        $mailer = $this->mailer();
        $team = $this->team('Cardio');
        $recipient = new User('bob@example.com', 'Bob', 'Leroy', 'h');
        $inviter = new User('inviter@example.com', 'Alice', 'Martin', 'h');

        self::assertTrue($mailer->sendAddedToTeam($recipient, $inviter, $team));

        $email = self::getMailerMessage(0);
        self::assertSame('Vous avez été ajouté à une équipe sur MedVue', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Bonjour Bob,', $html);
        self::assertStringContainsString('Alice Martin', $html);
        self::assertStringContainsString('Cardio', $html);
        self::assertStringContainsString('Cette équipe est désormais accessible directement depuis votre compte MedVue.', $html);
        self::assertStringContainsString('href="https://medvue.example/login"', $html);
        self::assertStringContainsString('#42A882', $html, 'The green accent of the reference design.');
    }

    /**
     * @return list<ConsumedInvitation>
     */
    private function consumed(string ...$teamNames): array
    {
        $joined = [];
        foreach ($teamNames as $i => $teamName) {
            $team = $this->team($teamName, 'Planning '.$teamName);
            $invitation = $this->invitation($team, email: "marie{$i}@example.com");
            $member = new PlanningTeamMember($team, new User('marie@example.com', 'Marie', 'Dupont', 'h'), TeamMemberRole::MEMBER, new \DateTimeImmutable('2027-01-01'));
            $joined[] = new ConsumedInvitation($invitation, $member);
        }

        return $joined;
    }

    public function testWelcomeEmailForOneTeam(): void
    {
        $mailer = $this->mailer();
        $user = new User('marie@example.com', 'Marie', 'Dupont', 'h');
        $joined = $this->consumed('Cardio');

        self::assertTrue($mailer->sendWelcome($user, $joined));

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertSame('Bienvenue sur MedVue', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Votre compte MedVue a bien été créé.', $html);
        self::assertStringContainsString('à laquelle vous aviez été invité par', $html);
        self::assertStringContainsString('Cardio', $html);
        self::assertStringContainsString('Alice Martin', $html);
        self::assertStringContainsString('Bienvenue sur MedVue.', $html);
        self::assertStringContainsString('https://medvue.example/plannings/'.$joined[0]->member->getPlanning()->getStableId(), $html);
    }

    public function testWelcomeEmailForSeveralTeamsIsOneEmailListingThemAll(): void
    {
        $mailer = $this->mailer();
        $user = new User('marie@example.com', 'Marie', 'Dupont', 'h');

        self::assertTrue($mailer->sendWelcome($user, $this->consumed('Team A', 'Team B', 'Team C')));

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        foreach ([(string) $email->getHtmlBody(), (string) $email->getTextBody()] as $body) {
            foreach (['Team A', 'Team B', 'Team C'] as $name) {
                self::assertStringContainsString($name, $body);
            }
        }
        self::assertStringContainsString('https://medvue.example/plannings"', (string) $email->getHtmlBody());
        self::assertStringContainsString('Accéder à mes équipes', (string) $email->getHtmlBody());
    }

    public function testNothingIsSentWhenNoInvitationWasConsumed(): void
    {
        $mailer = $this->mailer();

        self::assertTrue($mailer->sendWelcome(new User('marie@example.com', 'Marie', 'Dupont', 'h'), []));

        self::assertEmailCount(0);
    }

    /**
     * The reference macros render their argument raw (`|raw`); names typed by
     * a third party (the inviter's suggestion, a team name) must never be
     * able to inject markup into someone else's inbox.
     */
    public function testUserSuppliedNamesAreEscaped(): void
    {
        $mailer = $this->mailer();
        $team = $this->team('<b>Team</b> & "co"');
        $invitation = $this->invitation($team, first: '<script>alert(1)</script>');

        $mailer->sendInvitation($invitation, self::TOKEN);
        $mailer->sendAddedToTeam(new User('bob@example.com', '<img src=x onerror=1>', 'L', 'h'), new User('i@example.com', '<i>Eve</i>', 'M', 'h'), $team);
        $mailer->sendWelcome(new User('m@example.com', '<u>Mar</u>', 'D', 'h'), $this->consumed('<em>T</em>'));

        self::assertEmailCount(3);
        foreach ([0, 1, 2] as $i) {
            $html = (string) self::getMailerMessage($i)->getHtmlBody();
            foreach (['<script', '<img src=x', '<b>Team', '<i>Eve', '<u>Mar', '<em>T'] as $needle) {
                self::assertStringNotContainsString($needle, $html, "Email #{$i} must not contain raw {$needle}");
            }
        }
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', (string) self::getMailerMessage(0)->getHtmlBody());
    }

    public function testATransportFailureIsSwallowedAndReported(): void
    {
        self::bootKernel();
        $mailer = new InvitationMailer(
            new class implements MailerInterface {
                public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
                {
                    throw new TransportException('smtp down');
                }
            },
            new NullLogger(),
            'https://medvue.example',
            'no-reply@medvue.example',
            'help@medvue.example',
        );

        self::assertFalse($mailer->sendInvitation($this->invitation($this->team()), self::TOKEN));
    }
}
