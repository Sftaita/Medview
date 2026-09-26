<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * HTTP + email helpers for the "forgot password" flow
 * (docs/authentication.md §16). Expects the using class to be a
 * WebTestCase using MailerAssertionsTrait (bundled by WebTestCase).
 */
trait PasswordResetTestHelpers
{
    use AuthenticationTestHelpers;

    private function requestPasswordReset(KernelBrowser $client, string $email, ?string $ip = null): void
    {
        $client->request(
            'POST',
            '/api/password-reset/request',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip ?? $this->randomTestIp()],
            content: json_encode(['email' => $email]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmPasswordReset(KernelBrowser $client, string $rawToken, string $newPassword): array
    {
        $client->request(
            'POST',
            '/api/password-reset/confirm',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomTestIp()],
            content: json_encode(['token' => $rawToken, 'newPassword' => $newPassword]),
        );

        return json_decode((string) $client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * The raw token, read from the reset email at $messageIndex — the only
     * place it ever exists (URL fragment, never a query string).
     */
    private function tokenFromResetEmail(int $messageIndex = 0): string
    {
        $email = self::getMailerMessage($messageIndex);
        self::assertNotNull($email, 'A password reset email should have been sent.');
        self::assertSame(1, preg_match('/reset-password#token=([0-9a-f]{64})/', (string) $email->getHtmlBody(), $matches));

        return $matches[1];
    }
}
