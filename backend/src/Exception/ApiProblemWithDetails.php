<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * An ApiProblem whose answer also says precisely what is wrong:
 * ApiExceptionListener adds `details` to {error, message}.
 */
interface ApiProblemWithDetails extends ApiProblem
{
    /**
     * @return list<array<string, mixed>>
     */
    public function details(): array;
}
