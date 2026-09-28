<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A line that is the source of another line's demand policy in force
 * cannot be removed (docs/decisions.md D162): the conditional line would be
 * left depending on nothing. Change that line's policy first.
 */
final class PlanningLineIsDemandSourceException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This line is the source of another line\'s conditional demand: change that line\'s demand policy first.');
    }
}
