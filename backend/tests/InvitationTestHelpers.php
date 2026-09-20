<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Fixture/HTTP helpers for the registration + invitation lot
 * (docs/authentication.md §15). Everything goes through the real API, the
 * way the frontend does, so authorization and serialization are exercised
 * end to end. Expects the using class to be a WebTestCase.
 */
trait InvitationTestHelpers
{
    use AuthenticationTestHelpers;

    /**
     * @return array<string, string>
     */
    private function bearer(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function api(KernelBrowser $client, string $method, string $url, ?array $body = null, ?string $token = null, ?string $ip = null): array
    {
        $server = null !== $token ? $this->bearer($token) : ['CONTENT_TYPE' => 'application/json'];
        $server['REMOTE_ADDR'] = $ip ?? $this->randomTestIp();

        $client->request($method, $url, server: $server, content: null === $body ? null : json_encode($body));

        return json_decode((string) $client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * Registers + logs in a user; returns their access token.
     */
    private function userToken(KernelBrowser $client, string $email): string
    {
        $this->registerUser($client, $email, 'correct-horse-battery');

        return $this->loginUser($client, $email, 'correct-horse-battery');
    }

    /**
     * @return array{0: string, 1: string} [planningStableId, primaryTeamStableId]
     */
    private function createPlanningWithTeam(KernelBrowser $client, string $creatorToken, string $teamName = 'Seniors'): array
    {
        $planning = $this->api($client, 'POST', '/api/plannings', [
            'name' => 'Gardes '.$teamName,
            'startsAt' => '2027-01-01',
            'endsAt' => '2027-05-01',
            'timezone' => 'Europe/Brussels',
            'primaryTeam' => ['name' => $teamName],
        ], $creatorToken);
        self::assertResponseStatusCodeSame(201);

        $detail = $this->api($client, 'GET', '/api/plannings/'.$planning['stableId'], token: $creatorToken);

        return [$planning['stableId'], $detail['lines'][0]['team']['stableId']];
    }

    /**
     * Adds an existing user to a team with a given role, as the planning creator.
     */
    private function addMemberAs(KernelBrowser $client, string $creatorToken, string $planningStableId, string $teamStableId, string $email, string $role): void
    {
        $user = static::getContainer()->get(\App\Repository\UserRepository::class)->findOneByEmail($email);
        $this->api($client, 'POST', "/api/plannings/{$planningStableId}/teams/{$teamStableId}/members", [
            'userStableId' => (string) $user->getStableId(),
            'role' => $role,
            'membershipStart' => '2027-01-01',
        ], $creatorToken);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return array<string, mixed>
     */
    private function invite(KernelBrowser $client, string $token, string $planningStableId, string $teamStableId, string $email = 'marie@example.com', string $firstName = 'Marie', string $lastName = 'Dupont'): array
    {
        return $this->api($client, 'POST', "/api/plannings/{$planningStableId}/teams/{$teamStableId}/invitations", [
            'email' => $email,
            'firstName' => $firstName,
            'lastName' => $lastName,
        ], $token);
    }

    /**
     * The raw token, read from the invitation email the last request sent —
     * the only place it ever exists.
     */
    private function tokenFromLastEmail(): string
    {
        $email = self::getMailerMessage(0);
        self::assertNotNull($email, 'An invitation email should have been sent.');
        self::assertSame(1, preg_match('#/invitations/([0-9a-f]{64})#', (string) $email->getHtmlBody(), $matches));

        return $matches[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function registerWithToken(KernelBrowser $client, string $token, string $email, array $overrides = [], ?string $ip = null): array
    {
        return $this->api($client, 'POST', '/api/register', $this->registrationPayload($email, overrides: array_merge(['invitationToken' => $token], $overrides)), ip: $ip);
    }
}
