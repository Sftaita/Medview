<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use App\Tests\InvitationTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The invitee's side (docs/decisions.md D113): looking an invitation up,
 * registering through it, consuming several invitations at once, and every
 * way the link can be unusable.
 */
final class InvitationRegistrationTest extends WebTestCase
{
    use InvitationTestHelpers;

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /**
     * Invites $email to a fresh planning (own creator) and returns the raw token.
     *
     * @return array{token: string, planning: string, team: string, creator: string}
     */
    private function invitedBy(KernelBrowser $client, string $creatorEmail, string $inviteeEmail, string $teamName = 'Seniors', string $first = 'Marie', string $last = 'Dupont'): array
    {
        $creator = $this->userToken($client, $creatorEmail);
        [$planning, $team] = $this->createPlanningWithTeam($client, $creator, $teamName);
        $this->invite($client, $creator, $planning, $team, $inviteeEmail, $first, $last);
        self::assertResponseStatusCodeSame(201);

        return ['token' => $this->tokenFromLastEmail(), 'planning' => $planning, 'team' => $team, 'creator' => $creator];
    }

    public function testLookupReturnsTheDataToPrefillTheFormAndNothingInternal(): void
    {
        $client = static::createClient();
        $i = $this->invitedBy($client, 'reg1.creator@example.com', 'marie@example.com');

        $data = $this->api($client, 'GET', '/api/invitations/'.$i['token']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('marie@example.com', $data['email']);
        self::assertSame('Marie', $data['proposedFirstName']);
        self::assertSame('Dupont', $data['proposedLastName']);
        self::assertSame('Seniors', $data['teamName']);
        self::assertSame('Test User', $data['inviterName']);
        self::assertFalse($data['accountExists']);
        self::assertEqualsCanonicalizing(
            ['email', 'proposedFirstName', 'proposedLastName', 'teamName', 'planningName', 'inviterName', 'expiresAt', 'accountExists'],
            array_keys($data),
            'No id, no hash, no other invitation.',
        );
    }

    public function testUnknownTokenIs404(): void
    {
        $client = static::createClient();

        $data = $this->api($client, 'GET', '/api/invitations/'.str_repeat('a', 64));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('invitation_not_found', $data['error']);
    }

    public function testKnowingTheHashIsNotEnoughToUseAnInvitation(): void
    {
        $client = static::createClient();
        $i = $this->invitedBy($client, 'reg2.creator@example.com', 'marie@example.com');
        $hash = $this->connection()->fetchOne('SELECT token_hash FROM team_invitations');
        self::assertSame(hash('sha256', $i['token']), $hash);

        $this->api($client, 'GET', '/api/invitations/'.$hash);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRegisterThroughInvitationCreatesUserMembershipAndAcceptsAtomically(): void
    {
        $client = static::createClient();
        $i = $this->invitedBy($client, 'reg3.creator@example.com', 'marie@example.com');

        $data = $this->registerWithToken($client, $i['token'], 'marie@example.com');

        self::assertResponseStatusCodeSame(201);
        self::assertSame('marie@example.com', $data['email']);
        self::assertCount(1, $data['joinedTeams']);
        self::assertSame('Seniors', $data['joinedTeams'][0]['teamName']);
        self::assertSame($i['team'], $data['joinedTeams'][0]['teamStableId']);

        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('marie@example.com');
        self::assertNotNull($user);
        $open = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM planning_team_members m WHERE m.user_id = ? AND m.membership_end IS NULL AND m.role = ?',
            [$user->getId(), 'MEMBER'],
        );
        self::assertSame(1, (int) $open);
        $row = $this->connection()->fetchAssociative('SELECT status, accepted_at, accepted_by_id FROM team_invitations');
        self::assertSame('ACCEPTED', $row['status']);
        self::assertNotNull($row['accepted_at']);
        self::assertSame($user->getId(), (int) $row['accepted_by_id']);

        // The new account can log in and sees the team it joined.
        $token = $this->loginUser($client, 'marie@example.com', 'correct-horse-battery');
        $members = $this->api($client, 'GET', "/api/plannings/{$i['planning']}/teams/{$i['team']}/members", token: $token);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $members);
    }

