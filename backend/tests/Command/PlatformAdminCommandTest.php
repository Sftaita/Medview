<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The bootstrap procedure (docs/admin.md §2): the first administrator is
 * named from the server console, on an existing account, audited as CONSOLE;
 * the last one cannot be removed.
 */
final class PlatformAdminCommandTest extends KernelTestCase
{
    public function testGrantListAndRevokeFromTheConsole(): void
    {
        $first = $this->user('first@example.test');
        $second = $this->user('second@example.test');

        self::assertSame(Command::SUCCESS, $this->runCommand('grant', 'FIRST@example.test')->getStatusCode());
        self::assertTrue($this->reload($first)->isPlatformAdmin());

        $tester = $this->runCommand('grant', 'first@example.test');
        self::assertSame(Command::FAILURE, $tester->getStatusCode(), 'Already an administrator.');

        $tester = $this->runCommand('revoke', 'first@example.test');
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('dernier administrateur', $tester->getDisplay());
        self::assertTrue($this->reload($first)->isPlatformAdmin());

        $this->runCommand('grant', 'second@example.test');
        $list = $this->runCommand('list');
        self::assertStringContainsString('first@example.test', $list->getDisplay());
        self::assertStringContainsString('second@example.test', $list->getDisplay());

        self::assertSame(Command::SUCCESS, $this->runCommand('revoke', 'first@example.test')->getStatusCode());
        self::assertFalse($this->reload($first)->isPlatformAdmin());
        self::assertTrue($this->reload($second)->isPlatformAdmin());

        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative(
            "SELECT type, outcome, actor_kind, actor_id FROM platform_audit_events WHERE type IN ('PLATFORM_ADMIN_GRANTED', 'PLATFORM_ADMIN_REVOKED') ORDER BY id",
        );
        self::assertSame(
            [['PLATFORM_ADMIN_GRANTED', 'CONSOLE'], ['PLATFORM_ADMIN_GRANTED', 'CONSOLE'], ['PLATFORM_ADMIN_REVOKED', 'CONSOLE']],
            array_map(static fn (array $row): array => [$row['type'], $row['actor_kind']], $rows),
        );
        self::assertNull($rows[0]['actor_id']);
    }

    public function testUnknownOrDisabledAccountsAreRefused(): void
    {
        $disabled = $this->user('disabled@example.test');
        $disabled->setActive(false);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame(Command::FAILURE, $this->runCommand('grant', 'nobody@example.test')->getStatusCode());
        self::assertSame(Command::FAILURE, $this->runCommand('grant', 'disabled@example.test')->getStatusCode());
        self::assertSame(Command::INVALID, $this->runCommand('promote', 'disabled@example.test')->getStatusCode());
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM users WHERE platform_admin'));
    }

    private function runCommand(string $action, ?string $email = null): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:platform-admin'));
        $tester->execute(array_filter(['action' => $action, 'email' => $email]));

        return $tester;
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function user(string $email): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User($email, 'Test', 'User', 'irrelevant-hash');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function reload(User $user): User
    {
        self::getContainer()->get(EntityManagerInterface::class)->refresh($user);

        return $user;
    }
}
