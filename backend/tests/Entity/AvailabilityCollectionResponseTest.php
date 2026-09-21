<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AvailabilityAcknowledgementKind;
use App\Entity\AvailabilityCollection;
use App\Entity\AvailabilityCollectionResponse;
use App\Entity\AvailabilityCollectionStatus;
use App\Entity\AvailabilityResponseStatus;
use App\Entity\Planning;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Pure entity tests (no kernel): "answered" is an explicit event, never
 * derived from anything else (docs/availability-collection.md §3).
 */
final class AvailabilityCollectionResponseTest extends TestCase
{
    private function response(): AvailabilityCollectionResponse
    {
        $user = new User('a@example.com', 'Ada', 'Lovelace', 'hash');
        $planning = new Planning('P', $user, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'), 'Europe/Brussels');
        $collection = new AvailabilityCollection($planning, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'), $user, new \DateTimeImmutable('2026-12-10 09:00:00'));

        return new AvailabilityCollectionResponse($collection, $user, new \DateTimeImmutable('2026-12-10 09:00:00'));
    }

    public function testANewResponseIsPendingAndCarriesNoEvent(): void
    {
        $response = $this->response();

        self::assertSame(AvailabilityResponseStatus::PENDING, $response->getStatus());
        self::assertNull($response->getAcknowledgedAt());
        self::assertNull($response->getAcknowledgementKind());
        self::assertNull($response->getLastAvailabilityChangeAt());
    }

    public function testAcknowledgingRecordsTheKindAndTheMoment(): void
    {
        $response = $this->response();
        $at = new \DateTimeImmutable('2026-12-14 10:00:00');

        $response->acknowledge(AvailabilityAcknowledgementKind::NO_UNAVAILABILITY, $at);

        self::assertSame(AvailabilityResponseStatus::ACKNOWLEDGED, $response->getStatus());
        self::assertSame($at, $response->getAcknowledgedAt());
        self::assertSame(AvailabilityAcknowledgementKind::NO_UNAVAILABILITY, $response->getAcknowledgementKind());
    }

    public function testAcknowledgingTwiceIsRefusedAtTheEntityLevel(): void
    {
        $response = $this->response();
        $response->acknowledge(AvailabilityAcknowledgementKind::CONFIRMED, new \DateTimeImmutable('2026-12-14'));

        $this->expectException(\LogicException::class);

        $response->acknowledge(AvailabilityAcknowledgementKind::CONFIRMED, new \DateTimeImmutable('2026-12-15'));
    }

    public function testAChangeNeverReopensAnAcknowledgedResponse(): void
    {
        $response = $this->response();
        $response->acknowledge(AvailabilityAcknowledgementKind::CONFIRMED, new \DateTimeImmutable('2026-12-14 10:00:00'));

        $response->recordAvailabilityChange(new \DateTimeImmutable('2026-12-16 08:00:00'));

        self::assertSame(AvailabilityResponseStatus::ACKNOWLEDGED, $response->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-12-14 10:00:00'), $response->getAcknowledgedAt());
        self::assertEquals(new \DateTimeImmutable('2026-12-16 08:00:00'), $response->getLastAvailabilityChangeAt());
    }

    public function testAChangeAloneNeverMakesAResponseAnswered(): void
    {
        $response = $this->response();

        $response->recordAvailabilityChange(new \DateTimeImmutable('2026-12-16 08:00:00'));

        self::assertSame(AvailabilityResponseStatus::PENDING, $response->getStatus());
    }

    public function testWithdrawnThenReinstated(): void
    {
        $response = $this->response();

        $response->withdraw(new \DateTimeImmutable('2026-12-12'));
        self::assertSame(AvailabilityResponseStatus::WITHDRAWN, $response->getStatus());

        $response->reinstate(new \DateTimeImmutable('2026-12-13'));
        self::assertSame(AvailabilityResponseStatus::PENDING, $response->getStatus());
    }

    public function testAnAcknowledgedResponseCannotBeWithdrawn(): void
    {
        $response = $this->response();
        $response->acknowledge(AvailabilityAcknowledgementKind::CONFIRMED, new \DateTimeImmutable('2026-12-14'));

        $response->withdraw(new \DateTimeImmutable('2026-12-20'));

        self::assertSame(AvailabilityResponseStatus::ACKNOWLEDGED, $response->getStatus());
        self::assertNull($response->getWithdrawnAt());
    }

    public function testClosingACollectionIsOneWayAndKeepsTheFirstClosingDate(): void
    {
        $user = new User('a@example.com', 'Ada', 'Lovelace', 'hash');
        $planning = new Planning('P', $user, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'), 'Europe/Brussels');
        $collection = new AvailabilityCollection($planning, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'), $user, new \DateTimeImmutable('2026-12-10'));

        $collection->close(new \DateTimeImmutable('2026-12-21'));
        $collection->close(new \DateTimeImmutable('2026-12-30'));

        self::assertSame(AvailabilityCollectionStatus::CLOSED, $collection->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-12-21'), $collection->getClosedAt());
    }

    public function testAWindowIsResolvedInThePlanningTimezone(): void
    {
        $user = new User('a@example.com', 'Ada', 'Lovelace', 'hash');
        $planning = new Planning('P', $user, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'), 'Europe/Brussels');
        $collection = new AvailabilityCollection($planning, new \DateTimeImmutable('2027-03-01'), new \DateTimeImmutable('2027-04-01'), $user, new \DateTimeImmutable('2026-12-10'));

        // Brussels is UTC+1 on 1 March and UTC+2 on 1 April (DST starts 28 March 2027).
        self::assertSame('2027-02-28T23:00:00+00:00', $collection->getStartsAtInstant()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        self::assertSame('2027-03-31T22:00:00+00:00', $collection->getEndsAtInstant()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
    }
}
