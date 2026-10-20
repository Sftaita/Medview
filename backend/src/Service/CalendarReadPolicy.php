<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;
use App\Security\Voter\PlanningVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Who reads which lines of a planning's calendar (lot L7, docs/decisions.md
 * D204) — the policy behind `/result` and `/assignments`, decided on the
 * server for every caller:
 *
 * - someone who manages the calendar (PlanningVoter::MANAGE_CALENDAR: the
 *   creator, an OWNER/ADMIN of a team) reads every line, published or not —
 *   they prepare it;
 * - anyone else who may VIEW the planning reads the lines PUBLISHED (or
 *   ARCHIVED) in the period shown, with their current assignments — the
 *   operational calendar, reassignments and swaps made after publication
 *   included (D168). A line not published in that period is left out
 *   entirely: the answer is the one a line that does not exist would give.
 *
 * It narrows D148 ("un membre a la lecture") to what was published, like
 * "Mes gardes" (D168), the feed, the reminders, the exports (D200) and the
 * statistics (D201) already do.
 */
final class CalendarReadPolicy
{
    private const PUBLISHED_STATUSES = [PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];

    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /** Whether the caller reads the lines not published yet. */
    public function readsUnpublished(Planning $planning): bool
    {
        return $this->authorizationChecker->isGranted(PlanningVoter::MANAGE_CALENDAR, $planning);
    }

    /** Whether $line is published in $cycle — its own period there, never another line's or another period's. */
    public function isPublishedIn(PlanningLine $line, PlanningCycle $cycle): bool
    {
        $period = $line->periodIn($cycle);

        return null !== $period && \in_array($period->getStatus(), self::PUBLISHED_STATUSES, true);
    }

    /** Whether the caller may read $line in $cycle. */
    public function canRead(PlanningLine $line, PlanningCycle $cycle, bool $readsUnpublished): bool
    {
        return $readsUnpublished || $this->isPublishedIn($line, $cycle);
    }
}
