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
 * - republication (D172): to one person whose own duties changed, their
 *   own changes only, with the PDF of the updated planning attached.
 *
 * Best-effort like the other mailers: returns whether the transport
 * accepted the message; the caller records it (PlanningPublicationNotification)
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

    /**
     * @param PublishedDocument $document the name, period and PDF frozen with the publication (D173)
     */
    public function sendFirstPublication(User $recipient, Planning $planning, PublishedDocument $document): bool
    {
        $email = (new TemplatedEmail())
            ->from(Address::create($this->from))
            ->to($recipient->getEmail())
            ->subject('Planning de garde disponible — '.$document->planningName)
            ->htmlTemplate('email/planning_published.html.twig')
            ->textTemplate('email/planning_published.txt.twig')
            ->attach($document->content, $document->filename, 'application/pdf')
            ->context($this->context($recipient, $planning, $document) + ['periodLabel' => $document->periodLabel()]);

        return $this->send($email, 'planning_published');
    }

    /**
     * A republication (docs/decisions.md D172): this person's own changes
     * only — never anybody else's — and the whole updated planning as a PDF
     * (the bytes stored with the publication, D173 — as "Télécharger le PDF").
     *
     * @param list<array{kind: string, line: string, dutyType: ?string, block: ?string, dates: list<string>}> $changes frozen with the publication (PublicationChangeService::personalChanges)
     */
    public function sendRepublication(User $recipient, Planning $planning, array $changes, PublishedDocument $document): bool
    {
        $units = array_map(self::unitView(...), $changes);
        $removed = array_values(array_filter($units, static fn (array $unit): bool => PublicationChangeService::REMOVED === $unit['kind']));
        $added = array_values(array_filter($units, static fn (array $unit): bool => PublicationChangeService::ADDED === $unit['kind']));

        $email = (new TemplatedEmail())
            ->from(Address::create($this->from))
            ->to($recipient->getEmail())
            ->subject('Modification de vos gardes — '.$document->planningName)
            ->htmlTemplate('email/planning_republished.html.twig')
            ->textTemplate('email/planning_republished.txt.twig')
            ->attach($document->content, $document->filename, 'application/pdf')
            ->context($this->context($recipient, $planning, $document) + ['removed' => $removed, 'added' => $added, 'unitCount' => \count($units)]);

        return $this->send($email, 'planning_republished');
    }

    /**
     * One changed unit as the email shows it: a block once, with its date
     * range AND every one of its dates spelled out (a week-end block reads
     * "Vendredi 8 janvier 2027, Samedi 9 janvier 2027…", never only its
     * first day).
     *
     * @param array{kind: string, line: string, dutyType: ?string, block: ?string, dates: list<string>} $unit
     *
     * @return array{kind: string, line: string, detail: ?string, when: string, dates: list<string>}
     */
    private static function unitView(array $unit): array
    {
        $dates = array_map(static fn (string $date): \DateTimeImmutable => new \DateTimeImmutable($date), $unit['dates']);

        return [
            'kind' => $unit['kind'],
            'line' => $unit['line'],
            'detail' => null !== $unit['block'] ? 'bloc '.$unit['block'] : $unit['dutyType'],
            'when' => ucfirst(FrenchDate::range($dates[0], $dates[\count($dates) - 1])),
            'dates' => \count($dates) > 1 ? array_map(static fn (\DateTimeImmutable $date): string => ucfirst(FrenchDate::withWeekday($date)), $dates) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function context(User $recipient, Planning $planning, PublishedDocument $document): array
    {
        return [
            'firstName' => $recipient->getFirstName(),
            'planningName' => $document->planningName,
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
