<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\WeekStructureBlockInput;
use App\Dto\WeekStructureUpdateRequest;
use App\Entity\PlanningLine;
use App\Exception\InvalidWeekStructureException;
use App\Repository\PlanningLineRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\WeekStructureBlockView;
use App\Service\WeekStructureService;
use App\Service\WeekStructureView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * GET/PUT .../week-structure (docs/decisions.md D136, docs/week-structure.md
 * §4) — read/replace a PlanningLine's weekly structure. All business logic
 * (validation, DutyPattern/AllocationFamily persistence) lives in
 * WeekStructureService; this controller only authorizes, deserializes and
 * serializes.
 */
final class WeekStructureController
{
    public function __construct(
        private readonly PlanningLineRepository $planningLineRepository,
        private readonly WeekStructureService $weekStructureService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/planning-lines/{lineStableId}/week-structure', name: 'api_week_structure_get', methods: ['GET'])]
    public function get(string $lineStableId): JsonResponse
    {
        $line = $this->resolveLine($lineStableId);

        return new JsonResponse($this->viewToArray($this->weekStructureService->read($line)));
    }

    #[Route('/api/planning-lines/{lineStableId}/week-structure', name: 'api_week_structure_put', methods: ['PUT'])]
    public function put(string $lineStableId, Request $request): JsonResponse
    {
        $line = $this->resolveLine($lineStableId);

        [$dto, $errorResponse] = $this->deserialize($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }

        try {
            $view = $this->weekStructureService->replace($line, $dto);
        } catch (InvalidWeekStructureException $exception) {
            return new JsonResponse(['error' => 'invalid_week_structure', 'message' => $exception->getMessage()], 422);
        }

        return new JsonResponse($this->viewToArray($view));
    }

    private function resolveLine(string $lineStableId): PlanningLine
    {
        $line = $this->planningLineRepository->findOneByStableId($lineStableId);
        if (null === $line) {
            throw new NotFoundHttpException('PlanningLine not found.');
        }

        if (!$this->authorizationChecker->isGranted(PlanningVoter::MANAGE_LINE_STRUCTURE, $line->getPlanning())) {
            throw new AccessDeniedHttpException('Only the creator or an OWNER/ADMIN of this Planning can manage this line\'s weekly structure.');
        }

        return $line;
    }

    /**
     * @return array{0: ?WeekStructureUpdateRequest, 1: ?JsonResponse}
     */
    private function deserialize(Request $request): array
    {
        try {
            $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [null, new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400)];
        }

        if (!\is_array($raw)) {
            return [null, new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body must be a JSON object.'], 400)];
        }

        $blocks = [];
        foreach ((array) ($raw['blocks'] ?? []) as $block) {
            if (!\is_array($block)) {
                return [null, new JsonResponse(['error' => 'validation_failed', 'violations' => ['blocks' => 'Each block must be an object.']], 422)];
            }
            $blocks[] = new WeekStructureBlockInput(
                (string) ($block['name'] ?? ''),
                array_map('strval', (array) ($block['days'] ?? [])),
                (string) ($block['family'] ?? ''),
            );
        }

        $dto = new WeekStructureUpdateRequest(
            $blocks,
            array_map('strval', (array) ($raw['solo'] ?? [])),
            (string) ($raw['soloFamily'] ?? ''),
            array_map('strval', (array) ($raw['excluded'] ?? [])),
        );

        return [$dto, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function viewToArray(WeekStructureView $view): array
    {
        return [
            'blocks' => array_values(array_map($this->blockToArray(...), $view->blocks, array_keys($view->blocks))),
            'solo' => $view->solo,
            'soloFamily' => $view->soloFamily,
            'excluded' => $view->excluded,
        ];
    }

    /**
     * `id` (A-D) is assigned here, purely for this response's shape — it
     * has no meaning server-side (no domain concept of a "block letter",
     * WeekStructureService never stores or reads one). It exists only so
     * `WeekStructureEditor`'s `WeekStructurePayload`/`fromPayload()`
     * contract (docs/week-structure.md, a fixed, already-shipped frontend
     * component this lot deliberately never touched) — which requires each
     * block to carry a `BlockId` — can round-trip a `GET` response back
     * into a `WeekStructure` unchanged. Deterministic array order (the same
     * order `WeekStructureService::read()` builds `$blocks` in) is all the
     * stability this needs: nothing downstream compares a letter across two
     * separate reads.
     *
     * @return array<string, mixed>
     */
    private function blockToArray(WeekStructureBlockView $block, int $index): array
    {
        static $letters = ['A', 'B', 'C', 'D'];

        return [
            'id' => $letters[$index] ?? 'A',
            'name' => $block->name,
            'days' => $block->days,
            'family' => $block->family,
        ];
    }
}
