<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PasswordResetRequestRequest;
use App\Service\PasswordResetService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Public, unauthenticated (security.yaml access_control). The response is
 * deliberately identical whether the email belongs to an account or not,
 * is active or disabled: see PUBLIC_RESPONSE below and
 * docs/authentication.md. Only genuinely structural problems (bad JSON,
 * malformed email, rate limit) get a different response — they carry no
 * information about any particular account.
 */
final class PasswordResetRequestController
{
    private const PUBLIC_RESPONSE = [
        'success' => true,
        'message' => 'Si un compte correspond à cette adresse, un email de réinitialisation a été envoyé.',
    ];

    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly PasswordResetService $passwordResetService,
        #[Autowire(service: 'limiter.password_reset_request_ip')]
        private readonly RateLimiterFactory $ipLimiter,
        #[Autowire(service: 'limiter.password_reset_request_email')]
        private readonly RateLimiterFactory $emailLimiter,
    ) {
    }

    #[Route('/api/password-reset/request', name: 'api_password_reset_request', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $ipLimit = $this->ipLimiter->create($request->getClientIp())->consume(1);
        if (!$ipLimit->isAccepted()) {
            return TooManyRequestsResponse::from($ipLimit);
        }

        try {
            $resetRequest = $this->serializer->deserialize(
                $request->getContent(),
                PasswordResetRequestRequest::class,
                'json',
                [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
            );
        } catch (ExtraAttributesException $exception) {
            return UnknownFieldsResponse::from($exception);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400);
        }

        $resetRequest->email = trim($resetRequest->email);

        $violations = $this->validator->validate($resetRequest);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422);
        }

        // Keyed by a hash of the normalized email, never the address
        // itself — it must never appear in the rate limiter's cache keys.
        // Consumed unconditionally, existing account or not: the outcome
        // must never let an attacker distinguish the two by hammering a
        // target's email into 429s.
        $emailKey = hash('sha256', mb_strtolower($resetRequest->email));
        $emailLimit = $this->emailLimiter->create($emailKey)->consume(1);
        if (!$emailLimit->isAccepted()) {
            return TooManyRequestsResponse::from($emailLimit);
        }

        $this->passwordResetService->requestReset($resetRequest->email, $request->getClientIp());

        return new JsonResponse(self::PUBLIC_RESPONSE, 200);
    }
}
