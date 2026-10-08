<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\UnknownFieldsResponse;
use App\Exception\AdminActionRefusedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Request/response plumbing shared by the /api/admin controllers: strict
 * JSON bodies (unknown fields rejected, D116), validation errors in the
 * usual `validation_failed` shape, query-string pagination, and refused
 * actions as `{error, message}`.
 */
final class AdminJson
{
    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|JsonResponse
     */
    public function body(Request $request, string $class): object
    {
        $content = trim($request->getContent());
        try {
            $dto = $this->serializer->deserialize('' === $content ? '{}' : $content, $class, 'json', [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false]);
        } catch (ExtraAttributesException $exception) {
            return UnknownFieldsResponse::from($exception);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400);
        }

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422);
        }

        return $dto;
    }

    /**
     * @return array{0: int, 1: int}|JsonResponse page (1-based), items per page
     */
    public function pagination(Request $request, int $defaultPerPage = 25): array|JsonResponse
    {
        $page = filter_var($request->query->get('page', '1'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        $perPage = filter_var($request->query->get('perPage', (string) $defaultPerPage), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_PER_PAGE]]);
        if (false === $page || false === $perPage) {
            return self::invalidQuery('page must be ≥ 1 and perPage between 1 and '.self::MAX_PER_PAGE.'.');
        }

        return [$page, $perPage];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public static function page(array $items, int $total, int $page, int $perPage): JsonResponse
    {
        return new JsonResponse([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    public static function invalidQuery(string $message): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid_query', 'message' => $message], 400);
    }

    public static function refused(AdminActionRefusedException $exception): JsonResponse
    {
        return new JsonResponse(['error' => $exception->reason, 'message' => $exception->getMessage()], $exception->status);
    }
}
