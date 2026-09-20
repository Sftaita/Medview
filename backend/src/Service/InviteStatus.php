<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Business outcome of "add this person to this team".
 */
enum InviteStatus: string
{
    /** The email belongs to an existing User: membership created immediately, no acceptance step. */
    case USER_ADDED = 'USER_ADDED';
    /** No User for this email: a TeamInvitation was created and its email sent. */
    case INVITATION_CREATED = 'INVITATION_CREATED';
    /** The existing User already has an open membership in this team. Nothing changed. */
    case ALREADY_MEMBER = 'ALREADY_MEMBER';
    /** A usable invitation for this (team, email) already exists. Nothing changed. */
    case INVITATION_ALREADY_PENDING = 'INVITATION_ALREADY_PENDING';
}
