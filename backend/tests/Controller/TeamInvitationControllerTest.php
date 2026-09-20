<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use App\Tests\InvitationTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST/GET/revoke on /api/plannings/{p}/teams/{t}/invitations — the
 * "Ajouter une personne" flow (docs/decisions.md D111).
 */
final class TeamInvitationControllerTest extends WebTestCase
{
    use InvitationTestHelpers;

    /**
     * @return array{creator: string, planning: string, team: string}
     */
    private function scenario(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $prefix): array
    {
        $creator = $this->userToken($client, "{$prefix}.creator@example.com");
        [$planning, $team] = $this->createPlanningWithTeam($client, $creator);

        return ['creator' => $creator, 'planning' => $planning, 'team' => $team];
    }

    public function testCreatorInvitesAnUnknownEmailAndNoUserIsCreated(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv1');

        $data = $this->invite($client, $s['creator'], $s['planning'], $s['team'], 'Marie@Example.com ', 'Marie', 'Dupont');

        self::assertResponseStatusCodeSame(201);
        self::assertSame('INVITATION_CREATED', $data['status']);
        self::assertTrue($data['emailSent']);
        self::assertNull($data['member']);
        self::assertSame('marie@example.com', $data['invitation']['email'], 'The address is stored normalized.');
        self::assertSame('PENDING', $data['invitation']['status']);
        self::assertSame('MEMBER', $data['invitation']['role']);
        self::assertArrayNotHasKey('token', $data['invitation']);
        self::assertArrayNotHasKey('tokenHash', $data['invitation']);

        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('marie@example.com'), 'An invitation must never create a User.');
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertSame('Vous êtes invité à rejoindre une équipe sur MedVue', $email->getSubject());
        self::assertEmailAddressContains($email, 'to', 'marie@example.com');
    }

    public function testTheRawTokenIsNeverStoredInTheDatabase(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv2');
        $this->invite($client, $s['creator'], $s['planning'], $s['team']);
        $token = $this->tokenFromLastEmail();

        self::assertSame(64, \strlen($token));

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $row = $connection->fetchAssociative('SELECT * FROM team_invitations');
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $token), $row['token_hash']);
        foreach ($row as $column => $value) {
            self::assertStringNotContainsString($token, (string) $value, "Column {$column} must not contain the raw token.");
        }
        // Not anywhere in the API responses of the manager either.
        $this->api($client, 'GET', "/api/plannings/{$s['planning']}/teams/{$s['team']}/invitations", token: $s['creator']);
        self::assertStringNotContainsString($token, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString(hash('sha256', $token), (string) $client->getResponse()->getContent());
    }

    public function testOwnerAndAdminCanInvite(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv3');
        $ownerToken = $this->userToken($client, 'inv3.owner@example.com');
        $adminToken = $this->userToken($client, 'inv3.admin@example.com');
        $this->addMemberAs($client, $s['creator'], $s['planning'], $s['team'], 'inv3.owner@example.com', 'OWNER');
        $this->addMemberAs($client, $s['creator'], $s['planning'], $s['team'], 'inv3.admin@example.com', 'ADMIN');

        $this->invite($client, $ownerToken, $s['planning'], $s['team'], 'from.owner@example.com');
        self::assertResponseStatusCodeSame(201);

        $this->invite($client, $adminToken, $s['planning'], $s['team'], 'from.admin@example.com');
        self::assertResponseStatusCodeSame(201);
    }

    public function testPlainMemberCannotInviteNorSeePendingInvitations(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv4');
        $memberToken = $this->userToken($client, 'inv4.member@example.com');
        $this->addMemberAs($client, $s['creator'], $s['planning'], $s['team'], 'inv4.member@example.com', 'MEMBER');
        $this->invite($client, $s['creator'], $s['planning'], $s['team'], 'pending@example.com');

        $this->invite($client, $memberToken, $s['planning'], $s['team'], 'nope@example.com');
        self::assertResponseStatusCodeSame(403);

        $this->api($client, 'GET', "/api/plannings/{$s['planning']}/teams/{$s['team']}/invitations", token: $memberToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testManagerOfAnotherTeamOrPlanningCannotInvite(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv5');
        $otherToken = $this->userToken($client, 'inv5.other@example.com');
        [$otherPlanning, $otherTeam] = $this->createPlanningWithTeam($client, $otherToken, 'Autre');

        // Stranger to this planning: forbidden.
        $this->invite($client, $otherToken, $s['planning'], $s['team']);
        self::assertResponseStatusCodeSame(403);

        // Their own team id under someone else's planning: never confirmed, 404.
        $this->invite($client, $otherToken, $s['planning'], $otherTeam);
        self::assertResponseStatusCodeSame(404);
        $this->invite($client, $s['creator'], $otherPlanning, $s['team']);
        self::assertResponseStatusCodeSame(404);

        // OWNER of team B of the SAME planning has no power over team A.
        $this->api($client, 'POST', "/api/plannings/{$s['planning']}/lines", ['name' => 'Assistants'], $s['creator']);
        $teamB = json_decode((string) $client->getResponse()->getContent(), true)['team']['stableId'];
        $ownerBToken = $this->userToken($client, 'inv5.ownerb@example.com');
        $this->addMemberAs($client, $s['creator'], $s['planning'], $teamB, 'inv5.ownerb@example.com', 'OWNER');
        $this->invite($client, $ownerBToken, $s['planning'], $s['team']);
        self::assertResponseStatusCodeSame(403);
        $this->invite($client, $ownerBToken, $s['planning'], $teamB);
        self::assertResponseStatusCodeSame(201);
    }

    public function testUnauthenticatedRequestIsRejected(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv6');

        $this->api($client, 'POST', "/api/plannings/{$s['planning']}/teams/{$s['team']}/invitations", ['email' => 'a@example.com', 'firstName' => 'A', 'lastName' => 'B']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testExistingUserIsAddedImmediatelyAndNotified(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv7');
        $this->userToken($client, 'inv7.existing@example.com');

        $data = $this->invite($client, $s['creator'], $s['planning'], $s['team'], 'INV7.Existing@example.com', 'Ignored', 'Ignored');

        self::assertResponseStatusCodeSame(201);
        self::assertSame('USER_ADDED', $data['status']);
        self::assertNull($data['invitation']);
        self::assertSame('MEMBER', $data['member']['role']);
        self::assertNull($data['member']['membershipEnd']);
        // The registered account's own name is used, not the inviter's suggestion.
        self::assertSame('Test', $data['member']['firstName']);

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertSame('Vous avez été ajouté à une équipe sur MedVue', $email->getSubject());
        self::assertEmailAddressContains($email, 'to', 'inv7.existing@example.com');

        $count = static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM team_invitations');
        self::assertSame(0, (int) $count, 'No invitation row for an existing user.');
    }

    public function testInvitingAnExistingMemberTwiceIsIdempotent(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv8');
        $this->userToken($client, 'inv8.existing@example.com');

        $this->invite($client, $s['creator'], $s['planning'], $s['team'], 'inv8.existing@example.com');
        self::assertResponseStatusCodeSame(201);

        $data = $this->invite($client, $s['creator'], $s['planning'], $s['team'], 'inv8.existing@example.com');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('ALREADY_MEMBER', $data['status']);
        self::assertEmailCount(0);

        $open = static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM planning_team_members m JOIN users u ON u.id = m.user_id WHERE u.email = ? AND m.membership_end IS NULL',
            ['inv8.existing@example.com'],
        );
        self::assertSame(1, (int) $open, 'Never two open memberships.');
    }

    public function testInvitingAnExistingUserAlreadyInAnotherTeamOfThePlanningIsAConflict(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv9');
        $this->userToken($client, 'inv9.existing@example.com');
        $this->api($client, 'POST', "/api/plannings/{$s['planning']}/lines", ['name' => 'Assistants'], $s['creator']);
        $teamB = json_decode((string) $client->getResponse()->getContent(), true)['team']['stableId'];

        $this->invite($client, $s['creator'], $s['planning'], $s['team'], 'inv9.existing@example.com');
        self::assertResponseStatusCodeSame(201);

        $data = $this->invite($client, $s['creator'], $s['planning'], $teamB, 'inv9.existing@example.com');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('membership_conflict', $data['error']);
    }

    public function testInvitingTheSameNewEmailTwiceKeepsASinglePendingInvitation(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv10');

        $first = $this->invite($client, $s['creator'], $s['planning'], $s['team']);
        self::assertResponseStatusCodeSame(201);

        $second = $this->invite($client, $s['creator'], $s['planning'], $s['team'], 'MARIE@example.com');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('INVITATION_ALREADY_PENDING', $second['status']);
        self::assertSame($first['invitation']['stableId'], $second['invitation']['stableId']);
        self::assertEmailCount(0, message: 'No second email, no second live token.');

        $count = static::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM team_invitations WHERE status = 'PENDING'");
        self::assertSame(1, (int) $count);
    }

    public function testValidationErrors(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv11');

        $data = $this->api($client, 'POST', "/api/plannings/{$s['planning']}/teams/{$s['team']}/invitations", ['email' => 'not-an-email', 'firstName' => '', 'lastName' => str_repeat('x', 101)], $s['creator']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $data['error']);
        self::assertEqualsCanonicalizing(['email', 'firstName', 'lastName'], array_keys($data['violations']));

        $client->request('POST', "/api/plannings/{$s['planning']}/teams/{$s['team']}/invitations", server: $this->bearer($s['creator']), content: '{not json');
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * D116: the invitation body has no role field in v1 — asking for OWNER
     * must be an error, not a silent downgrade to MEMBER.
     */
    public function testUnknownFieldsAreRejectedSoARoleCannotBeRequested(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv11b');

        $data = $this->api($client, 'POST', "/api/plannings/{$s['planning']}/teams/{$s['team']}/invitations", ['email' => 'marie@example.com', 'firstName' => 'Marie', 'lastName' => 'Dupont', 'role' => 'OWNER'], $s['creator']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['role' => 'This field is not accepted.'], $data['violations']);
        self::assertEmailCount(0);
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM team_invitations'));
    }

    public function testListShowsPendingInvitationsToManagersOnlyAndNeverAsMembers(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv12');
        $this->invite($client, $s['creator'], $s['planning'], $s['team'], 'marie@example.com', 'Marie', 'Dupont');

        $list = $this->api($client, 'GET', "/api/plannings/{$s['planning']}/teams/{$s['team']}/invitations", token: $s['creator']);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $list);
        self::assertSame('Marie', $list[0]['firstName']);
        self::assertSame('PENDING', $list[0]['status']);

        $members = $this->api($client, 'GET', "/api/plannings/{$s['planning']}/teams/{$s['team']}/members", token: $s['creator']);
        self::assertSame([], $members, 'A pending invitation is never a TeamMember.');
    }

    public function testRevokeAndReinvite(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv13');
        $created = $this->invite($client, $s['creator'], $s['planning'], $s['team']);
        $oldToken = $this->tokenFromLastEmail();
        $id = $created['invitation']['stableId'];

        $data = $this->api($client, 'POST', "/api/plannings/{$s['planning']}/teams/{$s['team']}/invitations/{$id}/revoke", token: $s['creator']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('REVOKED', $data['status']);

        $this->api($client, 'POST', "/api/plannings/{$s['planning']}/teams/{$s['team']}/invitations/{$id}/revoke", token: $s['creator']);
        self::assertResponseStatusCodeSame(409);

        $this->api($client, 'GET', "/api/invitations/{$oldToken}");
        self::assertResponseStatusCodeSame(410);

        // The partial unique index only covers PENDING: a fresh invitation is possible.
        $again = $this->invite($client, $s['creator'], $s['planning'], $s['team']);
        self::assertResponseStatusCodeSame(201);
        self::assertNotSame($id, $again['invitation']['stableId']);
    }

    public function testRevokeCannotReachAnotherTeamsInvitation(): void
    {
        $client = static::createClient();
        $a = $this->scenario($client, 'inv14a');
        $b = $this->scenario($client, 'inv14b');
        $created = $this->invite($client, $b['creator'], $b['planning'], $b['team']);

        $this->api($client, 'POST', "/api/plannings/{$a['planning']}/teams/{$a['team']}/invitations/{$created['invitation']['stableId']}/revoke", token: $a['creator']);
        self::assertResponseStatusCodeSame(404);

        $this->api($client, 'GET', "/api/plannings/{$b['planning']}/teams/{$b['team']}/invitations", token: $a['creator']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testExpiredPendingInvitationIsListedAsExpiredAndCanBeReissued(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv15');
        $created = $this->invite($client, $s['creator'], $s['planning'], $s['team']);
        static::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE team_invitations SET expires_at = NOW() - INTERVAL '1 hour', created_at = NOW() - INTERVAL '2 days' WHERE stable_id = ?",
            [$created['invitation']['stableId']],
        );

        $list = $this->api($client, 'GET', "/api/plannings/{$s['planning']}/teams/{$s['team']}/invitations", token: $s['creator']);
        self::assertSame('EXPIRED', $list[0]['status']);

        $again = $this->invite($client, $s['creator'], $s['planning'], $s['team']);
        self::assertResponseStatusCodeSame(201, 'An expired invitation must not block a new one.');
        self::assertSame('INVITATION_CREATED', $again['status']);

        $statuses = static::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT status FROM team_invitations ORDER BY id');
        self::assertSame(['EXPIRED', 'PENDING'], $statuses);
    }

    public function testRateLimitOnInvitations(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, 'inv16');
        // The bucket is keyed by the inviter's stableId: exhaust it directly
        // instead of sending 100 real requests.
        $creator = static::getContainer()->get(UserRepository::class)->findOneByEmail('inv16.creator@example.com');
        static::getContainer()->get('limiter.team_invitation')->create((string) $creator->getStableId())->consume(100);

        $this->invite($client, $s['creator'], $s['planning'], $s['team'], 'bulk-overflow@example.com');
        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
    }
}
