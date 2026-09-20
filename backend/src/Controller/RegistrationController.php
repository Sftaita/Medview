<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegisterUserRequest;
use App\Exception\AccountExistsForInvitationException;
use App\Exception\EmailAlreadyUsedException;
use App\Exception\InvitationEmailMismatchException;
use App\Exception\InvitationNotUsableException;
use App\Service\ConsumedInvitation;
use App\Service\UserRegistrationService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RegistrationController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly UserRegistrationService $registrationService,
        #[Autowire(service: 'limiter.register')]
        private readonly RateLimiterFactory $registerLimiter,
    ) {
    }

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $limit = $this->registerLimiter->create($request->getClientIp())->consume(1);
        if (!$limit->isAccepted()) {
            return TooManyRequestsResponse::from($limit);
        }

        try {
            $registerRequest = $this->serializer->deserialize(
                $request->getContent(),
                RegisterUserRequest::class,
                'json',
                // Unknown fields are rejected, never silently dropped (D116).
                [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
            );
        } catch (ExtraAttributesException $exception) {
            return UnknownFieldsResponse::from($exception);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400);
        }

        // Copy/pasted addresses often carry stray whitespace: it is the same
        // mailbox, so trim before validating (case is normalized later).
        $registerRequest->email = trim($registerRequest->email);

        $violations = $this->validator->validate($registerRequest);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422);
        }

        try {
            $result = $this->registrationService->register($registerRequest);
        } catch (EmailAlreadyUsedException) {
            return new JsonResponse(['error' => 'email_already_used', 'message' => 'This email is already registered.'], 409);
        } catch (InvitationNotUsableException $exception) {
            return new JsonResponse(
                ['error' => $exception->reason, 'message' => 'This invitation cannot be used.'],
                InvitationNotUsableException::NOT_FOUND === $exception->reason ? 404 : 410,
            );
        } catch (InvitationEmailMismatchException) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['email' => 'The email must be the one this invitation was sent to.']], 422);
        } catch (AccountExistsForInvitationException) {
            return new JsonResponse(['error' => 'account_exists_for_invitation', 'message' => 'An account already exists for this email. Log in to accept the invitation.'], 409);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($this->serializer->serialize($result->user, 'json', ['groups' => ['user:read']]), true);
        // Teams joined through consumed invitations (empty for a classic
        // sign-up), so the frontend can tell the user what just happened.
        $data['joinedTeams'] = array_map(static fn (ConsumedInvitation $c): array => $c->toArray(), $result->joined);

        return new JsonResponse($data, 201);
    }
}
