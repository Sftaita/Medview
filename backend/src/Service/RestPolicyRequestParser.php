<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CreatePlanningGenerationRequest;
use App\Entity\RestPolicyOptions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Parses/validates a `RestPolicyOptions` choice out of a request body
 * (docs/decisions.md D105/D137) — extracted so `PlanningGenerationController`
 * (per-period) and `PlanningLaunchController` (planning-level façade, D129)
 * apply the exact same rule rather than two copies that could silently
 * drift apart: an empty/absent body means both policies stay disabled
 * (`RestPolicyOptions::none()`), identical to the pre-Lot-6D.1 behavior.
 */
final class RestPolicyRequestParser
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    /**
     * @return array{0: ?RestPolicyOptions, 1: ?JsonResponse}
     */
    public function parse(Request $request): array
    {
        $content = trim($request->getContent());
        if ('' === $content) {
            return [RestPolicyOptions::none(), null];
        }

        try {
            $raw = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [null, new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400)];
        }

        if (!\is_array($raw)) {
            return [null, new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body must be a JSON object.'], 400)];
        }

        $dto = new CreatePlanningGenerationRequest();
        $dto->legalMinRestEnabled = (bool) ($raw['legalMinRestEnabled'] ?? false);
        $dto->legalMinRestHours = isset($raw['legalMinRestHours']) ? (int) $raw['legalMinRestHours'] : null;
        $dto->teamMinRestEnabled = (bool) ($raw['teamMinRestEnabled'] ?? false);
        $dto->teamMinRestHours = isset($raw['teamMinRestHours']) ? (int) $raw['teamMinRestHours'] : null;

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return [null, new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422)];
        }

        return [new RestPolicyOptions($dto->legalMinRestEnabled, $dto->legalMinRestHours, $dto->teamMinRestEnabled, $dto->teamMinRestHours), null];
    }
}
