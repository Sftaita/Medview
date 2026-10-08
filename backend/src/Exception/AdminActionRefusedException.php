<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A platform administration action refused by a rule (docs/admin.md §3):
 * acting on one's own account, deactivating a platform administrator,
 * revoking the last administrator, a wrong password confirmation… $code is
 * the stable machine-readable reason returned to the client.
 */
final class AdminActionRefusedException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }
}
