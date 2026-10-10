<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RedeemSurgicalHubLinkCodeRequest;
use App\Exception\SurgicalHubLinkRefusedException;
use App\Service\SurgicalHub\SurgicalHubLinkRefusal;
use App\Service\SurgicalHub\SurgicalHubLinkService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The machine-to-machine surface SurgicalHub's server calls
 * (docs/surgicalhub-integration.md §5.1). Authenticated by its own firewall
 * (SurgicalHubIntegrationAuthenticator), never by a user JWT.
 *
 * Its only operation exchanges a code for a `linkId`: the response says
 * nothing about the MedVue account (no name, no email, no planning data).
 */
final class SurgicalHubIntegrationController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly SurgicalHubLinkService $linkService,
        #[Autowire(service: 'limiter.surgicalhub_redeem')]
        private readonly RateLimiterFactory $redeemLimiter,
    ) {
    }

    #[Route('/api/integrations/surgicalhub/v1/link-codes/redeem', name: 'api_integrations_surgicalhub_redeem', methods: ['POST'])]
    public function redeem(Request $request): JsonResponse
    {
        $limit = $this->redeemLimiter->create($request->getClientIp() ?? 'unknown')->consume(1);
        if (!$limit->isAccepted()) {
            return TooManyRequestsResponse::from($limit);
        }

        try {
            $payload = $this->serializer->deserialize(
                $request->getContent(),
                RedeemSurgicalHubLinkCodeRequest::class,
                'json',
                [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
            );
        } catch (ExtraAttributesException $exception) {
            return UnknownFieldsResponse::from($exception);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400);
        }

        $violations = $this->validator->validate($payload);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                // Never the submitted value: for `code` it would be the code itself.
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422);
        }

        try {
            $link = $this->linkService->redeem(
                $payload->code,
                $payload->surgicalHubUserId,
                $payload->surgicalHubDisplayName,
                $payload->actorDisplayName,
                $payload->actorIsAdministrator,
            );
        } catch (SurgicalHubLinkRefusedException $exception) {
            return match ($exception->reason) {
                SurgicalHubLinkRefusal::INVALID_CODE => new JsonResponse(
                    ['error' => $exception->reason->value, 'message' => 'Ce code est invalide ou a expiré.'],
                    422,
                ),
                SurgicalHubLinkRefusal::SURGICAL_HUB_ACCOUNT_TAKEN => new JsonResponse(
                    ['error' => $exception->reason->value, 'message' => 'Ce compte SurgicalHub est déjà associé à un autre compte MedVue.'],
                    409,
                ),
                SurgicalHubLinkRefusal::MEDVUE_ACCOUNT_TAKEN => new JsonResponse(
                    ['error' => $exception->reason->value, 'message' => 'Ce compte MedVue est déjà associé à un autre compte SurgicalHub. Il doit d’abord être dissocié dans MedVue.'],
                    409,
                ),
            };
        }

        return new JsonResponse([
            'linkId' => (string) $link->getStableId(),
            'linkedAt' => $link->getLinkedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
        ]);
    }
}
