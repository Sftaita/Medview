<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityCollection;
use App\Entity\Planning;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * The availability reminder email (template: templates/email/availability_reminder.*.twig,
 * same charte as InvitationMailer's messages, docs/decisions.md D114).
 *
 * Deliberately says nothing about the person's own unavailabilities nor
 * about anyone else: it asks the recipient to review and confirm, with a link
 * to the existing stable page `/my-availability?collection=<stableId>`
 * (a public UUID, never a technical id), built from APP_FRONTEND_URL and
 * never from the request.
 *
 * Best-effort like InvitationMailer::send(): returns whether the transport
 * accepted the message; the caller decides what a failure means (here: no
 * audit row is written, so "last reminder" never records a send that failed).
 */
final class AvailabilityReminderMailer
{
    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

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
     * @param non-empty-list<AvailabilityCollection> $pending the open collections $recipient has not answered yet, earliest window first
     */
    public function send(User $recipient, Planning $planning, array $pending, ?\DateTimeImmutable $deadline): bool
    {
        $firstWindow = $pending[0];
        $lastDay = $pending[0]->getEndsAt()->modify('-1 day');
        foreach ($pending as $collection) {
            $lastDay = max($lastDay, $collection->getEndsAt()->modify('-1 day'));
        }

        $email = (new TemplatedEmail())
            ->from(Address::create($this->from))
            ->to($recipient->getEmail())
            ->subject('Merci de vérifier vos disponibilités — '.$planning->getName())
            ->htmlTemplate('email/availability_reminder.html.twig')
            ->textTemplate('email/availability_reminder.txt.twig')
            ->context([
                'firstName' => $recipient->getFirstName(),
                'planningName' => $planning->getName(),
                'periodLabel' => self::formatDate($firstWindow->getStartsAt()).' au '.self::formatDate($lastDay),
                'deadlineLabel' => null !== $deadline ? self::formatDate($deadline) : null,
                'availabilityUrl' => rtrim($this->frontendUrl, '/').'/my-availability?collection='.$firstWindow->getStableId(),
                'supportEmail' => $this->supportEmail,
                'supportUrl' => 'mailto:'.$this->supportEmail,
            ]);

        try {
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $exception) {
            // Never log the body/context: it holds the recipient's link.
            $this->logger->error('Could not send the "availability_reminder" email: {message}', ['message' => $exception->getMessage()]);

            return false;
        }
    }

    /** "1er janvier 2027", "21 mars 2027". */
    private static function formatDate(\DateTimeImmutable $date): string
    {
        $day = (int) $date->format('j');

        return (1 === $day ? '1er' : (string) $day).' '.self::MONTHS[(int) $date->format('n') - 1].' '.$date->format('Y');
    }
}
