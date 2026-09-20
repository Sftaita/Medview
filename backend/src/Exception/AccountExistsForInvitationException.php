<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Registration through an invitation link was refused because a User
 * already exists for the invitation's email (created after the invitation
 * was sent). No second User is ever created: the invitee must log in and
 * accept the invitation instead (docs/decisions.md D113). The invitation
 * stays PENDING.
 */
final class AccountExistsForInvitationException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('An account already exists for this invitation\'s email. Log in to accept the invitation.');
    }
}
