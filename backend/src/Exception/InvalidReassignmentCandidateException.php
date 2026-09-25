<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The chosen candidate is not (or is no longer) selectable — re-checked
 * for real at save time, never trusted from what the modal showed when it
 * opened (docs/decisions.md D131 §Revalidation). Covers both "not a member
 * of this team" and "blocked by a real constraint" — the message never
 * exposes solver/CP-SAT internals, only the same real exclusion reason the
 * candidates list already carries.
 */
final class InvalidReassignmentCandidateException extends \RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct(\sprintf('This candidate is not selectable for this duty: %s.', $reason));
    }
}
