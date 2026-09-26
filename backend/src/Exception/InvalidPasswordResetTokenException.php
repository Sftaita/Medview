<?php

declare(strict_types=1);

namespace App\Exception;

use App\Security\PasswordResetFailureReason;

final class InvalidPasswordResetTokenException extends \RuntimeException
{
    public function __construct(public readonly PasswordResetFailureReason $reason)
    {
        parent::__construct(sprintf('Invalid password reset token: %s.', $reason->value));
    }
}
