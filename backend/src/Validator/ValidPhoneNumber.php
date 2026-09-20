<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * The value must be a real phone number that PhoneNumberNormalizer can
 * turn into E.164. Blank values are left to NotBlank.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class ValidPhoneNumber extends Constraint
{
    public string $message = 'This is not a valid phone number. Use the international format, e.g. +32 470 12 34 56.';
}
