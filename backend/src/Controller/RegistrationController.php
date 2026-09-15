<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegisterUserRequest;
use App\Exception\EmailAlreadyUsedException;
use App\Service\UserRegistrationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RegistrationController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly UserRegistrationService $registrationService,
    ) {
    }

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $registerRequest = $this->serializer->deserialize(
                $request->getContent(),
                RegisterUserRequest::class,
                'json',
            );
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400);
        }

        $violations = $this->validator->validate($registerRequest);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422);
        }

        try {
            $user = $this->registrationService->register($registerRequest);
        } catch (EmailAlreadyUsedException) {
            return new JsonResponse(['error' => 'email_already_used', 'message' => 'This email is already registered.'], 409);
        }

        $json = $this->serializer->serialize($user, 'json', ['groups' => ['user:read']]);

        return JsonResponse::fromJsonString($json, 201);
    }
}
