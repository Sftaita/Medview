<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Exception\PlanningCycleException;
use App\Repository\PlanningCycleRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * The period a period-scoped endpoint works on (docs/planning-cycles.md
 * §7, §7.4): `?period={stableId}` (or `period` in a JSON body); without it,
 * the default period — current, else next, else last — so that a client
 * written before periods keeps working. A period of another planning is
 * 404 `period_not_found`.
 */
final class PlanningCycleResolver
{
    public function __construct(
        private readonly PlanningCycleRepository $cycleRepository,
        private readonly PlanningCyclePresenter $presenter,
    ) {
    }

    /**
     * @throws PlanningCycleException 404 period_not_found
     */
    public function fromRequest(Planning $planning, Request $request): PlanningCycle
    {
        $stableId = $this->stableIdOf($request);
        if ('' === $stableId) {
            return $this->presenter->defaultCycle($planning);
        }

        return $this->cycleRepository->findOneForPlanning($planning, $stableId) ?? throw PlanningCycleException::notFound();
    }

    /**
     * The period an operation that writes a generation works on — preflight
     * (it materializes duties), generation, completion (docs/planning-cycles.md
     * §7.4, lot L3). Never guessed on a planning with several periods: 422
     * `period_required` without one. A planning with a single period keeps
     * working without it, as before periods existed.
     *
     * @throws PlanningCycleException 404 period_not_found, 422 period_required
     */
    public function requiredFromRequest(Planning $planning, Request $request): PlanningCycle
    {
        $stableId = $this->stableIdOf($request);
        if ('' !== $stableId) {
            return $this->cycleRepository->findOneForPlanning($planning, $stableId) ?? throw PlanningCycleException::notFound();
        }

        $cycles = $this->cycleRepository->findByPlanning($planning);
        if (1 !== \count($cycles)) {
            throw PlanningCycleException::invalid('period_required', 'This planning has several periods: name the one to work on (period).');
        }

        return $cycles[0];
    }

    private function stableIdOf(Request $request): string
    {
        $stableId = $request->query->getString('period');
        if ('' === $stableId && '' !== $request->getContent()) {
            $body = json_decode($request->getContent(), true);
            $stableId = \is_array($body) && \is_string($body['period'] ?? null) ? $body['period'] : '';
        }

        return $stableId;
    }
}
