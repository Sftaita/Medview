<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A period imported from SurgicalHub is read-only in MedVue: only the
 * synchronisation changes it (docs/surgicalhub-integration.md §10).
 */
final class ImportedAvailabilityPeriodException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This period comes from SurgicalHub and can only be changed there.');
    }
}
