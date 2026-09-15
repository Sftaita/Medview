<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/api/health', name: 'health_check', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $databaseStatus = 'ok';

        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            $databaseStatus = 'error';
        }

        $status = 'ok' === $databaseStatus ? 'ok' : 'error';

        return new JsonResponse(
            ['status' => $status, 'database' => $databaseStatus],
            'ok' === $status ? 200 : 503,
        );
    }
}
