<?php

declare(strict_types=1);

namespace App\Exception;

/** A generation or a completion is running on this planning: nothing was written (docs/decisions.md D233). */
final class CalendarBusyException extends \RuntimeException
{
}
