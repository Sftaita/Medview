<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AvailabilityCollection;
use App\Entity\AvailabilityCollectionResponse;
use App\Entity\AvailabilityCollectionStatus;
use App\Entity\AvailabilityResponseStatus;
use App\Entity\Planning;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Pure entity tests (no kernel). docs/decisions.md D214: a response only
 * says that a person takes part in a collection; nobody confirms anything.
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

    public function testANewResponseIsAParticipantAndAsksForNothing(): void
    {
        $response = $this->response();

        // docs/decisions.md D214: no "answered" state — a participant is told, never asked to confirm.
        self::assertSame(AvailabilityResponseStatus::PARTICIPANT, $response->getStatus());
        self::assertTrue($response->isParticipant());
        self::assertNull($response->getAcknowledgedAt());
        self::assertNull($response->getLastAvailabilityChangeAt());
        self::assertFalse(method_exists($response, 'acknowledge'), 'Nothing can confirm a collection any more.');
    }

    public function testAChangeOnlyRecordsWhenTheCalendarWasTouched(): void
    {
        $response = $this->response();

        $response->recordAvailabilityChange(new \DateTimeImmutable('2026-12-16 08:00:00'));

        self::assertSame(AvailabilityResponseStatus::PARTICIPANT, $response->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-12-16 08:00:00'), $response->getLastAvailabilityChangeAt());
    }

    public function testWithdrawnThenReinstated(): void
    {
        $response = $this->response();

        $response->withdraw(new \DateTimeImmutable('2026-12-12'));
        self::assertSame(AvailabilityResponseStatus::WITHDRAWN, $response->getStatus());
        self::assertFalse($response->isParticipant());

        $response->reinstate(new \DateTimeImmutable('2026-12-13'));
        self::assertSame(AvailabilityResponseStatus::PARTICIPANT, $response->getStatus());
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
