<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Tests\AdminTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Paramètres" (docs/admin.md §8): platform administrators are managed by
 * other administrators only, with their password re-typed, never on their
 * own account, always audited — and no secret ever leaves the API.
 */
final class AdminSettingsControllerTest extends WebTestCase
{
    use AdminTestHelpers;

    public function testSettingsListAdministratorsAndNeverExposeSecrets(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);

        $body = $this->api($client, 'GET', '/api/admin/settings', $admin['token']);
        self::assertResponseIsSuccessful();
        self::assertSame([$admin['email']], array_column($body['platformAdmins'], 'email'));
        self::assertSame(900, $body['security']['accessTokenTtlSeconds']);
        self::assertSame('permanent', $body['telemetry']['auditRetention']);

        $raw = (string) $client->getResponse()->getContent();
        foreach ([(string) $_SERVER['JWT_PASSPHRASE'], (string) $_SERVER['APP_SECRET'], 'DATABASE_URL', 'postgresql://', 'MAILER_DSN', 'private.pem', 'passphrase'] as $secret) {
            self::assertStringNotContainsString($secret, $raw);
        }
    }

    public function testAnAdministratorGrantsTheRoleToAnExistingAccountWithTheirPassword(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $member = $this->account($client, 'member');

        $body = $this->api($client, 'POST', '/api/admin/platform-admins', $admin['token'], ['email' => strtoupper($member['email']), 'password' => self::ADMIN_PASSWORD]);
        self::assertResponseStatusCodeSame(201);
        self::assertCount(2, $body['platformAdmins']);

        // Effective on the member's next request, with the token they already hold.
        $this->api($client, 'GET', '/api/admin/overview', $member['token']);
        self::assertResponseIsSuccessful();

        $audit = $this->connection()->fetchAssociative("SELECT outcome, actor_kind, target_user_id FROM platform_audit_events WHERE type = 'PLATFORM_ADMIN_GRANTED'");
        self::assertSame('SUCCESS', $audit['outcome']);
        self::assertSame('USER', $audit['actor_kind']);
    }

    public function testAWrongPasswordGrantsNothingAndIsAuditedAsDenied(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $member = $this->account($client, 'member');

        $body = $this->api($client, 'POST', '/api/admin/platform-admins', $admin['token'], ['email' => $member['email'], 'password' => 'not-my-password']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('password_confirmation_failed', $body['error']);

        self::assertFalse($this->api($client, 'GET', '/api/me', $member['token'])['platformAdmin']);
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM platform_audit_events WHERE type = 'PLATFORM_ADMIN_GRANTED' AND outcome = 'DENIED'"));
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM platform_audit_events WHERE type = 'PLATFORM_ADMIN_GRANTED' AND outcome = 'SUCCESS'"));
    }

    public function testGrantRefusals(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $peer = $this->account($client, 'peer', platformAdmin: true);
        $disabled = $this->account($client, 'disabled');
        $this->connection()->executeStatement('UPDATE users SET active = false WHERE email = :email', ['email' => $disabled['email']]);

        $cases = [
            [$admin['email'], 409, 'cannot_target_self'],
            [$peer['email'], 409, 'already_platform_admin'],
            [$disabled['email'], 409, 'target_disabled'],
            ['nobody@example.test', 404, 'user_not_found'],
        ];
        foreach ($cases as [$email, $status, $error]) {
            $body = $this->api($client, 'POST', '/api/admin/platform-admins', $admin['token'], ['email' => $email, 'password' => self::ADMIN_PASSWORD]);
            self::assertResponseStatusCodeSame($status, $error);
            self::assertSame($error, $body['error']);
        }

        $this->api($client, 'POST', '/api/admin/platform-admins', $admin['token'], ['email' => $peer['email'], 'password' => self::ADMIN_PASSWORD, 'role' => 'x']);
        self::assertResponseStatusCodeSame(422);
        $this->api($client, 'POST', '/api/admin/platform-admins', $admin['token'], ['email' => 'not-an-email', 'password' => self::ADMIN_PASSWORD]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRevocationOfAnotherAdministrator(): void
    {
        $client = self::createClient();
        $admin = $this->account($client, 'admin', platformAdmin: true);
        $peer = $this->account($client, 'peer', platformAdmin: true);

        $wrong = $this->api($client, 'POST', "/api/admin/platform-admins/{$peer['stableId']}/revoke", $admin['token'], ['password' => 'nope']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('password_confirmation_failed', $wrong['error']);

        $self = $this->api($client, 'POST', "/api/admin/platform-admins/{$admin['stableId']}/revoke", $admin['token'], ['password' => self::ADMIN_PASSWORD]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('cannot_target_self', $self['error']);

        $body = $this->api($client, 'POST', "/api/admin/platform-admins/{$peer['stableId']}/revoke", $admin['token'], ['password' => self::ADMIN_PASSWORD]);
        self::assertResponseIsSuccessful();
        self::assertSame([$admin['email']], array_column($body['platformAdmins'], 'email'));

        $this->api($client, 'GET', '/api/admin/overview', $peer['token']);
        self::assertResponseStatusCodeSame(403);
        $this->api($client, 'GET', '/api/me', $peer['token']);
        self::assertResponseIsSuccessful('Losing the role is not losing the account.');

        $outcomes = $this->connection()->fetchFirstColumn("SELECT outcome FROM platform_audit_events WHERE type = 'PLATFORM_ADMIN_REVOKED' ORDER BY id");
        self::assertSame(['DENIED', 'DENIED', 'SUCCESS'], $outcomes);

        $notAdmin = $this->api($client, 'POST', "/api/admin/platform-admins/{$peer['stableId']}/revoke", $admin['token'], ['password' => self::ADMIN_PASSWORD]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_platform_admin', $notAdmin['error']);
    }
}
