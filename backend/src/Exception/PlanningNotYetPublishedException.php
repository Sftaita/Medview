<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * "Republier les modifications" on a planning that was never published
 * (docs/decisions.md D143) — there is no reference to compare with; the
 * first diffusion is "Publier le planning".
 */
final class PlanningNotYetPublishedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This planning has never been published — publish it first.');
    }
}
