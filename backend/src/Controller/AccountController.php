<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;

final class AccountController
{
    public function __construct(private readonly SerializerInterface $serializer)
    {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        $json = $this->serializer->serialize($user, 'json', ['groups' => ['user:read']]);

        return JsonResponse::fromJsonString($json);
    }
}
