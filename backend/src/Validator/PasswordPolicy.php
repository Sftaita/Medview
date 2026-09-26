<?php

declare(strict_types=1);

namespace App\Validator;

/**
 * The single source of truth for password strength rules: today, just a
 * minimum length. Shared by RegisterUserRequest and
 * PasswordResetConfirmRequest so a reset can never end up weaker (or
 * differently validated) than a fresh sign-up — the backend stays the only
 * place either DTO's rule is actually defined.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const MIN_LENGTH_MESSAGE = 'Your password must be at least {{ limit }} characters long.';

    private function __construct()
    {
        // Static constants holder only.
    }
}
