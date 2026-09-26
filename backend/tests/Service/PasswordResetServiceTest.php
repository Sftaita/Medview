<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Exception\InvalidPasswordResetTokenException;
use App\Repository\PasswordResetTokenRepository;
use App\Security\PasswordResetFailureReason;
use App\Service\PasswordResetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Direct, HTTP-free coverage of PasswordResetService — the pieces
 * PasswordResetControllerTest exercises end to end but that are clearer to
 * assert against the real DB state here (docs/authentication.md §16).
 *
 * Tokens are minted directly as entities (like
 * RefreshTokenControllerTest::persistRefreshToken()) rather than through
 * requestReset() + reading the mailer, since the service deliberately never
 * hands the raw value back to its caller.
 */
final class PasswordResetServiceTest extends KernelTestCase
{
    public function testRequestForAnUnknownEmailCreatesNoToken(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(PasswordResetService::class);

        $service->requestReset('nobody-'.bin2hex(random_bytes(4)).'@example.com', '203.0.113.7');

        self::assertSame(0, self::getContainer()->get(PasswordResetTokenRepository::class)->count([]));
    }

    public function testRequestForADeactivatedAccountCreatesNoToken(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PasswordResetService::class);
        $user = $this->createUser($em);
        $user->setActive(false);
        $em->flush();

        $service->requestReset($user->getEmail(), '203.0.113.7');

