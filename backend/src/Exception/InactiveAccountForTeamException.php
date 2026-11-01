<?php

declare(strict_types=1);

namespace App\Exception;

/** An existing MedVue account must be active before joining a team by email. */
final class InactiveAccountForTeamException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('An inactive account cannot be added to a planning team.');
    }
}
