<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\RefreshToken;
use App\Repository\RefreshTokenRepository;
use App\Repository\UserRepository;
use App\Tests\PasswordResetTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /api/password-reset/request and POST /api/password-reset/confirm
 * (docs/authentication.md §16, docs/decisions.md D141/D142).
 */
final class PasswordResetControllerTest extends WebTestCase
{
    use PasswordResetTestHelpers;

    private const GENERIC_MESSAGE = 'Si un compte correspond à cette adresse, un email de réinitialisation a été envoyé.';

    // --- Request: identical public response regardless of account state ---

    public function testRequestForAnExistingAccountReturnsTheGenericResponse(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'reset.exists@example.com', 'correct-horse-battery');

        $this->requestPasswordReset($client, 'reset.exists@example.com');

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['success' => true, 'message' => self::GENERIC_MESSAGE], $data);
    }

    public function testRequestForAnUnknownEmailReturnsTheExactSameResponse(): void
    {
        $client = static::createClient();

        $this->requestPasswordReset($client, 'reset.unknown@example.com');

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['success' => true, 'message' => self::GENERIC_MESSAGE], $data);
        self::assertEmailCount(0, message: 'No account: no email sent, but the HTTP response must not say so.');
    }

    public function testRequestForADeactivatedAccountReturnsTheExactSameResponse(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'reset.disabled@example.com', 'correct-horse-battery');
        $container = static::getContainer();
        $user = $container->get(UserRepository::class)->findOneByEmail('reset.disabled@example.com');
        self::assertNotNull($user);
        $user->setActive(false);
        $container->get(EntityManagerInterface::class)->flush();

        $this->requestPasswordReset($client, 'reset.disabled@example.com');

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['success' => true, 'message' => self::GENERIC_MESSAGE], $data);
        self::assertEmailCount(0, message: 'A deactivated account must not receive a working reset link.');
    }

    // --- Request: token issuance ---

    public function testRequestForAnExistingActiveAccountIssuesAUsableTokenAndSendsOneEmail(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'reset.issues@example.com', 'correct-horse-battery');

        $this->requestPasswordReset($client, 'reset.issues@example.com');

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertSame('Réinitialisation de votre mot de passe MedVue', $email->getSubject());
        self::assertEmailAddressContains($email, 'to', 'reset.issues@example.com');
        $token = $this->tokenFromResetEmail();
        self::assertSame(64, \strlen($token));
    }

    public function testTheRawTokenIsNeverStoredInTheDatabase(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'reset.rawtoken@example.com', 'correct-horse-battery');
        $this->requestPasswordReset($client, 'reset.rawtoken@example.com');
        $token = $this->tokenFromResetEmail();

        $connection = static::getContainer()->get(Connection::class);
        $row = $connection->fetchAssociative('SELECT * FROM password_reset_tokens');
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $token), $row['token_hash']);
        foreach ($row as $column => $value) {
            self::assertStringNotContainsString($token, (string) $value, "Column {$column} must not contain the raw token.");
        }
    }

    public function testTokenExpiryMatchesTheConfiguredTtl(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'reset.ttl@example.com', 'correct-horse-battery');

        $this->requestPasswordReset($client, 'reset.ttl@example.com');

        $connection = static::getContainer()->get(Connection::class);
        $row = $connection->fetchAssociative('SELECT created_at, expires_at FROM password_reset_tokens');
        self::assertIsArray($row);
        $createdAt = new \DateTimeImmutable((string) $row['created_at']);
        $expiresAt = new \DateTimeImmutable((string) $row['expires_at']);
        // created_at is a TIMESTAMP(0) column (no fractional seconds), so it
        // can legitimately be a fraction of a second before "now" was read
        // here — only the TTL delta between the two columns is asserted.
        // PASSWORD_RESET_TOKEN_TTL=1800 (30 minutes) in backend/.env.
        self::assertSame(1800, $expiresAt->getTimestamp() - $createdAt->getTimestamp());
    }

    public function testANewRequestSupersedesThePreviouslyIssuedToken(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('reset.supersede');
        $this->registerUser($client, $email, 'correct-horse-battery');

        $this->requestPasswordReset($client, $email);
        $oldToken = $this->tokenFromResetEmail();

        // KernelBrowser reboots the kernel before every request after the
        // first, which resets the mailer message collector — so the second
        // email is index 0 of the *new* container, not index 1.
        $this->requestPasswordReset($client, $email);
        $newToken = $this->tokenFromResetEmail();

        self::assertNotSame($oldToken, $newToken);

        // The old token is now unusable...
        $result = $this->confirmPasswordReset($client, $oldToken, 'brand-new-password-1');
        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame('invalid_or_expired_token', $result['error']);

        // ...only the newest one is.
        $result = $this->confirmPasswordReset($client, $newToken, 'brand-new-password-1');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertTrue($result['success']);
    }

    // --- Request: input validation ---

    public function testRequestWithInvalidJsonIsRejected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/password-reset/request', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomTestIp()], content: 'not json');

        self::assertResponseStatusCodeSame(400);
    }

    public function testRequestWithAMalformedEmailIsRejected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/password-reset/request', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomTestIp()], content: json_encode(['email' => 'not-an-email']));

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('validation_failed', $data['error']);
        self::assertArrayHasKey('email', $data['violations']);
    }

    public function testRequestRejectsUnknownFields(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/password-reset/request', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomTestIp()], content: json_encode(['email' => 'x@example.com', 'userId' => 1]));

        self::assertResponseStatusCodeSame(422);
    }

    // --- Request: rate limiting ---

    public function testRequestIsRateLimitedByIp(): void
    {
        $client = static::createClient();
        $ip = $this->randomTestIp();

        // password_reset_request_ip: 5 / hour (config/packages/rate_limiter.yaml).
        for ($i = 0; $i < 5; ++$i) {
            $this->requestPasswordReset($client, "reset.iplimit{$i}@example.com", $ip);
            self::assertResponseStatusCodeSame(200);
        }

        $this->requestPasswordReset($client, 'reset.iplimit.over@example.com', $ip);
        self::assertResponseStatusCodeSame(429);
        self::assertNotEmpty($client->getResponse()->headers->get('Retry-After'));
    }

    public function testRequestIsRateLimitedByEmailRegardlessOfWhetherTheAccountExists(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('reset.emaillimit');

        // password_reset_request_email: 3 / hour, consumed unconditionally
        // (config/packages/rate_limiter.yaml) — a different IP each time so
        // only the email-keyed limiter can be the one tripping.
        for ($i = 0; $i < 3; ++$i) {
            $this->requestPasswordReset($client, $email);
            self::assertResponseStatusCodeSame(200);
        }

        $this->requestPasswordReset($client, $email);
        self::assertResponseStatusCodeSame(429, 'Even for an unknown email, the per-email limiter must trip — never a usable oracle.');
    }

    // --- Confirm: rejection reasons, always generic ---

    public function testConfirmWithAnUnknownTokenIsRejectedGenerically(): void
    {
        $client = static::createClient();

        $result = $this->confirmPasswordReset($client, bin2hex(random_bytes(32)), 'brand-new-password-1');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame('invalid_or_expired_token', $result['error']);
        self::assertSame('Ce lien est invalide ou a expiré.', $result['message']);
    }

    public function testConfirmWithAnExpiredTokenIsRejected(): void
    {
        [$client, $token] = $this->issuedToken('reset.expired@example.com');
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE password_reset_tokens SET created_at = :c, expires_at = :e',
            ['c' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), 'e' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')],
        );

        $result = $this->confirmPasswordReset($client, $token, 'brand-new-password-1');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame('invalid_or_expired_token', $result['error']);
    }

    public function testConfirmWithAnAlreadyConsumedTokenIsRejected(): void
    {
        [$client, $token] = $this->issuedToken('reset.usedtwice@example.com');
        $first = $this->confirmPasswordReset($client, $token, 'brand-new-password-1');
        self::assertTrue($first['success']);

        $result = $this->confirmPasswordReset($client, $token, 'another-password-2');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame('invalid_or_expired_token', $result['error']);
    }

    public function testConfirmWithARevokedSupersededTokenIsRejected(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail('reset.superseded');
        $this->registerUser($client, $email, 'correct-horse-battery');
        $this->requestPasswordReset($client, $email);
        $oldToken = $this->tokenFromResetEmail(0);
        $this->requestPasswordReset($client, $email);

        $result = $this->confirmPasswordReset($client, $oldToken, 'brand-new-password-1');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame('invalid_or_expired_token', $result['error']);
    }

    public function testConfirmForADeactivatedAccountIsRejected(): void
    {
        [$client, $token] = $this->issuedToken('reset.deactivatedbeforeconfirm@example.com');
        $container = static::getContainer();
        $user = $container->get(UserRepository::class)->findOneByEmail('reset.deactivatedbeforeconfirm@example.com');
        self::assertNotNull($user);
        $user->setActive(false);
        $container->get(EntityManagerInterface::class)->flush();

        $result = $this->confirmPasswordReset($client, $token, 'brand-new-password-1');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame('invalid_or_expired_token', $result['error']);
    }

    public function testConfirmWithAnInvalidPasswordIsRejectedWithoutConsumingTheToken(): void
    {
        [$client, $token] = $this->issuedToken('reset.badpassword@example.com');

        $result = $this->confirmPasswordReset($client, $token, 'short');

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('validation_failed', $result['error']);

        // The token must still be usable: a rejected password never consumes it.
        $second = $this->confirmPasswordReset($client, $token, 'a-perfectly-fine-password-1');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertTrue($second['success']);
    }

    public function testConfirmRejectsUnknownFields(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/password-reset/confirm', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomTestIp()], content: json_encode(['token' => 'x', 'newPassword' => 'long-enough-1', 'extra' => true]));

        self::assertResponseStatusCodeSame(422);
    }

    // --- Confirm: consuming a "same token twice" is proven sequentially
    //     (see PasswordResetServiceTest for the documented rationale —
    //     same approach as UserRegistrationServiceTest's registration race). ---

    public function testConfirmConsumesTheTokenAndInvalidatesOtherOutstandingTokensForTheUser(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'reset.multi@example.com', 'correct-horse-battery');
        // Two live requests for the same user (the second supersedes the
        // first per requestReset()'s own invariant) — reaching straight into
        // the DB to fabricate a second *simultaneously usable* token, the
        // one scenario requestReset() itself is specifically designed to
        // prevent, so confirmReset()'s own "invalidate the rest" step has
        // something real to invalidate.
        $this->requestPasswordReset($client, 'reset.multi@example.com');
        $tokenA = $this->tokenFromResetEmail(0);
        $connection = static::getContainer()->get(Connection::class);
        $userId = (int) $connection->fetchOne("SELECT id FROM users WHERE email = 'reset.multi@example.com'");
        $rawTokenB = bin2hex(random_bytes(32));
        $connection->insert('password_reset_tokens', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $rawTokenB),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'expires_at' => (new \DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s'),
        ]);

        $result = $this->confirmPasswordReset($client, $tokenA, 'brand-new-password-1');
        self::assertTrue($result['success']);

        $rows = $connection->fetchAllAssociative('SELECT consumed_at, revoked_at FROM password_reset_tokens WHERE user_id = ?', [$userId]);
        self::assertCount(2, $rows);
        $consumedCount = 0;
        $revokedCount = 0;
        foreach ($rows as $row) {
            $consumedCount += null !== $row['consumed_at'] ? 1 : 0;
            $revokedCount += null !== $row['revoked_at'] ? 1 : 0;
        }
        self::assertSame(1, $consumedCount, 'Exactly the confirmed token is consumed.');
        self::assertSame(1, $revokedCount, 'The other outstanding token is revoked, not left usable.');
    }

    // --- End-to-end: password change, session invalidation, JWT invalidation ---

    public function testFullResetFlowChangesThePasswordAndRejectsTheOldOne(): void
    {
        [$client, $token] = $this->issuedToken('reset.e2e@example.com', 'old-password-123');

        $result = $this->confirmPasswordReset($client, $token, 'new-password-456');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertTrue($result['success']);

        $client->request('POST', '/api/login', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomTestIp()], content: json_encode(['email' => 'reset.e2e@example.com', 'password' => 'old-password-123']));
        self::assertResponseStatusCodeSame(401, 'The old password must no longer work.');

        $client->request('POST', '/api/login', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomTestIp()], content: json_encode(['email' => 'reset.e2e@example.com', 'password' => 'new-password-456']));
        self::assertResponseStatusCodeSame(200, 'The new password must work.');
    }

    public function testFullResetFlowSendsAChangedPasswordAlertAsASecondEmail(): void
    {
        [$client, $token] = $this->issuedToken('reset.alert@example.com');

        $this->confirmPasswordReset($client, $token, 'brand-new-password-1');

        // KernelBrowser reboots the kernel before every request after the
        // first, which resets the mailer message collector — the alert is
        // the only message visible in *this* request's container, not the
        // second of two accumulated ones.
        self::assertEmailCount(1, message: 'The changed-password alert, distinct from the earlier reset-link email.');
        $alert = self::getMailerMessage(0);
        self::assertSame('Votre mot de passe MedVue a été modifié', $alert->getSubject());
        self::assertEmailAddressContains($alert, 'to', 'reset.alert@example.com');
        self::assertStringNotContainsString($token, (string) $alert->getHtmlBody(), 'The alert email must never contain a reset token.');
    }

    public function testFullResetFlowRevokesEveryRefreshTokenFamilyOfTheUser(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'reset.sessions@example.com', 'old-password-123');
        $this->loginUser($client, 'reset.sessions@example.com', 'old-password-123');

        $container = static::getContainer();
        $user = $container->get(UserRepository::class)->findOneByEmail('reset.sessions@example.com');
        self::assertNotNull($user);

        // A second "device": its own family, never touched by this
        // browser's cookie (same fixture approach as
        // RefreshTokenControllerTest::persistRefreshToken — a second real
        // KernelBrowser client can't coexist with the first, see
        // WebTestCase::createClient()'s single-kernel-per-test constraint).
        $otherDeviceRawToken = 'other-device-raw-token';
        $em = $container->get(EntityManagerInterface::class);
        $otherDeviceToken = new RefreshToken(
            user: $user,
            tokenHash: hash('sha256', $otherDeviceRawToken),
            familyId: bin2hex(random_bytes(16)),
            expiresAt: new \DateTimeImmutable('+30 days'),
            createdByIp: '198.51.100.9',
            userAgent: 'other-device',
        );
        $em->persist($otherDeviceToken);
        $em->flush();

        $this->requestPasswordReset($client, 'reset.sessions@example.com');
        $token = $this->tokenFromResetEmail();
        $this->confirmPasswordReset($client, $token, 'new-password-456');

        $tokens = $container->get(RefreshTokenRepository::class)->findBy(['user' => $user]);
        self::assertGreaterThanOrEqual(2, \count($tokens), 'Both this browser\'s family and the other device\'s.');
        foreach ($tokens as $refreshToken) {
            self::assertTrue($refreshToken->isRevoked(), 'Every refresh token of the user, across every device, must be revoked.');
        }

        // The other device's session is dead too, not just this browser's.
        $this->setRefreshCookieValue($client, $otherDeviceRawToken);
        $client->request('POST', '/api/token/refresh');
        self::assertResponseStatusCodeSame(401, 'The other device\'s refresh session must be dead too.');
    }

    public function testFullResetFlowInvalidatesAPreviouslyIssuedJwtImmediately(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'reset.jwt@example.com', 'old-password-123');
        $accessToken = $this->loginUser($client, 'reset.jwt@example.com', 'old-password-123');

        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken]);
        self::assertResponseStatusCodeSame(200, 'Sanity check: the token works before the reset.');

        $this->requestPasswordReset($client, 'reset.jwt@example.com');
        $token = $this->tokenFromResetEmail();
        $this->confirmPasswordReset($client, $token, 'new-password-456');

        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken]);
        self::assertResponseStatusCodeSame(401, 'A JWT issued before the reset must be rejected immediately, not just once its 15-minute TTL elapses.');
    }

    public function testFullResetFlowClearsTheRefreshCookieOnTheRespondingBrowser(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'reset.cookie@example.com', 'old-password-123');
        $this->loginUser($client, 'reset.cookie@example.com', 'old-password-123');
        self::assertNotNull($this->getRefreshCookieValue($client), 'Sanity check: a refresh cookie exists before the reset.');

        $this->requestPasswordReset($client, 'reset.cookie@example.com');
        $token = $this->tokenFromResetEmail();
        $this->confirmPasswordReset($client, $token, 'new-password-456');

        self::assertNull($this->getRefreshCookieValue($client), 'The confirm response must clear this browser\'s own refresh cookie.');
    }

    public function testFullResetFlowDoesNotAutomaticallyLogIn(): void
    {
        [$client, $token] = $this->issuedToken('reset.noautologin@example.com');

        $result = $this->confirmPasswordReset($client, $token, 'new-password-456');

        self::assertArrayNotHasKey('token', $result, 'A reset confirmation must never hand back a JWT.');
    }

    public function testResetOfOneUserNeverAffectsAnother(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'reset.other1@example.com', 'password-one-123');
        $this->registerUser($client, 'reset.other2@example.com', 'password-two-123');

        $this->requestPasswordReset($client, 'reset.other1@example.com');
        $token = $this->tokenFromResetEmail();
        $this->confirmPasswordReset($client, $token, 'new-password-1-456');

        $client->request('POST', '/api/login', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomTestIp()], content: json_encode(['email' => 'reset.other2@example.com', 'password' => 'password-two-123']));
        self::assertResponseStatusCodeSame(200, 'The unrelated user\'s password must be untouched.');
    }

    public function testNoEndpointEverExposesThePasswordHash(): void
    {
        [$client, $token] = $this->issuedToken('reset.nohash@example.com');
        $result = $this->confirmPasswordReset($client, $token, 'new-password-456');

        self::assertArrayNotHasKey('passwordHash', $result);
        self::assertArrayNotHasKey('password', $result);
    }

    /**
     * @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: string}
     */
    private function issuedToken(string $email, string $password = 'correct-horse-battery'): array
    {
        $client = static::createClient();
        $this->registerUser($client, $email, $password);
        $this->requestPasswordReset($client, $email);

        return [$client, $this->tokenFromResetEmail()];
    }

    /**
     * The email-keyed rate limiter's cache storage is NOT reset by
     * dama/doctrine-test-bundle's per-test rollback (same reasoning as
     * AuthenticationTestHelpers::randomTestIp() for the IP-keyed one) — a
     * test that requests a reset more than once for the same address needs
     * a fresh address of its own, not a literal shared with every other
     * run of this test.
     */
    private function uniqueEmail(string $localPart): string
    {
        return \sprintf('%s+%s@example.com', $localPart, bin2hex(random_bytes(4)));
    }
}
