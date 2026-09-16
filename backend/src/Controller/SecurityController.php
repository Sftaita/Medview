<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class SecurityController
{
    /**
     * The "login" firewall's json_login authenticator intercepts
     * POST /api/login *only* when the request's Content-Type is
     * application/json — anything else (missing header, form-encoded,
     * text/plain, ...) makes json_login decline, and the request falls
     * through to this controller. UAT found that with no explicit handling
     * here, that fallthrough previously threw an uncaught LogicException,
     * leaking a full debug stack trace on a public, unauthenticated
     * endpoint from a single malformed request (see docs/decisions.md).
     * A clean 400 is both safe and more useful to the caller.
     */
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'invalid_content_type', 'message' => 'Content-Type must be application/json.'],
            400,
        );
    }
}
