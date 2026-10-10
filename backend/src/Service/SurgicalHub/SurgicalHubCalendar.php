<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Days and windows of the integration (docs/surgicalhub-integration.md §7.1-§7.2):
 * SurgicalHub speaks in calendar dates (end inclusive), MedVue's calendar in
 * instants. One fixed business timezone does the translation, and also
 * defines "today" for the synchronisation window and for revocation (D9).
 */
final class SurgicalHubCalendar
{
    public const TIMEZONE = 'Europe/Brussels';

    public function __construct(
        #[Autowire(env: 'int:SURGICALHUB_SYNC_PAST_DAYS')]
        private readonly int $pastDays,
        #[Autowire(env: 'int:SURGICALHUB_SYNC_FUTURE_MONTHS')]
        private readonly int $futureMonths,
    ) {
    }

    /** Today's date in the business timezone, at midnight UTC (a pure date). */
    public function today(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return new \DateTimeImmutable($now->setTimezone(self::zone())->format('Y-m-d'), new \DateTimeZone('UTC'));
    }

    /** Local midnight starting today — imported periods starting at or after it are "future" (D9). */
    public function startOfToday(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return self::startOf($this->today($now));
    }

    /**
     * The reconciled window (D5): [today − past days, today + future months], dates inclusive.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function window(\DateTimeImmutable $now): array
    {
        $today = $this->today($now);

        return [
            $today->modify(sprintf('-%d days', $this->pastDays)),
            $today->modify(sprintf('+%d months', $this->futureMonths)),
        ];
    }

    /**
     * An absence [start, end] (dates, end inclusive) as a whole-day period
     * [start 00:00, end+1 00:00[ in the business timezone.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function instants(\DateTimeImmutable $startDate, \DateTimeImmutable $endDate): array
    {
        return [self::startOf($startDate), self::startOf($endDate->modify('+1 day'))];
    }

    private static function startOf(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d').' 00:00:00', self::zone());
    }

    private static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }
}
