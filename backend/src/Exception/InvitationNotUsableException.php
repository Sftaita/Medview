<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The invitation link cannot be used. $reason is safe to show to the
 * holder of the link (they already hold the secret): it never says
 * anything about *other* invitations, teams or accounts.
 */
final class InvitationNotUsableException extends \RuntimeException
{
    public const NOT_FOUND = 'invitation_not_found';
    public const EXPIRED = 'invitation_expired';
    public const REVOKED = 'invitation_revoked';
    public const ALREADY_USED = 'invitation_already_used';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(\sprintf('The invitation cannot be used (%s).', $reason));
    }
}
