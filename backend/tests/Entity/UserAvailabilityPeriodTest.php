<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\UserAvailabilityPeriod;
use App\Entity\UserAvailabilityType;
use PHPUnit\Framework\TestCase;

final class UserAvailabilityPeriodTest extends TestCase
{
    private function newUser(): User
    {
        return new User('a@example.com', 'A', 'User', 'hash');
    }

    public function testConstructUnavailableValid(): void
    {
        $period = new UserAvailabilityPeriod(
            $this->newUser(),
            UserAvailabilityType::UNAVAILABLE,
            new \DateTimeImmutable('2026-11-10 08:00:00'),
            new \DateTimeImmutable('2026-11-11 08:00:00'),
        );

        self::assertSame(UserAvailabilityType::UNAVAILABLE, $period->getType());
    }

    public function testConstructPreferDutyValid(): void
    {
        $period = new UserAvailabilityPeriod(
            $this->newUser(),
            UserAvailabilityType::PREFER_DUTY,
            new \DateTimeImmutable('2026-11-10 08:00:00'),
            new \DateTimeImmutable('2026-11-11 08:00:00'),
        );

        self::assertSame(UserAvailabilityType::PREFER_DUTY, $period->getType());
    }

    public function testStartsAtEqualsEndsAtIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UserAvailabilityPeriod(
            $this->newUser(),
            UserAvailabilityType::UNAVAILABLE,
            new \DateTimeImmutable('2026-11-10 08:00:00'),
            new \DateTimeImmutable('2026-11-10 08:00:00'),
        );
    }

    public function testStartsAtAfterEndsAtIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UserAvailabilityPeriod(
            $this->newUser(),
            UserAvailabilityType::UNAVAILABLE,
            new \DateTimeImmutable('2026-11-11 08:00:00'),
            new \DateTimeImmutable('2026-11-10 08:00:00'),
        );
    }

    public function testRescheduleValid(): void
    {
        $period = new UserAvailabilityPeriod(
            $this->newUser(),
            UserAvailabilityType::UNAVAILABLE,
            new \DateTimeImmutable('2026-11-10 08:00:00'),
            new \DateTimeImmutable('2026-11-11 08:00:00'),
        );
        $originalUpdatedAt = $period->getUpdatedAt();

        $period->reschedule(
            UserAvailabilityType::PREFER_DUTY,
            new \DateTimeImmutable('2026-12-01 00:00:00'),
            new \DateTimeImmutable('2026-12-02 00:00:00'),
        );

        self::assertSame(UserAvailabilityType::PREFER_DUTY, $period->getType());
        self::assertEquals(new \DateTimeImmutable('2026-12-01 00:00:00'), $period->getStartsAt());
        self::assertGreaterThanOrEqual($originalUpdatedAt, $period->getUpdatedAt());
    }

    public function testRescheduleRejectsInvalidOrdering(): void
    {
        $period = new UserAvailabilityPeriod(
            $this->newUser(),
            UserAvailabilityType::UNAVAILABLE,
            new \DateTimeImmutable('2026-11-10 08:00:00'),
            new \DateTimeImmutable('2026-11-11 08:00:00'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $period->reschedule(
            UserAvailabilityType::UNAVAILABLE,
            new \DateTimeImmutable('2026-12-02 00:00:00'),
            new \DateTimeImmutable('2026-12-01 00:00:00'),
        );
    }

    public function testOverlapsOrTouchesDetectsStrictOverlap(): void
    {
        $user = $this->newUser();
        $a = new UserAvailabilityPeriod($user, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-15'));
        $b = new UserAvailabilityPeriod($user, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2026-11-12'), new \DateTimeImmutable('2026-11-20'));

        self::assertTrue($a->overlapsOrTouches($b));
    }

    public function testOverlapsOrTouchesDetectsTouchingBoundary(): void
    {
        $user = $this->newUser();
        $a = new UserAvailabilityPeriod($user, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-15'));
        $b = new UserAvailabilityPeriod($user, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2026-11-15'), new \DateTimeImmutable('2026-11-20'));

        self::assertTrue($a->overlapsOrTouches($b), 'Touching periods must count as conflicting.');
    }

    public function testOverlapsOrTouchesIsFalseForADisjointGap(): void
    {
        $user = $this->newUser();
        $a = new UserAvailabilityPeriod($user, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-15'));
        $b = new UserAvailabilityPeriod($user, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2026-11-16'), new \DateTimeImmutable('2026-11-20'));

        self::assertFalse($a->overlapsOrTouches($b));
    }
}
