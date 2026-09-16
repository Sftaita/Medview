<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\AuthenticationTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PersonalCalendarControllerTest extends WebTestCase
{
    use AuthenticationTestHelpers;

    private function authHeader(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];
    }

    public function testListWithoutTokenReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/me/calendar');

        self::assertResponseStatusCodeSame(401);
    }

    public function testCreateUnavailablePeriodReturns201(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'calendar.create@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'calendar.create@example.com', 'correct-horse-battery');

        $client->request('POST', '/api/me/calendar', server: $this->authHeader($token), content: json_encode([
            'type' => 'UNAVAILABLE',
            'startsAt' => '2026-11-10T08:00:00+01:00',
            'endsAt' => '2026-11-11T08:00:00+01:00',
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('UNAVAILABLE', $data['type']);
        self::assertArrayHasKey('stableId', $data);
    }

    public function testCreateWithInvalidOrderingReturns422(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'calendar.invalid@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'calendar.invalid@example.com', 'correct-horse-battery');

        $client->request('POST', '/api/me/calendar', server: $this->authHeader($token), content: json_encode([
            'type' => 'UNAVAILABLE',
            'startsAt' => '2026-11-11T08:00:00+01:00',
            'endsAt' => '2026-11-10T08:00:00+01:00',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateWithMissingTypeReturns422(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'calendar.missingtype@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'calendar.missingtype@example.com', 'correct-horse-battery');

        $client->request('POST', '/api/me/calendar', server: $this->authHeader($token), content: json_encode([
            'startsAt' => '2026-11-10T08:00:00+01:00',
            'endsAt' => '2026-11-11T08:00:00+01:00',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testOverlappingSamePeriodReturns409(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'calendar.overlap@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'calendar.overlap@example.com', 'correct-horse-battery');

        $client->request('POST', '/api/me/calendar', server: $this->authHeader($token), content: json_encode([
            'type' => 'UNAVAILABLE',
            'startsAt' => '2026-11-10T08:00:00+01:00',
            'endsAt' => '2026-11-15T08:00:00+01:00',
        ]));
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/me/calendar', server: $this->authHeader($token), content: json_encode([
            'type' => 'UNAVAILABLE',
            'startsAt' => '2026-11-12T08:00:00+01:00',
            'endsAt' => '2026-11-20T08:00:00+01:00',
        ]));

        self::assertResponseStatusCodeSame(409);
    }

    public function testListOnlyReturnsOwnPeriods(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'calendar.owner@example.com', 'correct-horse-battery');
        $ownerToken = $this->loginUser($client, 'calendar.owner@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'calendar.other@example.com', 'correct-horse-battery');
        $otherToken = $this->loginUser($client, 'calendar.other@example.com', 'correct-horse-battery');

        $client->request('POST', '/api/me/calendar', server: $this->authHeader($ownerToken), content: json_encode([
            'type' => 'UNAVAILABLE',
            'startsAt' => '2026-11-10T08:00:00+01:00',
            'endsAt' => '2026-11-11T08:00:00+01:00',
        ]));

        $client->request('GET', '/api/me/calendar', server: $this->authHeader($otherToken));
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true));

        $client->request('GET', '/api/me/calendar', server: $this->authHeader($ownerToken));
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
    }

    public function testUpdateOwnPeriod(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'calendar.update@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'calendar.update@example.com', 'correct-horse-battery');

        $client->request('POST', '/api/me/calendar', server: $this->authHeader($token), content: json_encode([
            'type' => 'UNAVAILABLE',
            'startsAt' => '2026-11-10T08:00:00+01:00',
            'endsAt' => '2026-11-11T08:00:00+01:00',
        ]));
        $stableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];

        $client->request('PATCH', '/api/me/calendar/'.$stableId, server: $this->authHeader($token), content: json_encode([
            'type' => 'PREFER_DUTY',
            'startsAt' => '2026-12-01T00:00:00+01:00',
            'endsAt' => '2026-12-02T00:00:00+01:00',
        ]));

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('PREFER_DUTY', $data['type']);
    }

    public function testUpdatingAnotherUsersPeriodReturns404(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'calendar.victim@example.com', 'correct-horse-battery');
        $victimToken = $this->loginUser($client, 'calendar.victim@example.com', 'correct-horse-battery');
        $this->registerUser($client, 'calendar.attacker@example.com', 'correct-horse-battery');
        $attackerToken = $this->loginUser($client, 'calendar.attacker@example.com', 'correct-horse-battery');

        $client->request('POST', '/api/me/calendar', server: $this->authHeader($victimToken), content: json_encode([
            'type' => 'UNAVAILABLE',
            'startsAt' => '2026-11-10T08:00:00+01:00',
            'endsAt' => '2026-11-11T08:00:00+01:00',
        ]));
        $stableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];

        $client->request('PATCH', '/api/me/calendar/'.$stableId, server: $this->authHeader($attackerToken), content: json_encode([
            'type' => 'UNAVAILABLE',
            'startsAt' => '2026-12-01T00:00:00+01:00',
            'endsAt' => '2026-12-02T00:00:00+01:00',
        ]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteOwnPeriod(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'calendar.delete@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'calendar.delete@example.com', 'correct-horse-battery');

        $client->request('POST', '/api/me/calendar', server: $this->authHeader($token), content: json_encode([
            'type' => 'UNAVAILABLE',
            'startsAt' => '2026-11-10T08:00:00+01:00',
            'endsAt' => '2026-11-11T08:00:00+01:00',
        ]));
        $stableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];

        $client->request('DELETE', '/api/me/calendar/'.$stableId, server: $this->authHeader($token));
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/me/calendar', server: $this->authHeader($token));
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true));
    }
}
