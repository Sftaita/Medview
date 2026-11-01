<?php

declare(strict_types=1);

namespace App\Service;

enum ReminderStatus: string
{
    /** The email was accepted by the transport and an audit row was written. */
    case SENT = 'SENT';

    /** A reminder already went to this person a moment ago (double click, two admins): nothing sent. */
    case TOO_RECENT = 'TOO_RECENT';

    /** The person takes part in no open collection: there is nothing to remind them of. */
    case NOT_PARTICIPANT = 'NOT_PARTICIPANT';

    /** The transport refused the message: nothing is recorded. */
    case EMAIL_FAILED = 'EMAIL_FAILED';
}
