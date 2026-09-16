<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\InvalidRefreshTokenException;
use App\Security\RefreshTokenCookieFactory;
use App\Service\RefreshTokenService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public route (see access_control in security.yaml): authentication here
 * is the HttpOnly refresh cookie itself, not a Bearer JWT — that's the
 * whole point of this endpoint, so it deliberately sits outside the "api"
 * firewall's `jwt: ~` requirement.
 */
final class RefreshTokenController
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokenService,
        private readonly RefreshTokenCookieFactory $cookieFactory,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/token/refresh', name: 'api_token_refresh', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $rawToken = $request->cookies->get(RefreshTokenCookieFactory::COOKIE_NAME);

        if (!\is_string($rawToken) || '' === $rawToken) {
            return $this->invalidTokenResponse();
        }

        try {
            [$user, $newRawToken] = $this->refreshTokenService->rotate($rawToken, $request);
        } catch (InvalidRefreshTokenException $e) {
            if ('reused' === $e->reason->value) {
                $this->logger->warning('Refresh token reuse detected; family revoked.', ['reason' => $e->reason->value]);
            } else {
                $this->logger->info('Refresh token rejected.', ['reason' => $e->reason->value]);
            }

            return $this->invalidTokenResponse();
        }

        $accessToken = $this->jwtManager->create($user);

        $response = new JsonResponse(['token' => $accessToken]);
        $response->headers->setCookie($this->cookieFactory->create($newRawToken));

        return $response;
    }

    private function invalidTokenResponse(): JsonResponse
    {
        $response = new JsonResponse(['error' => 'invalid_refresh_token', 'message' => 'Invalid or expired session.'], 401);
        $response->headers->setCookie($this->cookieFactory->clear());

        return $response;
    }
}
