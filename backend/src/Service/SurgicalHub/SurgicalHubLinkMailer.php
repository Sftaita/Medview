<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

use App\Entity\SurgicalHubLink;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Tells the owner of a MedVue account that it was associated with a
 * SurgicalHub account, by whom and when (docs/decisions.md D182): the code
 * proves they consented to *an* association, this email lets them check it
 * is the right one and undo it from their account page. Same conventions as
 * PasswordResetMailer: best effort, sent after commit, nothing secret in it.
 */
final class SurgicalHubLinkMailer
{
    private const TIMEZONE = 'Europe/Brussels';

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

    public function sendLinkedNotice(SurgicalHubLink $link, string $actorLabel): bool
    {
        $user = $link->getUser();
        $email = (new TemplatedEmail())
            ->from(Address::create($this->from))
            ->to($user->getEmail())
            ->subject('Votre compte MedVue est associé à SurgicalHub')
            ->htmlTemplate('email/surgicalhub_linked.html.twig')
            ->textTemplate('email/surgicalhub_linked.txt.twig')
            ->context([
                'firstName' => $user->getFirstName(),
                'surgicalHubName' => $link->getSurgicalHubDisplayName(),
                'actorLabel' => $actorLabel,
                'byAdministrator' => $link->isLinkedByAdministrator(),
                'linkedAtLabel' => self::label($link->getLinkedAt()),
                'accountUrl' => rtrim($this->frontendUrl, '/').'/account',
                'supportEmail' => $this->supportEmail,
                'supportUrl' => 'mailto:'.$this->supportEmail,
            ]);

        try {
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('Could not send the "surgicalhub_linked" email: {message}', ['message' => $exception->getMessage()]);

            return false;
        }
    }

    private static function label(\DateTimeImmutable $instant): string
    {
        $local = $instant->setTimezone(new \DateTimeZone(self::TIMEZONE));

        return $local->format('d/m/Y').' à '.$local->format('H:i');
    }
}
