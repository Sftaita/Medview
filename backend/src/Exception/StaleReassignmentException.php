<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The calendar changed between the moment the reassignment modal was
 * opened and the moment "Enregistrer la modification" was clicked
 * (docs/decisions.md D131 §Concurrence) — the block's current assignee no
 * longer matches `expectedCurrentTeamMemberStableId`. Never silently
 * overwritten: the second save must fail and tell the user to reopen the
 * duty.
 */
final class StaleReassignmentException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This assignment has changed since this window was opened.');
    }
}
