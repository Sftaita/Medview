<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityCollection;
use App\Entity\PlanningCycle;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * The two emails of a period's preparation (docs/decisions.md D210):
 * the announcement (the collection of availabilities is open) and a
 * scheduled reminder (still not confirmed). Same charte as the manual
 * reminder (AvailabilityReminderMailer, D114); the link is the stable page
 * `/my-availability?collection=<stableId>`, built from APP_FRONTEND_URL.
 *
 * Never a word about anyone's unavailabilities, and no deadline: the target
 * date of the generation is mentioned as such, and answering later stays
 * possible. Returns whether the transport accepted the message.
 */
final class PeriodPreparationMailer
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

    public function sendAnnouncement(User $recipient, PlanningCycle $cycle, AvailabilityCollection $collection, ?\DateTimeImmutable $target): bool
    {
        return $this->send('period_announcement', 'Disponibilités à communiquer — '.$cycle->getPlanning()->getName(), $recipient, $cycle, $collection, $target);
    }

    public function sendReminder(User $recipient, PlanningCycle $cycle, AvailabilityCollection $collection, ?\DateTimeImmutable $target): bool
    {
        return $this->send('period_reminder', 'Rappel : merci de confirmer vos disponibilités — '.$cycle->getPlanning()->getName(), $recipient, $cycle, $collection, $target);
    }

    private function send(string $template, string $subject, User $recipient, PlanningCycle $cycle, AvailabilityCollection $collection, ?\DateTimeImmutable $target): bool
    {
        $email = (new TemplatedEmail())
            ->from(Address::create($this->from))
            ->to($recipient->getEmail())
            ->subject($subject)
            ->htmlTemplate('email/'.$template.'.html.twig')
            ->textTemplate('email/'.$template.'.txt.twig')
            ->context([
                'firstName' => $recipient->getFirstName(),
                'planningName' => $cycle->getPlanning()->getName(),
                'periodNumber' => $cycle->getNumber(),
                'periodLabel' => self::formatDate($cycle->getStartsAt()).' au '.self::formatDate($cycle->getEndsAt()->modify('-1 day')),
                'targetLabel' => null !== $target ? self::formatDate($target) : null,
                'availabilityUrl' => rtrim($this->frontendUrl, '/').'/my-availability?collection='.$collection->getStableId(),
                'supportEmail' => $this->supportEmail,
                'supportUrl' => 'mailto:'.$this->supportEmail,
            ]);

        try {
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $exception) {
            // Never log the body/context: it holds the recipient's link.
            $this->logger->error('Could not send the "{template}" email: {message}', ['template' => $template, 'message' => $exception->getMessage()]);

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
