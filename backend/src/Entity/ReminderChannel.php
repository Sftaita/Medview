<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * How an availability reminder reached its recipient. Only EMAIL exists
 * today; the column is there so an in-app notification (not started) can be
 * audited in the same table later.
 */
enum ReminderChannel: string
{
    case EMAIL = 'EMAIL';
}
