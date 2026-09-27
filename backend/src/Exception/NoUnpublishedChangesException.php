<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * "Republier les modifications" when the current calendar is exactly what
 * the last publication diffused (docs/decisions.md D143) — nothing to tell
 * anyone, nothing is recorded, no email is sent.
 */
final class NoUnpublishedChangesException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The calendar has no change since its last publication.');
    }
}
