<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A refusal of a period operation (docs/planning-cycles.md §5, §7.3): the
 * error code is the API contract, the message a developer hint.
 */
final class PlanningCycleException extends \RuntimeException implements ApiProblem
{
    private function __construct(
        private readonly string $errorCode,
        private readonly int $statusCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $errorCode, string $message): self
    {
        return new self($errorCode, 409, $message);
    }

    public static function invalid(string $errorCode, string $message): self
    {
        return new self($errorCode, 422, $message);
    }

    public static function notFound(): self
    {
        return new self('period_not_found', 404, 'This period does not belong to this planning.');
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
