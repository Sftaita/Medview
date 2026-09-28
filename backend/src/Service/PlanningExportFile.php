<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A rendered export, ready to be sent as a download.
 */
final readonly class PlanningExportFile
{
    public function __construct(
        public string $content,
        public string $filename,
        public string $contentType,
    ) {
    }
}
