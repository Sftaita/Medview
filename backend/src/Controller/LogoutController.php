<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\RefreshTokenCookieFactory;
use App\Service\RefreshTokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public route, same reasoning as RefreshTokenController: identified by the
 * cookie, not a Bearer JWT. Idempotent and always succeeds from the
 * client's point of view — logging out with no/invalid session is not an
 * error, the end state ("no active session") already holds.
 */
final class LogoutController
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokenService,
        private readonly RefreshTokenCookieFactory $cookieFactory,
    ) {
    }

    #[Route('/api/token/logout', name: 'api_token_logout', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $rawToken = $request->cookies->get(RefreshTokenCookieFactory::COOKIE_NAME);

        if (\is_string($rawToken) && '' !== $rawToken) {
            $this->refreshTokenService->revokeByRawToken($rawToken);
        }

        $response = new JsonResponse(['success' => true]);
        $response->headers->setCookie($this->cookieFactory->clear());

        return $response;
    }
}
