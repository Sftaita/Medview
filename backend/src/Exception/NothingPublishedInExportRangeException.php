<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * "Exporter" over dates where none of the chosen lines has a published
 * period (docs/decisions.md D200): there is nothing that may be exported —
 * never a document made only of "Non publiée" days.
 */
final class NothingPublishedInExportRangeException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('None of the chosen lines is published over these dates.');
    }
}
