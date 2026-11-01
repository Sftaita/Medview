<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\TeamMemberRole;
use App\Repository\UserRepository;
use App\Service\PlanningTeamMembershipService;
use App\Tests\AuthenticationTestHelpers;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * D219 — two periods that overlap or touch are a business conflict (409)
 * whatever offset each instant was sent with (Z, +01:00, +02:00, across the
 * Europe/Brussels DST changes), never a 500 from the exclusion constraint,
 * and never a false conflict either. The bounds are semi-open
 * [startsAt, endsAt[ instants; touching (one's end = the other's start)
 * counts as a conflict for two periods of the same type, as the
 * '[]'-bounded tstzrange constraint says.
 */
final class CalendarOverlapOffsetTest extends WebTestCase
{
    use AuthenticationTestHelpers;
    use PlanningDomainTestHelpers;

    private const PASSWORD = 'correct-horse-battery';

    /**
     * @return iterable<string, array{0: array{string, string}, 1: array{string, string}, 2: int}>
     */
    public static function cases(): iterable
    {
        // The anomaly seen in the rehearsal: Brussels day 12 Jan sent in UTC, day 13 sent in +01:00.
        yield 'next local day in +01:00 touches a day sent in UTC' => [['2027-01-11T23:00:00Z', '2027-01-12T23:00:00Z'], ['2027-01-13T00:00:00+01:00', '2027-01-14T00:00:00+01:00'], 409];
        yield 'previous local day in +01:00 touches a day sent in UTC' => [['2027-01-11T23:00:00Z', '2027-01-12T23:00:00Z'], ['2027-01-11T00:00:00+01:00', '2027-01-12T00:00:00+01:00'], 409];
        yield 'a real overlap written in +01:00 is seen (was a 500)' => [['2027-02-20T00:00:00Z', '2027-02-21T00:00:00Z'], ['2027-02-21T00:30:00+01:00', '2027-02-22T00:00:00+01:00'], 409];
        yield 'no overlap written in -01:00 is accepted (was a false 409)' => [['2027-02-10T00:00:00Z', '2027-02-11T00:00:00Z'], ['2027-02-10T23:30:00-01:00', '2027-02-12T00:00:00-01:00'], 201];
        yield 'summer day in +02:00 touched by the next one in Z' => [['2027-07-01T00:00:00+02:00', '2027-07-02T00:00:00+02:00'], ['2027-07-01T22:00:00Z', '2027-07-02T22:00:00Z'], 409];
        yield 'one second apart across offsets is not touching' => [['2027-07-01T00:00:00+02:00', '2027-07-02T00:00:00+02:00'], ['2027-07-01T22:00:01Z', '2027-07-02T22:00:00Z'], 201];
        // Spring forward, 28 March 2027: the local day lasts 23 hours (00:00+01:00 → 00:00+02:00).
        yield 'DST spring: the day after the 23-hour day touches it' => [['2027-03-28T00:00:00+01:00', '2027-03-29T00:00:00+02:00'], ['2027-03-29T00:00:00+02:00', '2027-03-30T00:00:00+02:00'], 409];
        yield 'DST spring: the day before the 23-hour day touches it' => [['2027-03-28T00:00:00+01:00', '2027-03-29T00:00:00+02:00'], ['2027-03-27T00:00:00+01:00', '2027-03-28T00:00:00+01:00'], 409];
        yield 'DST spring: the 23-hour day in UTC is the same day' => [['2027-03-28T00:00:00+01:00', '2027-03-29T00:00:00+02:00'], ['2027-03-27T23:00:00Z', '2027-03-28T22:00:00Z'], 409];
        yield 'DST spring: two days later is free' => [['2027-03-28T00:00:00+01:00', '2027-03-29T00:00:00+02:00'], ['2027-03-30T00:00:00+02:00', '2027-03-31T00:00:00+02:00'], 201];
        // Fall back, 31 October 2027: the local day lasts 25 hours (00:00+02:00 → 00:00+01:00).
        yield 'DST autumn: the day after the 25-hour day touches it' => [['2027-10-31T00:00:00+02:00', '2027-11-01T00:00:00+01:00'], ['2027-11-01T00:00:00+01:00', '2027-11-02T00:00:00+01:00'], 409];
        yield 'DST autumn: the day before, sent in Z, touches it' => [['2027-10-31T00:00:00+02:00', '2027-11-01T00:00:00+01:00'], ['2027-10-29T22:00:00Z', '2027-10-30T22:00:00Z'], 409];
        yield 'DST autumn: the last hour of the 25-hour day overlaps' => [['2027-10-31T00:00:00+02:00', '2027-11-01T00:00:00+01:00'], ['2027-10-31T23:30:00+01:00', '2027-11-01T12:00:00+01:00'], 409];
        yield 'DST autumn: two days later is free' => [['2027-10-31T00:00:00+02:00', '2027-11-01T00:00:00+01:00'], ['2027-11-02T00:00:00+01:00', '2027-11-03T00:00:00+01:00'], 201];
    }

    /**
     * @param array{string, string} $existing
     * @param array{string, string} $candidate
     */
    #[DataProvider('cases')]
    public function testCreatingInThePersonalCalendar(array $existing, array $candidate, int $expected): void
    {
        $client = static::createClient();
        $token = $this->newUser($client, 'create');

        self::assertSame(201, $this->write($client, $token, 'POST', '/api/me/calendar', 'UNAVAILABLE', $existing));
        self::assertSame($expected, $this->write($client, $token, 'POST', '/api/me/calendar', 'UNAVAILABLE', $candidate));
        if (409 === $expected) {
            self::assertSame('overlapping_period', $this->body($client)['error']);
        }
        self::assertCount(201 === $expected ? 2 : 1, $this->calendar($client, $token));
    }

    /**
     * @param array{string, string} $existing
     * @param array{string, string} $candidate
     */
    #[DataProvider('cases')]
    public function testMovingAPeriodOfThePersonalCalendar(array $existing, array $candidate, int $expected): void
    {
        $client = static::createClient();
        $token = $this->newUser($client, 'move');

        self::assertSame(201, $this->write($client, $token, 'POST', '/api/me/calendar', 'UNAVAILABLE', $existing));
        self::assertSame(201, $this->write($client, $token, 'POST', '/api/me/calendar', 'UNAVAILABLE', ['2028-06-01T00:00:00+02:00', '2028-06-02T00:00:00+02:00']));
        $moved = $this->body($client)['stableId'];

        $status = $this->write($client, $token, 'PATCH', '/api/me/calendar/'.$moved, 'UNAVAILABLE', $candidate);
        self::assertSame(201 === $expected ? 200 : 409, $status);

        // A refused move leaves the period where it was.
        $byId = array_column($this->calendar($client, $token), null, 'stableId');
        $starts = new \DateTimeImmutable($byId[$moved]['startsAt']);
        self::assertEquals(new \DateTimeImmutable(201 === $expected ? $candidate[0] : '2028-06-01T00:00:00+02:00'), $starts);
    }

    public function testAPreferenceMayStillCoverAnUnavailableDayWhateverTheOffsets(): void
    {
        $client = static::createClient();
        $token = $this->newUser($client, 'types');

        self::assertSame(201, $this->write($client, $token, 'POST', '/api/me/calendar', 'UNAVAILABLE', ['2027-03-28T00:00:00+01:00', '2027-03-29T00:00:00+02:00']));
        self::assertSame(201, $this->write($client, $token, 'POST', '/api/me/calendar', 'PREFER_DUTY', ['2027-03-27T23:00:00Z', '2027-03-28T22:00:00Z']));
    }

    public function testTheInstantsSentAreAnsweredAsBefore(): void
    {
        $client = static::createClient();
        $token = $this->newUser($client, 'echo');

        self::assertSame(201, $this->write($client, $token, 'POST', '/api/me/calendar', 'UNAVAILABLE', ['2027-03-28T00:00:00+01:00', '2027-03-29T00:00:00+02:00']));
        self::assertSame('2027-03-28T00:00:00+01:00', $this->body($client)['startsAt']);
        self::assertSame('2027-03-29T00:00:00+02:00', $this->body($client)['endsAt']);

        // Read back, the same instants (in the UTC of the database session).
        $period = $this->calendar($client, $token)[0];
        self::assertSame('2027-03-27T23:00:00+00:00', $period['startsAt']);
        self::assertSame('2027-03-28T22:00:00+00:00', $period['endsAt']);
    }

    /**
     * @param array{string, string} $existing
     * @param array{string, string} $candidate
     */
    #[DataProvider('cases')]
    public function testAdministrativeNonParticipation(array $existing, array $candidate, int $expected): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $users = $container->get(UserRepository::class);
        $memberships = $container->get(PlanningTeamMembershipService::class);
        $email = 'np.offset.'.substr(md5((string) $this->dataName()), 0, 12).'@example.com';
        $this->registerUser($client, $email, self::PASSWORD);
        $token = $this->loginUser($client, $email, self::PASSWORD);
        $team = $this->createTeam($em);
        $member = $memberships->addMember($team, $users->findOneByEmail($email), TeamMemberRole::OWNER, $this->date('2026-01-01'));
        $url = \sprintf(
            '/api/plannings/%s/teams/%s/members/%s/non-participation',
            $team->getPlanning()->getStableId(),
            $team->getStableId(),
            $member->getStableId(),
        );

        self::assertSame(201, $this->write($client, $token, 'POST', $url, null, $existing));
        self::assertSame($expected, $this->write($client, $token, 'POST', $url, null, $candidate));
    }

    private function newUser(KernelBrowser $client, string $kind): string
    {
        $email = 'offset.'.$kind.'.'.substr(md5((string) $this->dataName()), 0, 12).'@example.com';
        $this->registerUser($client, $email, self::PASSWORD);

        return $this->loginUser($client, $email, self::PASSWORD);
    }

    /**
     * @param array{string, string} $range
     */
    private function write(KernelBrowser $client, string $token, string $method, string $url, ?string $type, array $range): int
    {
        $payload = ['startsAt' => $range[0], 'endsAt' => $range[1]];
        if (null !== $type) {
            $payload['type'] = $type;
        }
        $client->request($method, $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'], content: json_encode($payload));

        return $client->getResponse()->getStatusCode();
    }

    /** @return array<string, mixed> */
    private function body(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    /** @return list<array<string, mixed>> */
    private function calendar(KernelBrowser $client, string $token): array
    {
        $client->request('GET', '/api/me/calendar', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $data = $this->body($client);

        return $data['periods'] ?? $data;
    }
}
