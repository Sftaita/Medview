<?php

declare(strict_types=1);

namespace App\Controller;

use App\Calendar\DutyCalendarFeedRenderer;
use App\Entity\CalendarFeed;
use App\Entity\User;
use App\Service\CalendarFeedService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Calendar subscription of "Mes gardes" (docs/decisions.md D170).
 *
 * /api/me/calendar-feed is implicitly scoped to #[CurrentUser] (no Voter,
 * like /api/me/duties). /api/calendar-feeds/{token}.ics is public — calendar
 * apps send no JWT, the token is the credential (security.yaml) — and
 * answers the same 404 for an unknown, revoked or deactivated one.
 */
final class CalendarFeedController
{
    public function __construct(
        private readonly CalendarFeedService $service,
        private readonly DutyCalendarFeedRenderer $renderer,
    ) {
    }

    #[Route('/api/me/calendar-feed', name: 'api_me_calendar_feed_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return self::feedResponse($this->service->activeOf($user));
    }

    #[Route('/api/me/calendar-feed', name: 'api_me_calendar_feed_enable', methods: ['POST'])]
    public function enable(#[CurrentUser] User $user): JsonResponse
    {
        return self::feedResponse($this->service->enable($user));
    }

    #[Route('/api/me/calendar-feed/regenerate', name: 'api_me_calendar_feed_regenerate', methods: ['POST'])]
    public function regenerate(#[CurrentUser] User $user): JsonResponse
    {
        return self::feedResponse($this->service->regenerate($user));
    }

    #[Route('/api/me/calendar-feed', name: 'api_me_calendar_feed_disable', methods: ['DELETE'])]
    public function disable(#[CurrentUser] User $user): Response
    {
        $this->service->disable($user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/calendar-feeds/{token}.ics', name: 'api_calendar_feed_ics', methods: ['GET'])]
    public function ics(string $token): Response
    {
        $user = $this->service->resolve($token);
        if (null === $user) {
            throw new NotFoundHttpException('Calendar feed not found.');
        }

        return new Response($this->renderer->render($user), Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="medvue-mes-gardes.ics"',
            // Personal data behind a secret address: no shared cache may keep it.
            'Cache-Control' => 'private, no-cache',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    private static function feedResponse(?CalendarFeed $feed): JsonResponse
    {
        return new JsonResponse(['feed' => null === $feed ? null : [
            'token' => $feed->getToken(),
            'createdAt' => self::utc($feed->getCreatedAt()),
            'lastFetchedAt' => null === $feed->getLastFetchedAt() ? null : self::utc($feed->getLastFetchedAt()),
        ]]);
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }
}
