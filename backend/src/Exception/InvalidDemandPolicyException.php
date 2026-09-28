<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A demand policy that breaks a V1 structure rule (docs/decisions.md D162):
 * 422, with a stable machine code and the field concerned — never only a
 * free-form message.
 */
final class InvalidDemandPolicyException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}
