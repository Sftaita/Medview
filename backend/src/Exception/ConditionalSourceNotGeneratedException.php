<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A conditional line cannot be snapshotted while its source line has no
 * COMPLETED generation (docs/decisions.md D164): nobody holds its source
 * duties, so its demand cannot be decided. Generate the source line first.
 */
final class ConditionalSourceNotGeneratedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The source line of this conditional line has no completed generation yet: generate it first.');
    }
}
