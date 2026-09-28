<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A NEW assignment on a conditional duty whose live demand says "not
 * required" (docs/decisions.md D165): refused at write time, whatever path
 * the request took. An assignment already there stays (superfluous
 * reinforcement); only "Retirer l'affectation" touches it.
 */
final class CoverageNotRequiredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This reinforcement is not required by the current calendar: nobody can be newly assigned to it.');
    }
}
