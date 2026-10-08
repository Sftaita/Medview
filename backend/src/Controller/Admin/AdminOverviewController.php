<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Admin\PlatformAnalytics;
use App\Service\Admin\SystemHealth;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Vue générale", "Statistiques" and "Infrastructure" data
 * (docs/admin.md §9). Platform administrators only — checked by
 * access_control (^/api/admin) and again here.
 */
#[IsGranted('ROLE_PLATFORM_ADMIN')]
final class AdminOverviewController
{
    public function __construct(
        private readonly PlatformAnalytics $analytics,
        private readonly SystemHealth $health,
    ) {
    }

    #[Route('/api/admin/overview', name: 'api_admin_overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        return new JsonResponse($this->analytics->overview());
    }

    #[Route('/api/admin/analytics/timeseries', name: 'api_admin_analytics_timeseries', methods: ['GET'])]
    public function timeseries(Request $request): JsonResponse
    {
        $granularity = $request->query->get('granularity');
        try {
            return new JsonResponse($this->analytics->timeseries(
                (string) $request->query->get('range', '30d'),
                null === $granularity || '' === $granularity ? null : (string) $granularity,
            ));
        } catch (\InvalidArgumentException) {
            return AdminJson::invalidQuery('range must be one of 7d, 30d, 90d, 12m; granularity one of day, week, month.');
        }
    }

    #[Route('/api/admin/analytics/adoption', name: 'api_admin_analytics_adoption', methods: ['GET'])]
    public function adoption(Request $request): JsonResponse
    {
        try {
            return new JsonResponse($this->analytics->adoption((string) $request->query->get('range', '90d')));
        } catch (\InvalidArgumentException) {
            return AdminJson::invalidQuery('range must be one of 30d, 90d, 12m.');
        }
    }

    #[Route('/api/admin/system-health', name: 'api_admin_system_health', methods: ['GET'])]
    public function systemHealth(): JsonResponse
    {
        $response = new JsonResponse($this->health->report());
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
