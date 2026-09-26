<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * The two transactional emails of the "forgot password" flow (templates:
 * templates/email/), following the same conventions as InvitationMailer:
 * best-effort send (a transport failure never turns a successful DB change
 * into an error the caller must handle), never logs a token or a link.
 *
 * The reset link deliberately puts the raw token in the URL *fragment*
 * (#token=...), never a query string: the fragment is never sent to the
 * server on the initial page load, so it never reaches access logs, a
 * reverse proxy, analytics, or a Referer header (docs/decisions.md D141).
 */
final class PasswordResetMailer
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
        #[Autowire(env: 'int:PASSWORD_RESET_TOKEN_TTL')]
        private readonly int $ttlSeconds,
    ) {
    }

    /**
     * Email 1 — the reset link itself. $rawToken never touches the
     * database or a log; it only ever appears in this one URL fragment.
     */
    public function sendResetLink(User $user, string $rawToken): bool
    {
        $email = $this->baseEmail($user->getEmail(), 'Réinitialisation de votre mot de passe MedVue')
            ->htmlTemplate('email/password_reset_request.html.twig')
            ->textTemplate('email/password_reset_request.txt.twig')
            ->context([
                'firstName' => $user->getFirstName(),
                'resetUrl' => $this->url('/reset-password').'#token='.rawurlencode($rawToken),
                'ttlMinutes' => intdiv($this->ttlSeconds, 60),
            ]);

        return $this->send($email, 'password_reset_request');
    }

    /**
     * Email 2 — confirmation that the password actually changed. No token,
     * sent after the fact; distinct from email 1 in every case (never
     * combined, never sent for a request that didn't lead to a reset).
     */
    public function sendPasswordChangedAlert(User $user): bool
    {
        $email = $this->baseEmail($user->getEmail(), 'Votre mot de passe MedVue a été modifié')
            ->htmlTemplate('email/password_changed_alert.html.twig')
            ->textTemplate('email/password_changed_alert.txt.twig')
            ->context([
                'firstName' => $user->getFirstName(),
                'loginUrl' => $this->url('/login'),
            ]);

        return $this->send($email, 'password_changed_alert');
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
        $email->context(array_merge($email->getContext(), [
            'supportEmail' => $this->supportEmail,
            'supportUrl' => 'mailto:'.$this->supportEmail,
        ]));

        try {
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $exception) {
            // Never log the token/link, only that a send failed.
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
}
