<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\PlanningLine;

/**
 * A reinforcement line inherits its blocks from its source line
 * (docs/decisions.md D224): it has no weekly structure of its own to edit —
 * the source line's structure is the one to change.
 */
final class StructureInheritedException extends \RuntimeException
{
    public function __construct(public readonly PlanningLine $source)
    {
        parent::__construct(\sprintf('This reinforcement line inherits the structure of "%s": change that line\'s structure instead.', $source->getName()));
    }
}
