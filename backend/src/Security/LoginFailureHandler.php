<?php

declare(strict_types=1);

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationFailureHandler as LexikAuthenticationFailureHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Wraps Lexik's stock failure handler (kept for the "Invalid credentials."
 * generic 401 — see docs/decisions.md D015) and only special-cases login
 * throttling, which Symfony's security_login_throttling emits as a plain
 * AuthenticationException that Lexik would otherwise also flatten to 401.
 */
final class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(private readonly LexikAuthenticationFailureHandler $lexikHandler)
    {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            $retryAfterMinutes = (int) ($exception->getMessageData()['%minutes%'] ?? 1);

            $response = new JsonResponse(
                ['error' => 'too_many_attempts', 'message' => 'Too many login attempts. Please try again later.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
            $response->headers->set('Retry-After', (string) max(1, $retryAfterMinutes * 60));

            return $response;
        }

        return $this->lexikHandler->onAuthenticationFailure($request, $exception);
    }
}
