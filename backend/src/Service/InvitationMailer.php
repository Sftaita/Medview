<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningTeam;
use App\Entity\TeamInvitation;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * The three transactional emails of the registration/invitation flow
 * (templates: templates/email/, visual reference: docs/Design/emails_medvue).
 *
 * Every send() is best-effort by design: by the time an email goes out the
 * database change it announces is already committed, so a mail-transport
 * failure must never turn a successful membership/registration into a 500.
 * Each method returns whether the message was handed to the transport; a
 * failure is logged (never with the token or the link) and surfaced to the
 * caller as `emailSent: false`.
 *
 * Every link is built from APP_FRONTEND_URL, never from the request, and
 * the raw invitation token appears exactly once: in the invitation link.
 */
final class InvitationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'APP_FRONTEND_URL')]
        private readonly string $frontendUrl,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $from,
        #[Autowire(env: 'SUPPORT_EMAIL')]
        private readonly string $supportEmail,
    ) {
    }

    /**
     * Email 1 — an existing user was added to a team.
     */
    public function sendAddedToTeam(User $recipient, User $inviter, PlanningTeam $team): bool
    {
        $email = $this->baseEmail($recipient->getEmail(), 'Vous avez été ajouté à une équipe sur MedVue')
            ->htmlTemplate('email/team_invitation_existing_account.html.twig')
            ->textTemplate('email/team_invitation_existing_account.txt.twig')
            ->context([
                'firstName' => $recipient->getFirstName(),
                'inviterName' => self::fullName($inviter),
                'teamName' => $team->getName(),
                'loginUrl' => $this->url('/login'),
            ]);

        return $this->send($email, 'added_to_team');
    }

    /**
     * Email 2 — invitation for an address that has no account yet.
     */
    public function sendInvitation(TeamInvitation $invitation, string $rawToken): bool
    {
        $email = $this->baseEmail($invitation->getEmail(), 'Vous êtes invité à rejoindre une équipe sur MedVue')
            ->htmlTemplate('email/team_invitation_new_account.html.twig')
            ->textTemplate('email/team_invitation_new_account.txt.twig')
            ->context([
                'firstName' => $invitation->getProposedFirstName(),
                'inviterName' => self::fullName($invitation->getInvitedBy()),
                'teamName' => $invitation->getPlanningTeam()->getName(),
                'signupUrl' => $this->url('/invitations/'.rawurlencode($rawToken)),
            ]);

        return $this->send($email, 'invitation');
    }

    /**
     * Email 3 — one single confirmation for an account creation, listing
     * every team joined through the consumed invitations.
     *
     * @param list<ConsumedInvitation> $joined
     */
    public function sendWelcome(User $user, array $joined): bool
    {
        if ([] === $joined) {
            return true;
        }

        $teams = array_map(static fn (ConsumedInvitation $c): array => [
            'name' => $c->member->getPlanningTeam()->getName(),
            'inviterName' => self::fullName($c->invitation->getInvitedBy()),
        ], $joined);

        $teamUrl = 1 === \count($joined)
            ? $this->url('/plannings/'.$joined[0]->member->getPlanning()->getStableId())
            : $this->url('/plannings');

        $email = $this->baseEmail($user->getEmail(), 'Bienvenue sur MedVue')
            ->htmlTemplate('email/account_created_welcome.html.twig')
            ->textTemplate('email/account_created_welcome.txt.twig')
            ->context([
                'firstName' => $user->getFirstName(),
                'teams' => $teams,
                'teamUrl' => $teamUrl,
            ]);

        return $this->send($email, 'welcome');
    }

    private function baseEmail(string $to, string $subject): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->from(Address::create($this->from))
            ->to($to)
            ->subject($subject);
    }

    private function send(TemplatedEmail $email, string $kind): bool
    {
        // The footer's help/contact links come from configuration, never
        // from the reference design's demo defaults (a foreign domain).
        $email->context(array_merge($email->getContext(), [
            'supportEmail' => $this->supportEmail,
            'supportUrl' => 'mailto:'.$this->supportEmail,
        ]));

        try {
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $exception) {
            // Never log the email body/context: it may hold the invitation link.
            $this->logger->error('Could not send the "{kind}" email: {message}', [
                'kind' => $kind,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function url(string $path): string
    {
        return rtrim($this->frontendUrl, '/').$path;
    }

    private static function fullName(User $user): string
    {
        return trim($user->getFirstName().' '.$user->getLastName());
    }
}
