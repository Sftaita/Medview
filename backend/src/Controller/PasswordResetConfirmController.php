<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PasswordResetConfirmRequest;
use App\Exception\InvalidPasswordResetTokenException;
use App\Security\RefreshTokenCookieFactory;
use App\Service\PasswordResetService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Public, unauthenticated (security.yaml access_control) — the token itself
 * is the credential, checked entirely inside PasswordResetService. Every
 * failure reason (unknown/expired/consumed/revoked/account no longer
 * eligible) collapses to the same generic response: never enough to tell
 * an attacker which case they hit, or that a given token/account exists at
 * all (docs/authentication.md).
 *
 * An invalid *password* (policy violation) is rejected by validation below,
 * before PasswordResetService is ever called — the token is never touched,
 * let alone consumed, by a request that never had a valid new password.
 */
final class PasswordResetConfirmController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly PasswordResetService $passwordResetService,
        private readonly RefreshTokenCookieFactory $cookieFactory,
    ) {
    }

    #[Route('/api/password-reset/confirm', name: 'api_password_reset_confirm', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $confirmRequest = $this->serializer->deserialize(
                $request->getContent(),
                PasswordResetConfirmRequest::class,
                'json',
                [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
            );
        } catch (ExtraAttributesException $exception) {
            return UnknownFieldsResponse::from($exception);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400);
        }

        $violations = $this->validator->validate($confirmRequest);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422);
        }

        try {
            $this->passwordResetService->confirmReset($confirmRequest->token, $confirmRequest->newPassword);
        } catch (InvalidPasswordResetTokenException) {
            // Same response whatever the internal reason (see class doc).
            return new JsonResponse(
                ['error' => 'invalid_or_expired_token', 'message' => 'Ce lien est invalide ou a expiré.'],
                400,
            );
        }

        $response = new JsonResponse(['success' => true, 'message' => 'Votre mot de passe a été modifié.']);
        // Best-effort clean-up of this browser's own refresh cookie, if it
        // still had one: the underlying session was revoked server-side
        // regardless (PasswordResetService::confirmReset), this only spares
        // the browser a doomed refresh attempt on its next request.
        $response->headers->setCookie($this->cookieFactory->clear());

        return $response;
    }
}
