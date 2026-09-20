<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;

/**
 * The 422 returned when a JSON body carries a field the endpoint does not
 * accept (docs/decisions.md D116). A client must never believe a value was
 * stored when the API silently ignored it — e.g. the retired
 * `primaryHospitalStableId` (D115). Same `validation_failed` shape as every
 * other input error, one violation per unknown field.
 */
final class UnknownFieldsResponse
{
    public static function from(ExtraAttributesException $exception): JsonResponse
    {
        $violations = [];
        foreach ($exception->getExtraAttributes() as $field) {
            $violations[(string) $field] = 'This field is not accepted.';
        }

        return new JsonResponse(['error' => 'validation_failed', 'violations' => $violations], 422);
    }
}
