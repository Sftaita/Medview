<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A swap workflow action refused (docs/duty-swaps.md §10): a stable
 * $error code for the client, the HTTP status it maps to, and a message
 * written for the member (French) — the screen shows it as is. $reason and
 * $party carry the detail of a refused swap revalidation
 * (DutySwapNotApplicableException), null otherwise.
 */
final class DutySwapException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly int $status,
        string $message,
        public readonly ?string $reason = null,
        public readonly ?string $party = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('not_found', 404, 'Cette demande d\'échange est introuvable.');
    }

    public static function forbidden(string $message): self
    {
        return new self('forbidden', 403, $message);
    }

    public static function conflict(string $error, string $message): self
    {
        return new self($error, 409, $message);
    }

    public static function invalid(string $error, string $message): self
    {
        return new self($error, 422, $message);
    }
}
