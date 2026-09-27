<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * The two publication emails (docs/decisions.md D143), same charte as the
 * other transactional emails (templates/email/_base.html.twig):
 * - first publication: "the planning is available", with the PDF of what
 *   was published attached;
 * - republication: exactly what changed on the impacted dates, no PDF.
 *
 * Best-effort like the other mailers: returns whether the transport
 * accepted the message; the caller records it (PlanningPublicationDelivery)
 * and never rolls a publication back because of an email.
 */
final class PlanningPublicationMailer
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

    public function sendFirstPublication(User $recipient, Planning $planning, string $periodLabel, string $pdf, string $pdfFilename): bool
    {
        $email = (new TemplatedEmail())
            ->from(Address::create($this->from))
            ->to($recipient->getEmail())
            ->subject('Planning de garde disponible — '.$planning->getName())
            ->htmlTemplate('email/planning_published.html.twig')
            ->textTemplate('email/planning_published.txt.twig')
            ->attach($pdf, $pdfFilename, 'application/pdf')
            ->context($this->context($recipient, $planning) + ['periodLabel' => $periodLabel]);

        return $this->send($email, 'planning_published');
    }

    /**
     * @param array{units: list<array{line: string, when: string, block: ?string, before: string, after: string}>, days: list<array{label: string, rows: list<array{line: string, text: string}>}>} $digest
     */
    public function sendRepublication(User $recipient, Planning $planning, array $digest): bool
    {
        $email = (new TemplatedEmail())
            ->from(Address::create($this->from))
            ->to($recipient->getEmail())
            ->subject('Modification du planning de garde — '.$planning->getName())
            ->htmlTemplate('email/planning_republished.html.twig')
            ->textTemplate('email/planning_republished.txt.twig')
            ->context($this->context($recipient, $planning) + ['units' => $digest['units'], 'days' => $digest['days']]);

        return $this->send($email, 'planning_republished');
    }

    /**
     * @return array<string, mixed>
     */
    private function context(User $recipient, Planning $planning): array
    {
        return [
            'firstName' => $recipient->getFirstName(),
            'planningName' => $planning->getName(),
            'planningUrl' => rtrim($this->frontendUrl, '/').'/plannings/'.$planning->getStableId(),
            'supportEmail' => $this->supportEmail,
            'supportUrl' => 'mailto:'.$this->supportEmail,
        ];
    }

    private function send(TemplatedEmail $email, string $kind): bool
    {
        try {
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('Could not send the "{kind}" email: {message}', ['kind' => $kind, 'message' => $exception->getMessage()]);

            return false;
        }
    }
}
