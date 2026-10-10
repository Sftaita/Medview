<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

/**
 * A freshly generated association code: the only moment its raw value
 * exists. Returned once to its owner, never stored or logged.
 */
final readonly class IssuedLinkCode
{
    public function __construct(
        public string $displayCode,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
