<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PlanningPublication;
use App\Entity\PlanningPublicationNotification;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Which email a notification is (docs/decisions.md D196): decided per
 * recipient, including for the rows recorded before L4c.
 */
final class PlanningPublicationNotificationTest extends TestCase
{
    private const CHANGE = ['kind' => 'added', 'line' => 'Seniors', 'dutyType' => 'Garde', 'block' => null, 'dates' => ['2027-01-05']];

    private function notification(?array $changes, ?array $firstLines): PlanningPublicationNotification
    {
        return new PlanningPublicationNotification($this->createStub(PlanningPublication::class), $this->createStub(User::class), $changes, new \DateTimeImmutable(), $firstLines);
    }

    public function testTheLinesFirstPublishedForSomeoneMakeItAFirstPublicationEmail(): void
    {
        self::assertTrue($this->notification(null, ['Juniors'])->isFirstPublicationEmail());
        self::assertTrue($this->notification([self::CHANGE], ['Juniors'])->isFirstPublicationEmail(), 'with their changes elsewhere, in the same email');
    }

    public function testOnlyChangesMakeARepublicationEmail(): void
    {
        $notification = $this->notification([self::CHANGE], null);

        self::assertFalse($notification->isFirstPublicationEmail());
        self::assertNull($notification->getFirstLines());
    }

    public function testARowRecordedBeforeL4cKeepsItsMeaning(): void
    {
        // Before L4c, a first publication's notification had no changes and no first_lines column.
        self::assertTrue($this->notification(null, null)->isFirstPublicationEmail());
    }
}
