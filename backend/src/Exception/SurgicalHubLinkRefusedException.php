<?php

declare(strict_types=1);

namespace App\Exception;

use App\Service\SurgicalHub\SurgicalHubLinkRefusal;

/**
 * A code exchange that did not create an association. Never carries the code.
 */
final class SurgicalHubLinkRefusedException extends \RuntimeException
{
    public function __construct(public readonly SurgicalHubLinkRefusal $reason)
    {
        parent::__construct(sprintf('SurgicalHub association refused: %s.', $reason->value));
    }
}
