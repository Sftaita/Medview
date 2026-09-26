<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class PasswordResetTokenTest extends TestCase
{
    private function user(): User
    {
        return new User('marie@example.com', 'Marie', 'Dupont', 'hash');
    }

    private function token(string $expiresIn = '+30 minutes'): PasswordResetToken
    {
        return new PasswordResetToken($this->user(), PasswordResetToken::hashToken('raw'), new \DateTimeImmutable($expiresIn), '203.0.113.7');
    }

    public function testOnlyTheHashOfTheTokenIsKept(): void
    {
        $token = $this->token();

        self::assertSame(hash('sha256', 'raw'), $token->getTokenHash());
        self::assertNotSame('raw', $token->getTokenHash());
        self::assertSame(64, \strlen($token->getTokenHash()));
    }

    public function testAFreshTokenIsUsable(): void
    {
        $token = $this->token('+30 minutes');

        self::assertTrue($token->isUsableAt(new \DateTimeImmutable('now')));
        self::assertNull($token->getConsumedAt());
        self::assertNull($token->getRevokedAt());
    }

    public function testUsabilityDependsOnTheClockNotOnAStoredFlag(): void
    {
        $token = $this->token('+30 minutes');

        self::assertTrue($token->isUsableAt(new \DateTimeImmutable('+29 minutes')));
        self::assertFalse($token->isUsableAt(new \DateTimeImmutable('+31 minutes')));
    }

    public function testConsumingMakesItUnusable(): void
    {
        $token = $this->token();
        $token->consume();

        self::assertNotNull($token->getConsumedAt());
        self::assertFalse($token->isUsableAt(new \DateTimeImmutable()));
    }

    public function testConsumeIsIdempotent(): void
    {
        $token = $this->token();
        $token->consume();
        $first = $token->getConsumedAt();
        $token->consume();

        self::assertSame($first, $token->getConsumedAt());
    }

    public function testRevokingMakesItUnusable(): void
    {
        $token = $this->token();
        $token->revoke();

        self::assertNotNull($token->getRevokedAt());
        self::assertFalse($token->isUsableAt(new \DateTimeImmutable()));
    }

    public function testRevokeIsIdempotent(): void
    {
        $token = $this->token();
        $token->revoke();
        $first = $token->getRevokedAt();
        $token->revoke();

        self::assertSame($first, $token->getRevokedAt());
    }
}
