<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimit;

/**
 * The one 429 shape every rate-limited endpoint returns (the frontend reads
 * Retry-After to tell the user how long to wait).
 */
final class TooManyRequestsResponse
{
    public static function from(RateLimit $limit): JsonResponse
    {
        $response = new JsonResponse(
            ['error' => 'too_many_attempts', 'message' => 'Too many attempts. Please try again later.'],
            429,
        );
        $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

        return $response;
    }
}
