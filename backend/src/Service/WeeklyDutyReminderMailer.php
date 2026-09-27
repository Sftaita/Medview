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
 * "Vos gardes de la semaine prochaine" (docs/decisions.md D146) — only ever
 * called with at least one item: there is no "no duty" variant of this
 * email, on purpose. Best-effort, like the other mailers.
 */
final class WeeklyDutyReminderMailer
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
     * @param non-empty-list<array{when: string, what: string}> $items
     */
    public function send(User $recipient, Planning $planning, \DateTimeImmutable $weekStart, array $items): bool
    {
        $email = (new TemplatedEmail())
            ->from(Address::create($this->from))
            ->to($recipient->getEmail())
            ->subject('Vos gardes de la semaine prochaine — '.$planning->getName())
            ->htmlTemplate('email/weekly_duty_reminder.html.twig')
            ->textTemplate('email/weekly_duty_reminder.txt.twig')
            ->context([
                'firstName' => $recipient->getFirstName(),
                'planningName' => $planning->getName(),
                'weekLabel' => 'Semaine du '.FrenchDate::long($weekStart),
                'items' => $items,
                'planningUrl' => rtrim($this->frontendUrl, '/').'/plannings/'.$planning->getStableId(),
                'supportEmail' => $this->supportEmail,
                'supportUrl' => 'mailto:'.$this->supportEmail,
            ]);

        try {
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('Could not send the "weekly_duty_reminder" email: {message}', ['message' => $exception->getMessage()]);

            return false;
        }
    }
}
