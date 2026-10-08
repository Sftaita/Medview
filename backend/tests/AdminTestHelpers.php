<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Accounts for the platform administration tests (docs/admin.md). Accounts
 * are created through the real registration endpoint; the global role is
 * set with a plain UPDATE — exactly what no request body can do — so the
 * tests never depend on the grant endpoint they are testing.
 */
trait AdminTestHelpers
{
    use AuthenticationTestHelpers;

    private const ADMIN_PASSWORD = 'admin-password-123';

    /**
     * @return array{email: string, token: string, stableId: string}
     */
    private function account(KernelBrowser $client, string $prefix, bool $platformAdmin = false, string $password = self::ADMIN_PASSWORD): array
    {
        $email = \sprintf('%s-%s@example.test', $prefix, bin2hex(random_bytes(4)));
        $this->registerUser($client, $email, $password);
        $stableId = json_decode((string) $client->getResponse()->getContent(), true)['stableId'];

        if ($platformAdmin) {
            $this->setPlatformAdmin($email, true);
        }

        return ['email' => $email, 'token' => $this->loginUser($client, $email, $password), 'stableId' => $stableId];
    }

    private function setPlatformAdmin(string $email, bool $value): void
    {
        $this->connection()->executeStatement('UPDATE users SET platform_admin = :value WHERE email = :email', ['value' => $value, 'email' => $email], ['value' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>|null
     */
    private function api(KernelBrowser $client, string $method, string $uri, ?string $token, ?array $body = null): ?array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.9.9.9'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $client->request($method, $uri, server: $server, content: null === $body ? '' : (string) json_encode($body));

        return json_decode((string) $client->getResponse()->getContent(), true);
    }
}
