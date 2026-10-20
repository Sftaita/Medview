<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A domain refusal that carries its own HTTP answer: ApiExceptionListener
 * renders it as {error: errorCode(), message} with statusCode(), wherever
 * it is thrown from — no controller has to know it (docs/decisions.md D187).
 */
interface ApiProblem extends \Throwable
{
    public function statusCode(): int;

    public function errorCode(): string;
}