    public function testSuggestedNamesCanBeCorrectedAtRegistration(): void
    {
        $client = static::createClient();
        $i = $this->invitedBy($client, 'reg4.creator@example.com', 'marie@example.com', first: 'Mary', last: 'Dupond');

        $data = $this->registerWithToken($client, $i['token'], 'marie@example.com', ['firstName' => 'Marie', 'lastName' => 'Dupont']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Marie', $data['firstName']);
        self::assertSame('Dupont', $data['lastName']);
        // The invitation keeps what the inviter typed (audit), the account what the person confirmed.
        self::assertSame('Mary', $this->connection()->fetchOne('SELECT proposed_first_name FROM team_invitations'));
    }

    public function testTheInvitationEmailCannotBeChanged(): void
    {
        $client = static::createClient();
        $i = $this->invitedBy($client, 'reg5.creator@example.com', 'marie@example.com');

        $data = $this->registerWithToken($client, $i['token'], 'someone.else@example.com');

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('email', $data['violations']);
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('someone.else@example.com'));
        self::assertSame('PENDING', $this->connection()->fetchOne('SELECT status FROM team_invitations'), 'A refused attempt must not burn the invitation.');
    }

    public function testEmailCaseDoesNotMatter(): void
    {
        $client = static::createClient();
        $i = $this->invitedBy($client, 'reg6.creator@example.com', 'marie@example.com');

        $this->registerWithToken($client, $i['token'], 'Marie@Example.COM');

        self::assertResponseStatusCodeSame(201);
        self::assertSame('marie@example.com', static::getContainer()->get(UserRepository::class)->findOneByEmail('marie@example.com')->getEmail());
    }

    public function testAcceptedInvitationCannotBeReplayed(): void
    {
        $client = static::createClient();
        $i = $this->invitedBy($client, 'reg7.creator@example.com', 'marie@example.com');
        $this->registerWithToken($client, $i['token'], 'marie@example.com');
        self::assertResponseStatusCodeSame(201);

        $data = $this->registerWithToken($client, $i['token'], 'marie@example.com');
        self::assertResponseStatusCodeSame(410);
        self::assertSame('invitation_already_used', $data['error']);

        $this->api($client, 'GET', '/api/invitations/'.$i['token']);
        self::assertResponseStatusCodeSame(410);

        $users = $this->connection()->fetchOne("SELECT COUNT(*) FROM users WHERE email = 'marie@example.com'");
        self::assertSame(1, (int) $users, 'A double submission never yields two accounts.');
    }

