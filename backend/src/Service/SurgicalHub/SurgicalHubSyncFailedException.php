<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

/**
 * Reading SurgicalHub failed: nothing local may be written or deleted.
 */
final class SurgicalHubSyncFailedException extends \RuntimeException
{
    public function __construct(public readonly SurgicalHubSyncError $error)
    {
        parent::__construct(sprintf('SurgicalHub synchronisation failed: %s.', $error->value));
    }
}
