<?php

declare(strict_types=1);

namespace App\Exception;

use App\Security\RefreshTokenFailureReason;

final class InvalidRefreshTokenException extends \RuntimeException
{
    public function __construct(public readonly RefreshTokenFailureReason $reason)
    {
        parent::__construct(sprintf('Invalid refresh token: %s.', $reason->value));
    }
}
