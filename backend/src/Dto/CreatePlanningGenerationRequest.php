<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Input shape for POST /api/planning-periods/{id}/generations
 * (docs/decisions.md D105) — an *optional* JSON body. Omitting it (or
 * sending `{}`) means both rest policies stay disabled, identical to the
 * pre-Lot-6D.1 behavior; only `CONFLICT` ever applies.
 *
 * Mirrors `App\Entity\RestPolicyOptions`'s own invariants exactly, but as
 * clean HTTP 422 violations instead of an uncaught `\InvalidArgumentException`
 * — the entity's constructor remains the ultimate, unbypassable guard for
 * every other construction path (tests, future service code); this DTO
 * only makes the same rules legible to an HTTP client.
 */
final class CreatePlanningGenerationRequest
{
    public bool $legalMinRestEnabled = false;

    #[Assert\Positive]
    public ?int $legalMinRestHours = null;

    public bool $teamMinRestEnabled = false;

    #[Assert\Positive]
    public ?int $teamMinRestHours = null;

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->legalMinRestEnabled && null === $this->legalMinRestHours) {
            $context->buildViolation('legalMinRestHours is required when legalMinRestEnabled is true.')
                ->atPath('legalMinRestHours')
                ->addViolation();
        }

        if (!$this->legalMinRestEnabled && null !== $this->legalMinRestHours) {
            $context->buildViolation('legalMinRestHours must be omitted when legalMinRestEnabled is false.')
                ->atPath('legalMinRestHours')
                ->addViolation();
        }

        if ($this->teamMinRestEnabled && null === $this->teamMinRestHours) {
            $context->buildViolation('teamMinRestHours is required when teamMinRestEnabled is true.')
                ->atPath('teamMinRestHours')
                ->addViolation();
        }

        if (!$this->teamMinRestEnabled && null !== $this->teamMinRestHours) {
            $context->buildViolation('teamMinRestHours must be omitted when teamMinRestEnabled is false.')
                ->atPath('teamMinRestHours')
                ->addViolation();
        }

        if (
            $this->legalMinRestEnabled && $this->teamMinRestEnabled
            && null !== $this->legalMinRestHours && null !== $this->teamMinRestHours
            && $this->teamMinRestHours < $this->legalMinRestHours
        ) {
            $context->buildViolation('teamMinRestHours must be >= legalMinRestHours when both rest policies are enabled.')
                ->atPath('teamMinRestHours')
                ->addViolation();
        }
    }
}
