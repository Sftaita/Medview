<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * "Retirer l'affectation" on a duty (or block) nobody currently holds
 * (docs/decisions.md D144) — there is nothing to remove.
 */
final class DutyAlreadyUncoveredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This duty has no current assignment to remove.');
    }
}
