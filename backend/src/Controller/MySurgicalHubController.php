<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SurgicalHubLink;
use App\Entity\User;
use App\Repository\SurgicalHubLinkRepository;
use App\Service\SurgicalHub\SurgicalHubApiClient;
use App\Service\SurgicalHub\SurgicalHubLeaveSyncService;
use App\Service\SurgicalHub\SurgicalHubLinkService;
use App\Service\SurgicalHub\SurgicalHubSyncOutcome;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The owner's side of the SurgicalHub association (docs/surgicalhub-integration.md
 * §4, §10). Implicitly scoped to #[CurrentUser], like /api/me/calendar-feed:
 * nobody can generate a code for, or dissociate, someone else's account.
 */
final class MySurgicalHubController
{
    public function __construct(
        private readonly SurgicalHubLinkService $linkService,
        private readonly SurgicalHubLinkRepository $linkRepository,
        private readonly SurgicalHubLeaveSyncService $syncService,
        private readonly SurgicalHubApiClient $client,
        #[Autowire(service: 'limiter.surgicalhub_link_code')]
        private readonly RateLimiterFactory $codeLimiter,
        #[Autowire(service: 'limiter.surgicalhub_manual_sync')]
        private readonly RateLimiterFactory $syncLimiter,
    ) {
    }

    #[Route('/api/me/surgicalhub', name: 'api_me_surgicalhub_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            // False when this server cannot read SurgicalHub (no URL/secret): the page says so.
            'available' => $this->client->isConfigured(),
            'link' => self::linkView($this->linkRepository->findLatestForUser($user)),
        ]);
    }

    /** « Synchroniser SurgicalHub » — synchronous, the owner's own association only. */
    #[Route('/api/me/surgicalhub/sync', name: 'api_me_surgicalhub_sync', methods: ['POST'])]
    public function sync(#[CurrentUser] User $user): JsonResponse
    {
        $link = $this->linkRepository->findCurrentForUser($user);
        if (null === $link) {
            return new JsonResponse(['error' => 'not_linked', 'message' => 'Aucun compte SurgicalHub n’est associé.'], 404);
        }
        if ($link->isSuspended()) {
            // §9: nothing is read until the owner associates again (new code) or dissociates.
            return new JsonResponse(['error' => 'link_suspended', 'message' => 'SurgicalHub ne reconnaît plus cette association.'], 409);
        }

        $limit = $this->syncLimiter->create((string) $user->getStableId())->consume(1);
        if (!$limit->isAccepted()) {
            return TooManyRequestsResponse::from($limit);
        }

        $outcome = $this->syncService->sync($link);

        return new JsonResponse([
            'outcome' => self::outcomeView($outcome),
            'link' => self::linkView($this->linkRepository->findLatestForUser($user)),
        ]);
    }

    #[Route('/api/me/surgicalhub/link-code', name: 'api_me_surgicalhub_link_code', methods: ['POST'])]
    public function issueCode(#[CurrentUser] User $user): JsonResponse
    {
        $limit = $this->codeLimiter->create((string) $user->getStableId())->consume(1);
        if (!$limit->isAccepted()) {
            return TooManyRequestsResponse::from($limit);
        }

        $issued = $this->linkService->issueCode($user);

        $response = new JsonResponse([
            'code' => $issued->displayCode,
            'expiresAt' => self::utc($issued->expiresAt),
        ], 201);
        // A credential for a few minutes: no cache may keep it.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/api/me/surgicalhub/link', name: 'api_me_surgicalhub_unlink', methods: ['DELETE'])]
    public function unlink(#[CurrentUser] User $user): Response
    {
        if (null === $this->linkService->revokeLocally($user)) {
            return new JsonResponse(['error' => 'not_linked', 'message' => 'Aucun compte SurgicalHub n’est associé.'], 404);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function linkView(?SurgicalHubLink $link): ?array
    {
        if (null === $link) {
            return null;
        }

        return [
            'status' => $link->getStatus()->value,
            'surgicalHubName' => $link->getSurgicalHubDisplayName(),
            'linkedByAdministrator' => $link->isLinkedByAdministrator(),
            'linkedAt' => self::utc($link->getLinkedAt()),
            'revokedAt' => self::utc($link->getRevokedAt()),
            'suspendedAt' => self::utc($link->getSuspendedAt()),
            'lastSyncAttemptAt' => self::utc($link->getLastSyncAttemptAt()),
            'lastSuccessfulSyncAt' => self::utc($link->getLastSuccessfulSyncAt()),
            'lastSyncError' => $link->getLastSyncError(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function outcomeView(SurgicalHubSyncOutcome $outcome): array
    {
        return [
            'status' => $outcome->status->value,
            'error' => $outcome->error?->value,
            'created' => $outcome->created,
            'updated' => $outcome->updated,
            'removed' => $outcome->removed,
        ];
    }

    private static function utc(?\DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
