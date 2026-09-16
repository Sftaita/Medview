<?php

declare(strict_types=1);

namespace App\Exception;

final class TeamMembershipConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This User already has an open membership in this Team.');
    }
}
