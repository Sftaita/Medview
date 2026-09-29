<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Entity\User;
use App\Service\MyDutiesService;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The iCalendar body of a User's subscription feed (docs/decisions.md D170).
 *
 * Exactly what "Mes gardes" shows (MyDutiesService, D168): the current
 * calendar of PUBLISHED lines, the caller's own duties only, past ones
 * included — a draft never reaches anybody's phone, a late change on a
 * published line does at the next poll. A subscribed calendar mirrors the
 * feed (a duty dropped from it disappears from the app too), so nothing is
 * cut by age: a few hundred all-day events stay a small document.
 */
final class DutyCalendarFeedRenderer
{
    public const CALENDAR_NAME = 'MedVue — Mes gardes';

    public function __construct(
        private readonly MyDutiesService $myDuties,
        private readonly IcsWriter $writer,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'APP_FRONTEND_URL')]
        private readonly string $frontendUrl,
    ) {
    }

    public function render(User $user): string
    {
        return $this->writer->write(
            self::CALENDAR_NAME,
            DutyCalendarEvents::fromDuties($this->myDuties->dutiesOf($user), $this->frontendUrl),
            $this->clock->now(),
        );
    }
}