        self::assertSame(0, self::getContainer()->get(PasswordResetTokenRepository::class)->count([]));
    }

    public function testRequestForAnActiveAccountCreatesExactlyOneUsableToken(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PasswordResetService::class);
        $repository = self::getContainer()->get(PasswordResetTokenRepository::class);
        $user = $this->createUser($em);

        $service->requestReset($user->getEmail(), '203.0.113.7');

        // findUsableByUserForUpdate() takes a row lock (SELECT ... FOR
        // UPDATE), which Doctrine refuses outside an explicit transaction.
        $usable = $em->wrapInTransaction(fn () => $repository->findUsableByUserForUpdate($user, new \DateTimeImmutable()));
        self::assertCount(1, $usable);
        self::assertSame('203.0.113.7', $this->fetchRequestedByIp($em, $usable[0]));
    }

    /**
     * Two requests for the same user must never leave two simultaneously
     * usable tokens behind. A true two-connection race isn't reliably
     * reproducible under PHPUnit (dama/doctrine-test-bundle runs the whole
     * test in one transaction on one connection — see
     * UserRegistrationServiceTest's own documented rationale for the same
     * limitation); this proves the invariant the row lock
     * (PasswordResetTokenRepository::findUsableByUserForUpdate) is built to
     * guarantee even when two real transactions do interleave: whichever
     * request's transaction commits second always finds and supersedes
     * whatever the first left behind.
     */
    public function testASecondRequestLeavesExactlyOneUsableToken(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PasswordResetService::class);
        $repository = self::getContainer()->get(PasswordResetTokenRepository::class);
        $user = $this->createUser($em);

        $service->requestReset($user->getEmail(), '203.0.113.7');
        $service->requestReset($user->getEmail(), '203.0.113.8');

        self::assertSame(2, $repository->count([]), 'Both rows are kept — history is never deleted.');
        $usable = $em->wrapInTransaction(fn () => $repository->findUsableByUserForUpdate($user, new \DateTimeImmutable()));
        self::assertCount(1, $usable, 'Only the most recent request is usable.');
    }

    /**
     * Same documented limitation/approach as above: two confirmations of
     * the same raw token can't be fired as a genuine simultaneous DB race
     * inside one PHPUnit transaction, but PasswordResetTokenRepository's
     * row lock (SELECT ... FOR UPDATE) is exactly what makes "whichever
     * commits first wins" true under real concurrency too — this proves
     * the second attempt, once the first has committed its consumption, is
     * unconditionally rejected.
     */
    public function testASecondConfirmationOfTheSameTokenIsRejectedAsAlreadyConsumed(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PasswordResetService::class);
        $user = $this->createUser($em);
        $rawToken = $this->persistToken($em, $user);

        $service->confirmReset($rawToken, 'brand-new-password-1');

        try {
            $service->confirmReset($rawToken, 'another-password-2');
            self::fail('The second confirmation must be rejected.');
        } catch (InvalidPasswordResetTokenException $exception) {
            self::assertSame(PasswordResetFailureReason::CONSUMED, $exception->reason);
        }
    }

    public function testAnUnknownTokenReasonIsNotFound(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(PasswordResetService::class);

        try {
            $service->confirmReset(bin2hex(random_bytes(32)), 'brand-new-password-1');
            self::fail('An unknown token must be rejected.');
        } catch (InvalidPasswordResetTokenException $exception) {
            self::assertSame(PasswordResetFailureReason::NOT_FOUND, $exception->reason);
        }
    }

    public function testAnExpiredTokenReasonIsExpired(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PasswordResetService::class);
        $user = $this->createUser($em);
        $rawToken = $this->persistToken($em, $user);
        // expires_at > created_at is enforced by a DB CHECK on every write,
        // including this one — both moved into the past together, exactly
        // like PasswordResetControllerTest::testConfirmWithAnExpiredTokenIsRejected.
        $em->getConnection()->executeStatement(
            'UPDATE password_reset_tokens SET created_at = :c, expires_at = :e WHERE user_id = :u',
            ['c' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), 'e' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), 'u' => $user->getId()],
        );
        // The raw UPDATE above bypasses Doctrine entirely: without clearing
        // it, the identity map would still hand confirmReset() back the
        // same PHP object created by persistToken(), with its original
        // (non-expired) $expiresAt still in memory.
        $em->clear();

        try {
            $service->confirmReset($rawToken, 'brand-new-password-1');
            self::fail('An expired token must be rejected.');
        } catch (InvalidPasswordResetTokenException $exception) {
            self::assertSame(PasswordResetFailureReason::EXPIRED, $exception->reason);
        }
    }

    public function testARevokedTokenReasonIsRevoked(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PasswordResetService::class);
        $user = $this->createUser($em);
        [$rawToken, $token] = $this->persistTokenEntity($em, $user);
        $token->revoke();
        $em->flush();

        try {
            $service->confirmReset($rawToken, 'brand-new-password-1');
            self::fail('A revoked token must be rejected.');
        } catch (InvalidPasswordResetTokenException $exception) {
            self::assertSame(PasswordResetFailureReason::REVOKED, $exception->reason);
        }
    }

    public function testADeactivatedAccountReasonIsAccountNotEligible(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PasswordResetService::class);
        $user = $this->createUser($em);
        $rawToken = $this->persistToken($em, $user);
        $user->setActive(false);
        $em->flush();

        try {
            $service->confirmReset($rawToken, 'brand-new-password-1');
            self::fail('A deactivated account must be rejected.');
        } catch (InvalidPasswordResetTokenException $exception) {
            self::assertSame(PasswordResetFailureReason::ACCOUNT_NOT_ELIGIBLE, $exception->reason);
        }
    }

    public function testSuccessfulConfirmBumpsCredentialsVersion(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PasswordResetService::class);
        $user = $this->createUser($em);
        $before = $user->getCredentialsVersion();
        $rawToken = $this->persistToken($em, $user);

        $service->confirmReset($rawToken, 'brand-new-password-1');

        self::assertSame($before + 1, $user->getCredentialsVersion());
    }

    private function persistToken(EntityManagerInterface $em, User $user, ?\DateTimeImmutable $expiresAt = null): string
    {
        return $this->persistTokenEntity($em, $user, $expiresAt)[0];
    }

    /**
     * @return array{0: string, 1: PasswordResetToken}
     */
    private function persistTokenEntity(EntityManagerInterface $em, User $user, ?\DateTimeImmutable $expiresAt = null): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $token = new PasswordResetToken($user, PasswordResetToken::hashToken($rawToken), $expiresAt ?? new \DateTimeImmutable('+30 minutes'), '203.0.113.7');
        $em->persist($token);
        $em->flush();

        return [$rawToken, $token];
    }

    private function fetchRequestedByIp(EntityManagerInterface $em, PasswordResetToken $token): ?string
    {
        $connection = $em->getConnection();

        return (string) $connection->fetchOne('SELECT requested_by_ip FROM password_reset_tokens WHERE id = ?', [$token->getId()]);
    }

    private function createUser(EntityManagerInterface $em): User
    {
        $user = new User(sprintf('user-%s@example.com', bin2hex(random_bytes(4))), 'Test', 'User', 'irrelevant-hash');
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
