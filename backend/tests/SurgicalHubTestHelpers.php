<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * SurgicalHub association helpers (docs/surgicalhub-integration.md). The
 * inbound secret is the one whose SHA-256 is configured in .env.test.
 */
trait SurgicalHubTestHelpers
{
    private const SURGICALHUB_INBOUND_SECRET = 'test-surgicalhub-to-medvue-secret-0123456789';

    /**
     * @return array<string, string>
     */
    private function integrationHeaders(string $secret = self::SURGICALHUB_INBOUND_SECRET, ?string $ip = null): array
    {
        // The redeem endpoint is rate-limited by client IP: a random one per call
        // keeps tests from limiting each other (see AuthenticationTestHelpers).
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip ?? $this->randomTestIp()];
    }

    /**
     * @return array<string, string>
     */
    private function userHeaders(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];
    }

    /** POST /api/me/surgicalhub/link-code as $token's owner; returns the displayed code. */
    private function issueLinkCode(KernelBrowser $client, string $token): string
    {
        $client->request('POST', '/api/me/surgicalhub/link-code', server: $this->userHeaders($token));
        self::assertResponseStatusCodeSame(201);

        return json_decode((string) $client->getResponse()->getContent(), true)['code'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function redeemLinkCode(KernelBrowser $client, string $code, string $surgicalHubUserId = '4812', array $overrides = [], ?string $ip = null): void
    {
        $client->request('POST', '/api/integrations/surgicalhub/v1/link-codes/redeem', server: $this->integrationHeaders(ip: $ip), content: json_encode(array_merge([
            'code' => $code,
            'surgicalHubUserId' => $surgicalHubUserId,
            'surgicalHubDisplayName' => 'Dr Jeanne Martin',
            'actorDisplayName' => 'Dr Jeanne Martin',
            'actorIsAdministrator' => false,
        ], $overrides)));
    }

    /** Registers, logs in, links to $surgicalHubUserId; returns the access token and the linkId. */
    private function linkedUser(KernelBrowser $client, string $email, string $surgicalHubUserId): array
    {
        $this->registerUser($client, $email, 'correct-horse-battery');
        $token = $this->loginUser($client, $email, 'correct-horse-battery');
        $this->redeemLinkCode($client, $this->issueLinkCode($client, $token), $surgicalHubUserId);
        self::assertResponseStatusCodeSame(200);

        return [$token, json_decode((string) $client->getResponse()->getContent(), true)['linkId']];
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
