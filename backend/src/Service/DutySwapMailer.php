<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DutySwapNotification;
use App\Entity\DutySwapNotificationKind;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * The swap workflow's emails (docs/duty-swaps.md §8), rendered from a
 * DutySwapNotification's frozen payload — never from the live state, so a
 * retry sends exactly what the first attempt would have. Same charte as
 * the other transactional emails (templates/email/_base.html.twig).
 *
 * Returns null when the transport accepted the message, or the error — the
 * caller records the attempt (DutySwapNotification::markFailed) and never
 * undoes a workflow step because of an email.
 */
final class DutySwapMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'APP_FRONTEND_URL')]
        private readonly string $frontendUrl,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $from,
    ) {
    }

    public function send(DutySwapNotification $notification): ?string
    {
        $payload = $notification->getPayload();
        $recipient = $notification->getRecipient();
        $base = rtrim($this->frontendUrl, '/');

        [$subject, $template] = match ($notification->getKind()) {
            DutySwapNotificationKind::SWAP_CONFIRMED => ['MedVue — Confirmation de votre échange de gardes', 'duty_swap_confirmed'],
            DutySwapNotificationKind::AGREED_PROPOSAL_RECEIVED => ['MedVue — '.$payload['requesterName'].' vous propose un échange de gardes', 'duty_swap_agreed_proposal'],
            DutySwapNotificationKind::REQUEST_RECEIVED => ['MedVue — '.$payload['requesterName'].' cherche à échanger une garde', 'duty_swap_request_received'],
            DutySwapNotificationKind::PROPOSAL_RECEIVED => ['MedVue — Nouvelle proposition d\'échange de '.$payload['counterpartName'], 'duty_swap_proposal_received'],
            DutySwapNotificationKind::PROPOSAL_REFUSED => ['MedVue — Votre proposition d\'échange a été refusée', 'duty_swap_proposal_refused'],
        };

        $email = (new TemplatedEmail())
            ->from(Address::create($this->from))
            ->to($recipient->getEmail())
            ->subject($subject)
            ->htmlTemplate('email/'.$template.'.html.twig')
            ->textTemplate('email/'.$template.'.txt.twig')
            ->context($payload + [
                'firstName' => $recipient->getFirstName(),
                'swapsUrl' => $base.'/swaps',
                'requestUrl' => $base.'/swaps?request='.$payload['requestStableId'],
            ]);

        try {
            $this->mailer->send($email);

            return null;
        } catch (\Throwable $exception) {
            $this->logger->error('Could not send the "{kind}" swap email: {message}', ['kind' => $notification->getKind()->value, 'message' => $exception->getMessage()]);

            return $exception->getMessage();
        }
    }
}
