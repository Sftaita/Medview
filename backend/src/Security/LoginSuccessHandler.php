<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\RefreshTokenService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Replaces Lexik's stock success handler: still returns {"token": <JWT>},
 * but also issues a refresh token (new family — this is a fresh login) and
 * attaches it as an HttpOnly cookie. The refresh token itself never appears
 * in the JSON body.
 */
final class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenService $refreshTokenService,
        private readonly RefreshTokenCookieFactory $cookieFactory,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();
        \assert($user instanceof User);

        $accessToken = $this->jwtManager->create($user);
        $rawRefreshToken = $this->refreshTokenService->issueNewFamily($user, $request);

        $response = new JsonResponse(['token' => $accessToken]);
        $response->headers->setCookie($this->cookieFactory->create($rawRefreshToken));

        return $response;
    }
}
