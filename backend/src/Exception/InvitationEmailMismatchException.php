<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The email supplied with an invitation token is not the invitation's
 * email. The address is fixed by whoever created the invitation — a
 * client can never choose it (docs/decisions.md D113).
 */
final class InvitationEmailMismatchException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The email does not match the one this invitation was sent to.');
    }
}
