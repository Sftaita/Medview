<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A duty that contradicts its line's demand policy (docs/decisions.md
 * D163): a CONDITIONAL duty on a line that is not conditional, an
 * intrinsic (REQUIRED/OPTIONAL) duty on a conditional line, or a coverage
 * source that is not a duty of the policy's source line.
 */
final class InvalidConditionalDutyException extends \InvalidArgumentException
{
}
