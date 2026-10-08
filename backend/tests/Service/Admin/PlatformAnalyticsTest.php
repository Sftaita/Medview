<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Entity\User;
use App\Service\Admin\PlatformAnalytics;
use App\Service\PlanningService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The definitions of docs/admin.md §5, checked on hand-made data: an empty
 * platform, day boundaries in Europe/Brussels (not UTC), distinct users vs
 * user-days, DAU/MAU, return rates on complete cohorts only, seniority.
 */
final class PlatformAnalyticsTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private PlatformAnalytics $analytics;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-12-10 12:00:00 Europe/Brussels');
        $this->analytics = self::getContainer()->get(PlatformAnalytics::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testAnEmptyPlatformReportsZerosAndNoRatios(): void
    {
        $overview = $this->analytics->overview();
        self::assertSame(['total' => 0, 'activeAccounts' => 0, 'disabledAccounts' => 0, 'registeredLast30Days' => 0, 'platformAdmins' => 0], $overview['users']);
        self::assertSame(['total' => 0, 'createdLast30Days' => 0], $overview['plannings']);
        self::assertSame(0, $overview['activity']['activeUsersLast7Days']);
        self::assertNull($overview['activity']['dataSince']);

        $series = $this->analytics->timeseries('30d');
        self::assertCount(30, $series['points']);
        self::assertSame(0, array_sum(array_column($series['points'], 'registrations')));
        self::assertSame('2026-11-11', $series['from']);
        self::assertSame('2026-12-10', $series['to']);

        $adoption = $this->analytics->adoption('30d');
        self::assertNull($adoption['current']['stickiness']);
        self::assertNull($adoption['retention']['day7']['rate']);
        self::assertSame(0, $adoption['retention']['day7']['cohortSize']);
    }

    public function testOverviewDistinguishesAccountsFromRecentlyActiveUsers(): void
    {
        $old = $this->user('old', '2026-01-05 10:00:00');
        $recent = $this->user('recent', '2026-12-01 10:00:00');
        $disabled = $this->user('disabled', '2026-11-20 10:00:00', active: false);
        $this->user('idle', '2026-12-02 10:00:00');

        $this->activity($old, '2026-12-09');          // within 7 days
        $this->activity($recent, '2026-11-20');       // within 30, not 7
        $this->activity($disabled, '2026-11-01');     // older than 30 days
        $this->planning($old, '2026-12-03 09:00:00');
        $this->planning($recent, '2026-10-01 09:00:00');

        $overview = $this->analytics->overview();
        self::assertSame(4, $overview['users']['total']);
        self::assertSame(3, $overview['users']['activeAccounts']);
        self::assertSame(1, $overview['users']['disabledAccounts']);
        self::assertSame(3, $overview['users']['registeredLast30Days']);
        self::assertSame(['total' => 2, 'createdLast30Days' => 1], $overview['plannings']);
        self::assertSame(1, $overview['activity']['activeUsersLast7Days']);
        self::assertSame(2, $overview['activity']['activeUsersLast30Days'], 'An active account is not an active user.');
        self::assertSame('2026-11-01', $overview['activity']['dataSince']);
    }

    public function testDaysAreCountedInBrusselsTime(): void
    {
        // 23:30 UTC on the 8th is 00:30 on the 9th in Brussels.
        $this->user('late', '2026-12-08 23:30:00');
        // 22:30 UTC on the 9th is 23:30 on the 9th in Brussels.
        $this->user('evening', '2026-12-09 22:30:00');

        $points = array_column($this->analytics->timeseries('7d')['points'], 'registrations', 'bucket');
        self::assertSame(0, $points['2026-12-08']);
        self::assertSame(2, $points['2026-12-09']);
        self::assertCount(7, $points);
    }

    public function testActiveUsersAreDistinctPerBucketAndUserDaysAreNot(): void
    {
        $a = $this->user('a', '2026-01-01 10:00:00');
        $b = $this->user('b', '2026-01-01 10:00:00');
        foreach (['2026-12-07', '2026-12-08', '2026-12-09'] as $day) {
            $this->activity($a, $day);
        }
        $this->activity($b, '2026-12-08');

        $weekly = $this->analytics->timeseries('30d', 'week');
        $byBucket = array_column($weekly['points'], null, 'bucket');
        self::assertArrayHasKey('2026-12-07', $byBucket, 'Weeks start on Monday.');
        self::assertSame(2, $byBucket['2026-12-07']['activeUsers']);
        self::assertSame(4, $byBucket['2026-12-07']['activeUserDays']);
        self::assertSame(2, $weekly['totals']['activeUsers']);
        self::assertSame('2026-11-09', $weekly['from'], 'The first bucket is aligned on the Monday before the range start.');
    }

    public function testTwelveMonthsAreTwelveCalendarMonths(): void
    {
        $user = $this->user('a', '2026-01-15 10:00:00');
        $this->planning($user, '2026-01-20 10:00:00');

        $series = $this->analytics->timeseries('12m');
        self::assertSame('month', $series['granularity']);
        self::assertCount(12, $series['points']);
        self::assertSame('2026-01-01', $series['points'][0]['bucket']);
        self::assertSame(1, $series['points'][0]['registrations']);
        self::assertSame(1, $series['points'][0]['plannings']);
        self::assertSame('2026-12-01', $series['points'][11]['bucket']);
    }

    public function testDauMauAndStickiness(): void
    {
        $a = $this->user('a', '2026-01-01 10:00:00');
        $b = $this->user('b', '2026-01-01 10:00:00');
        $this->activity($a, '2026-12-10');
        $this->activity($b, '2026-11-15');
        $this->activity($b, '2026-10-01'); // outside the 30-day window of today

        $adoption = $this->analytics->adoption('30d');
        $last = end($adoption['dauMau']);
        self::assertSame(['day' => '2026-12-10', 'dau' => 1, 'mau' => 2], $last);
        self::assertSame(1, $adoption['current']['dau']);
        self::assertSame(2, $adoption['current']['mau']);
        // 2 user-days over the last 30 days → 2/30 average DAU, / 2 MAU.
        self::assertEqualsWithDelta(round((2 / 30) / 2, 3), $adoption['current']['stickiness'], 0.0001);
    }

    public function testReturnRatesOnlyCountCompleteCohortsAndLaterDays(): void
    {
        // Registered 2026-11-20 (window of 7 days complete on 2026-11-27 ≤ today).
        $returned = $this->user('returned', '2026-11-20 08:00:00');
        $this->activity($returned, '2026-11-20');
        $this->activity($returned, '2026-11-25');
        $sameDayOnly = $this->user('same-day', '2026-11-21 08:00:00');
        $this->activity($sameDayOnly, '2026-11-21');
        $late = $this->user('late', '2026-11-22 08:00:00');
        $this->activity($late, '2026-12-05'); // day 13: counts for 30 days, not 7
        // Registered 2026-12-08: its 7-day window is not complete, excluded.
        $this->user('too-recent', '2026-12-08 08:00:00');

        $retention = $this->analytics->adoption('90d')['retention'];
        self::assertSame(3, $retention['day7']['cohortSize']);
        self::assertSame(1, $retention['day7']['returned']);
        self::assertSame(0.333, $retention['day7']['rate']);
        self::assertSame('2026-12-03', $retention['day7']['cohortTo']);
        // 30-day window: only registrations up to 2026-11-10 are complete.
        self::assertSame(0, $retention['day30']['cohortSize']);
        self::assertNull($retention['day30']['rate']);
    }

    public function testSeniorityCountsActiveAccountsOnly(): void
    {
        $this->user('new', '2026-12-01 10:00:00');
        $this->user('two-months', '2026-10-01 10:00:00');
        $this->user('half-year', '2026-06-01 10:00:00');
        $this->user('veteran', '2025-06-01 10:00:00');
        $this->user('gone', '2025-06-01 10:00:00', active: false);

        self::assertSame(
            [['bucket' => 'lt30', 'count' => 1], ['bucket' => '30to89', 'count' => 1], ['bucket' => '90to364', 'count' => 1], ['bucket' => 'gte365', 'count' => 1]],
            $this->analytics->adoption('30d')['seniority'],
        );
    }

    public function testUnsupportedRangesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->analytics->timeseries('2y');
    }

    public function testUnsupportedGranularityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->analytics->timeseries('30d', 'hour');
    }

    private function user(string $name, string $createdAtUtc, bool $active = true): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(\sprintf('%s-%s@example.test', $name, bin2hex(random_bytes(3))), 'Test', ucfirst($name), 'irrelevant-hash');
        $user->setActive($active);
        $em->persist($user);
        $em->flush();
        $this->connection->executeStatement('UPDATE users SET created_at = :at WHERE id = :id', ['at' => $createdAtUtc, 'id' => $user->getId()]);

        return $user;
    }

    private function activity(User $user, string $day): void
    {
        $this->connection->executeStatement(
            'INSERT INTO user_activity_days (user_id, activity_date, first_seen_at, last_seen_at) VALUES (:user, :day, :at, :at)',
            ['user' => $user->getId(), 'day' => $day, 'at' => $day.' 10:00:00'],
        );
    }

    private function planning(User $creator, string $createdAtUtc): void
    {
        $planning = self::getContainer()->get(PlanningService::class)->create('Gardes', $creator, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-03-01'), 'Europe/Brussels', 'Ligne');
        $this->connection->executeStatement('UPDATE plannings SET created_at = :at WHERE id = :id', ['at' => $createdAtUtc, 'id' => $planning->getId()]);
    }
}
