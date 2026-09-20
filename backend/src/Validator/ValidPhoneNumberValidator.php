<?php

declare(strict_types=1);

namespace App\Validator;

use App\Service\PhoneNumberNormalizer;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidPhoneNumberValidator extends ConstraintValidator
{
    public function __construct(private readonly PhoneNumberNormalizer $normalizer)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidPhoneNumber) {
            throw new UnexpectedTypeException($constraint, ValidPhoneNumber::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value) || null === $this->normalizer->toE164($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