    public function testExpiredInvitationIsRefusedByTheServer(): void
    {
        $client = static::createClient();
        $i = $this->invitedBy($client, 'reg8.creator@example.com', 'marie@example.com');
        $this->connection()->executeStatement("UPDATE team_invitations SET expires_at = NOW() - INTERVAL '1 minute', created_at = NOW() - INTERVAL '8 days'");

        $data = $this->api($client, 'GET', '/api/invitations/'.$i['token']);
        self::assertResponseStatusCodeSame(410);
        self::assertSame('invitation_expired', $data['error']);

        $data = $this->registerWithToken($client, $i['token'], 'marie@example.com');
        self::assertResponseStatusCodeSame(410);
        self::assertSame('invitation_expired', $data['error']);
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('marie@example.com'));
    }

    public function testRevokedInvitationIsRefused(): void
    {
        $client = static::createClient();
        $i = $this->invitedBy($client, 'reg9.creator@example.com', 'marie@example.com');
        $id = $this->connection()->fetchOne('SELECT stable_id FROM team_invitations');
        $this->api($client, 'POST', "/api/plannings/{$i['planning']}/teams/{$i['team']}/invitations/{$id}/revoke", token: $i['creator']);
        self::assertResponseStatusCodeSame(200);

        $this->api($client, 'GET', '/api/invitations/'.$i['token']);
        self::assertResponseStatusCodeSame(410);

        $data = $this->registerWithToken($client, $i['token'], 'marie@example.com');
        self::assertResponseStatusCodeSame(410);
        self::assertSame('invitation_revoked', $data['error']);
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('marie@example.com'));
    }

    public function testSeveralInvitationsForTheSameEmailGiveOneUserSeveralMembershipsAndOneEmail(): void
    {
        $client = static::createClient();
        $a = $this->invitedBy($client, 'reg10.a@example.com', 'marie@example.com', 'Team A');
        $b = $this->invitedBy($client, 'reg10.b@example.com', 'marie@example.com', 'Team B');
        $c = $this->invitedBy($client, 'reg10.c@example.com', 'MARIE@example.com', 'Team C');
        // A fourth one, already revoked: must not be consumed.
        $d = $this->invitedBy($client, 'reg10.d@example.com', 'marie@example.com', 'Team D');
        $this->connection()->executeStatement("UPDATE team_invitations SET status = 'REVOKED' WHERE token_hash = ?", [hash('sha256', $d['token'])]);

        // Any one of the links is enough: it proves control of the mailbox.
        $data = $this->registerWithToken($client, $b['token'], 'marie@example.com');

        self::assertResponseStatusCodeSame(201);
        self::assertEqualsCanonicalizing(['Team A', 'Team B', 'Team C'], array_column($data['joinedTeams'], 'teamName'));

        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM users WHERE email = 'marie@example.com'"));
        self::assertSame(3, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM planning_team_members m JOIN users u ON u.id = m.user_id WHERE u.email = ? AND m.membership_end IS NULL', ['marie@example.com']));
        $statuses = $this->connection()->fetchAllKeyValue('SELECT token_hash, status FROM team_invitations');
        self::assertSame('ACCEPTED', $statuses[hash('sha256', $a['token'])]);
        self::assertSame('ACCEPTED', $statuses[hash('sha256', $b['token'])]);
        self::assertSame('ACCEPTED', $statuses[hash('sha256', $c['token'])]);
        self::assertSame('REVOKED', $statuses[hash('sha256', $d['token'])]);

        // One welcome email listing the teams — not three.
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertSame('Bienvenue sur MedVue', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        foreach (['Team A', 'Team B', 'Team C'] as $team) {
            self::assertStringContainsString($team, $html);
        }
        self::assertStringNotContainsString('Team D', $html);
        self::assertStringNotContainsString('Team D', (string) $email->getTextBody());

        // Each of the other links is now used up.
        $this->api($client, 'GET', '/api/invitations/'.$a['token']);
        self::assertResponseStatusCodeSame(410);
    }

    public function testTwoInvitationsFromTheSamePlanningJoinOnlyTheFirstAndLeaveTheOtherPending(): void
    {
        $client = static::createClient();
        $creator = $this->userToken($client, 'reg11.creator@example.com');
        [$planning, $teamA] = $this->createPlanningWithTeam($client, $creator, 'Team A');
        $this->api($client, 'POST', "/api/plannings/{$planning}/lines", ['name' => 'Team B'], $creator);
        $teamB = json_decode((string) $client->getResponse()->getContent(), true)['team']['stableId'];
        $this->invite($client, $creator, $planning, $teamA);
        $token = $this->tokenFromLastEmail();
        $this->invite($client, $creator, $planning, $teamB);

        $data = $this->registerWithToken($client, $token, 'marie@example.com');

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['Team A'], array_column($data['joinedTeams'], 'teamName'));
        $statuses = $this->connection()->fetchFirstColumn('SELECT status FROM team_invitations ORDER BY id');
        self::assertSame(['ACCEPTED', 'PENDING'], $statuses, 'Never two open memberships in one planning.');
    }

    public function testAccountCreatedBetweenInvitationAndAcceptanceNeverDuplicatesTheUser(): void
    {
        $client = static::createClient();
        $i = $this->invitedBy($client, 'reg12.creator@example.com', 'marie@example.com');
        // Marie signs up on her own, classically, before using the link.
        $this->registerUser($client, 'marie@example.com', 'correct-horse-battery');

        $data = $this->api($client, 'GET', '/api/invitations/'.$i['token']);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($data['accountExists'], 'The link holder is told to log in instead.');

        $data = $this->registerWithToken($client, $i['token'], 'marie@example.com');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('account_exists_for_invitation', $data['error']);
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM users WHERE email = 'marie@example.com'"));
        self::assertSame('PENDING', $this->connection()->fetchOne('SELECT status FROM team_invitations'));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM planning_team_members m JOIN users u ON u.id = m.user_id WHERE u.email = ?', ['marie@example.com']));

        // Logged in, she accepts: membership, no second User.
        $token = $this->loginUser($client, 'marie@example.com', 'correct-horse-battery');
        $data = $this->api($client, 'POST', "/api/invitations/{$i['token']}/accept", token: $token);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($i['team'], $data['joinedTeams'][0]['teamStableId']);
        self::assertSame('ACCEPTED', $this->connection()->fetchOne('SELECT status FROM team_invitations'));
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM users WHERE email = 'marie@example.com'"));

        // And the link cannot be accepted twice.
        $this->api($client, 'POST', "/api/invitations/{$i['token']}/accept", token: $token);
        self::assertResponseStatusCodeSame(410);
    }

    public function testAcceptRequiresAuthenticationAndTheInvitedAccount(): void
    {
        $client = static::createClient();
        $i = $this->invitedBy($client, 'reg13.creator@example.com', 'marie@example.com');
        $this->registerUser($client, 'marie@example.com', 'correct-horse-battery');
        $strangerToken = $this->userToken($client, 'reg13.stranger@example.com');

        $this->api($client, 'POST', "/api/invitations/{$i['token']}/accept");
        self::assertResponseStatusCodeSame(401);

        $data = $this->api($client, 'POST', "/api/invitations/{$i['token']}/accept", token: $strangerToken);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('invitation_email_mismatch', $data['error']);
        self::assertSame('PENDING', $this->connection()->fetchOne('SELECT status FROM team_invitations'));
    }

    /**
     * Emails are not verified yet, so registering "as" someone else without
     * their link must not hand over their pending invitations.
     */
    public function testClassicRegistrationNeverConsumesPendingInvitations(): void
    {
        $client = static::createClient();
        $this->invitedBy($client, 'reg14.creator@example.com', 'marie@example.com');

        $this->registerUser($client, 'marie@example.com', 'correct-horse-battery');

        self::assertSame('PENDING', $this->connection()->fetchOne('SELECT status FROM team_invitations'));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM planning_team_members m JOIN users u ON u.id = m.user_id WHERE u.email = ?', ['marie@example.com']));
    }

    public function testWelcomeEmailFailureDoesNotUndoTheRegistration(): void
    {
        $client = static::createClient();
        $i = $this->invitedBy($client, 'reg15.creator@example.com', 'marie@example.com');

        // From now on the SMTP relay is unreachable (connection refused).
        $original = [$_ENV['MAILER_DSN'] ?? null, $_SERVER['MAILER_DSN'] ?? null];
        $_ENV['MAILER_DSN'] = $_SERVER['MAILER_DSN'] = 'smtp://127.0.0.1:1';
        try {
            static::ensureKernelShutdown();
            $client = static::createClient();
            $this->registerWithToken($client, $i['token'], 'marie@example.com');
            self::assertResponseStatusCodeSame(201);
        } finally {
            [$_ENV['MAILER_DSN'], $_SERVER['MAILER_DSN']] = $original;
        }

        self::assertNotNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('marie@example.com'));
        self::assertSame('ACCEPTED', $this->connection()->fetchOne('SELECT status FROM team_invitations'));
    }

    public function testLookupIsRateLimitedPerIp(): void
    {
        $client = static::createClient();
        $ip = '198.51.100.77';
        static::getContainer()->get('limiter.invitation_lookup')->create($ip)->consume(30);

        $this->api($client, 'GET', '/api/invitations/'.str_repeat('b', 64), ip: $ip);

        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
    }
}
