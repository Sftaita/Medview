<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The calendar changed while "Compléter automatiquement" was solving
 * (docs/decisions.md D145): an assignment the solve treated as fixed, or a
 * hole it filled, is no longer what it was. Nothing was written — the
 * manager simply runs it again on the new state.
 */
final class PlanningCompletionStaleException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The calendar changed while it was being completed — nothing was written.');
    }
}
