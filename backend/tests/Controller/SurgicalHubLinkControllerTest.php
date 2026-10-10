<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\AuthenticationTestHelpers;
use App\Tests\SurgicalHubTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Associating a MedVue account with a SurgicalHub account
 * (docs/surgicalhub-integration.md §4-§5.1, docs/decisions.md D182).
 */
final class SurgicalHubLinkControllerTest extends WebTestCase
{
    use AuthenticationTestHelpers;
    use SurgicalHubTestHelpers;

    public function testStatusRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/me/surgicalhub');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnUnlinkedAccountHasNoLink(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'sh.none@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'sh.none@example.com', 'correct-horse-battery');

        $client->request('GET', '/api/me/surgicalhub', server: $this->userHeaders($token));

        self::assertResponseIsSuccessful();
        self::assertNull(json_decode((string) $client->getResponse()->getContent(), true)['link']);
    }

    public function testIssuedCodeIsShortLivedNeverCachedAndOnlyItsHashIsStored(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'sh.code@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'sh.code@example.com', 'correct-horse-battery');

        $before = time();
        $client->request('POST', '/api/me/surgicalhub/link-code', server: $this->userHeaders($token));

        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $data['code']);
        $ttl = strtotime($data['expiresAt']) - $before;
        self::assertGreaterThanOrEqual(595, $ttl);
        self::assertLessThanOrEqual(605, $ttl);

        $stored = $this->connection()->fetchAllAssociative(
            'SELECT c.code_hash FROM surgical_hub_link_codes c JOIN users u ON u.id = c.user_id WHERE u.email = ?',
            ['sh.code@example.com'],
        );
        self::assertCount(1, $stored);
        $raw = str_replace('-', '', $data['code']);
        self::assertSame(hash('sha256', $raw), $stored[0]['code_hash']);
        self::assertStringNotContainsString($raw, json_encode($this->connection()->fetchAllAssociative('SELECT * FROM surgical_hub_link_codes')));
        self::assertStringNotContainsString($raw, json_encode($this->connection()->fetchAllAssociative('SELECT * FROM surgical_hub_link_events')));
    }

    public function testOwnerLinksTheirAccountAndTheAnswerSaysNothingAboutMedVue(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'sh.link@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'sh.link@example.com', 'correct-horse-battery');
        $code = $this->issueLinkCode($client, $token);

        $this->redeemLinkCode($client, $code, '4812');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        $data = json_decode($body, true);
        self::assertSame(['linkId', 'linkedAt'], array_keys($data));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $data['linkId']);
        self::assertStringNotContainsString('sh.link@example.com', $body);
        self::assertStringNotContainsString('Test', $body);
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'To', 'sh.link@example.com');
        self::assertEmailTextBodyContains($email, 'Dr Jeanne Martin');

        $client->request('GET', '/api/me/surgicalhub', server: $this->userHeaders($token));
        $link = json_decode((string) $client->getResponse()->getContent(), true)['link'];
        self::assertSame('ACTIVE', $link['status']);
        self::assertSame('Dr Jeanne Martin', $link['surgicalHubName']);
        self::assertFalse($link['linkedByAdministrator']);
        self::assertNull($link['lastSuccessfulSyncAt']);

        self::assertSame(['CODE_ISSUED', 'LINKED'], $this->connection()->fetchFirstColumn(
            'SELECT e.kind FROM surgical_hub_link_events e JOIN users u ON u.id = e.user_id WHERE u.email = ? ORDER BY e.id',
            ['sh.link@example.com'],
        ));
    }

    public function testAdministratorLinkingIsRecordedAndNamedInTheOwnersEmail(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'sh.admin@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'sh.admin@example.com', 'correct-horse-battery');

        $this->redeemLinkCode($client, $this->issueLinkCode($client, $token), '77', [
            'actorDisplayName' => 'Alice Admin',
            'actorIsAdministrator' => true,
        ]);

        self::assertResponseIsSuccessful();
        self::assertEmailTextBodyContains(self::getMailerMessage(), 'par Alice Admin (administrateur SurgicalHub)');
        $client->request('GET', '/api/me/surgicalhub', server: $this->userHeaders($token));
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['link']['linkedByAdministrator']);
        self::assertSame('Alice Admin', $this->connection()->fetchOne("SELECT actor_label FROM surgical_hub_link_events WHERE kind = 'LINKED' AND surgical_hub_user_id = '77'"));
    }

    public function testCodeTypedLooselyIsAccepted(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'sh.loose@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'sh.loose@example.com', 'correct-horse-battery');
        $code = $this->issueLinkCode($client, $token);

        $this->redeemLinkCode($client, ' '.strtolower(str_replace('-', ' ', $code)).' ');

        self::assertResponseIsSuccessful();
    }

    public function testConsumedCodeIsRefused(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'sh.reuse@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'sh.reuse@example.com', 'correct-horse-battery');
        $code = $this->issueLinkCode($client, $token);
        $this->redeemLinkCode($client, $code, '1001');
        self::assertResponseIsSuccessful();

        $this->redeemLinkCode($client, $code, '1001');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_code', json_decode((string) $client->getResponse()->getContent(), true)['error']);
    }

    public function testExpiredCodeIsRefusedLikeAnUnknownOne(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'sh.expired@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'sh.expired@example.com', 'correct-horse-battery');
        $code = $this->issueLinkCode($client, $token);
        $this->connection()->executeStatement("UPDATE surgical_hub_link_codes SET expires_at = NOW() - INTERVAL '1 second'");

        $this->redeemLinkCode($client, $code);
        $expired = (string) $client->getResponse()->getContent();
        self::assertResponseStatusCodeSame(422);

        $this->redeemLinkCode($client, '0000-0000-0000');
        self::assertResponseStatusCodeSame(422);
        self::assertSame($expired, (string) $client->getResponse()->getContent());

        $this->redeemLinkCode($client, 'not a code');
        self::assertResponseStatusCodeSame(422);
        self::assertSame($expired, (string) $client->getResponse()->getContent());
    }

    public function testANewCodeSupersedesThePreviousOne(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'sh.newer@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'sh.newer@example.com', 'correct-horse-battery');
        $first = $this->issueLinkCode($client, $token);
        $second = $this->issueLinkCode($client, $token);

        $this->redeemLinkCode($client, $first);
        self::assertResponseStatusCodeSame(422);

        $this->redeemLinkCode($client, $second);
        self::assertResponseIsSuccessful();
    }

    public function testCodeOfADeactivatedAccountIsRefused(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'sh.inactive@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'sh.inactive@example.com', 'correct-horse-battery');
        $code = $this->issueLinkCode($client, $token);
        $this->connection()->executeStatement("UPDATE users SET active = false WHERE email = 'sh.inactive@example.com'");

        $this->redeemLinkCode($client, $code);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM surgical_hub_links'));
    }

    public function testSamePairRedeemingAgainConfirmsTheExistingAssociation(): void
    {
        $client = static::createClient();
        [$token, $linkId] = $this->linkedUser($client, 'sh.again@example.com', '2002');
        self::assertEmailCount(1);

        $this->redeemLinkCode($client, $this->issueLinkCode($client, $token), '2002');

        self::assertResponseIsSuccessful();
        self::assertSame($linkId, json_decode((string) $client->getResponse()->getContent(), true)['linkId']);
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM surgical_hub_links WHERE surgical_hub_user_id = '2002'"));
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM surgical_hub_link_events WHERE kind = 'LINK_CONFIRMED'"));
    }

    public function testASurgicalHubAccountCannotBeLinkedToASecondMedVueAccount(): void
    {
        $client = static::createClient();
        $this->linkedUser($client, 'sh.first@example.com', '3003');
        $this->registerUser($client, 'sh.second@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'sh.second@example.com', 'correct-horse-battery');
        $code = $this->issueLinkCode($client, $token);

        $this->redeemLinkCode($client, $code, '3003');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_linked', json_decode((string) $client->getResponse()->getContent(), true)['error']);
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM surgical_hub_link_events WHERE kind = 'REFUSED_SURGICAL_HUB_ACCOUNT_TAKEN'"));

        // The administrator picked the wrong account: the code still works for the right one.
        $this->redeemLinkCode($client, $code, '3004');
        self::assertResponseIsSuccessful();
    }

    public function testAMedVueAccountCannotBeLinkedToASecondSurgicalHubAccount(): void
    {
        $client = static::createClient();
        [$token] = $this->linkedUser($client, 'sh.twice@example.com', '4004');

        $this->redeemLinkCode($client, $this->issueLinkCode($client, $token), '4005');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('medvue_account_already_linked', json_decode((string) $client->getResponse()->getContent(), true)['error']);
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM surgical_hub_links WHERE surgical_hub_user_id = '4005'"));
    }

    public function testOwnerDissociatesThenCanLinkAnotherAccount(): void
    {
        $client = static::createClient();
        [$token] = $this->linkedUser($client, 'sh.unlink@example.com', '5005');

        $client->request('DELETE', '/api/me/surgicalhub/link', server: $this->userHeaders($token));
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/me/surgicalhub', server: $this->userHeaders($token));
        $link = json_decode((string) $client->getResponse()->getContent(), true)['link'];
        self::assertSame('REVOKED_LOCAL', $link['status']);
        self::assertNotNull($link['revokedAt']);

        $client->request('DELETE', '/api/me/surgicalhub/link', server: $this->userHeaders($token));
        self::assertResponseStatusCodeSame(404);

        $this->redeemLinkCode($client, $this->issueLinkCode($client, $token), '5006');
        self::assertResponseIsSuccessful();
        self::assertSame(['REVOKED_LOCAL', 'ACTIVE'], $this->connection()->fetchFirstColumn(
            "SELECT l.status FROM surgical_hub_links l JOIN users u ON u.id = l.user_id WHERE u.email = 'sh.unlink@example.com' ORDER BY l.id",
        ));
    }

    public function testRedeemRequiresTheIntegrationSecret(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'sh.secret@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'sh.secret@example.com', 'correct-horse-battery');
        $payload = json_encode(['code' => $this->issueLinkCode($client, $token), 'surgicalHubUserId' => '1', 'surgicalHubDisplayName' => 'X', 'actorDisplayName' => 'X']);
        $url = '/api/integrations/surgicalhub/v1/link-codes/redeem';

        $client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        self::assertResponseStatusCodeSame(401);

        $client->request('POST', $url, server: $this->integrationHeaders('wrong-secret-wrong-secret-wrong-secret-00'), content: $payload);
        self::assertResponseStatusCodeSame(401);

        // A MedVue user's own access token is not an integration credential.
        $client->request('POST', $url, server: $this->userHeaders($token), content: $payload);
        self::assertResponseStatusCodeSame(401);

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM surgical_hub_links'));
    }

    public function testIntegrationSecretOpensNothingElse(): void
    {
        $client = static::createClient();

        foreach (['/api/me/surgicalhub', '/api/me/calendar', '/api/plannings', '/api/admin/users'] as $url) {
            $client->request('GET', $url, server: $this->integrationHeaders());
            self::assertResponseStatusCodeSame(401, $url);
        }
    }

    public function testInvalidPayloadNeverEchoesTheCode(): void
    {
        $client = static::createClient();

        $this->redeemLinkCode($client, 'ABCD-EFGH-JKMN', 'not valid!');
        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('ABCD', (string) $client->getResponse()->getContent());

        $this->redeemLinkCode($client, 'ABCD-EFGH-JKMN', '1', ['medvueUserId' => 3]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('ABCD', (string) $client->getResponse()->getContent());
    }

    public function testCodeGenerationIsRateLimitedPerUser(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'sh.limit@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'sh.limit@example.com', 'correct-horse-battery');

        for ($i = 0; $i < 10; ++$i) {
            $client->request('POST', '/api/me/surgicalhub/link-code', server: $this->userHeaders($token));
            self::assertResponseStatusCodeSame(201);
        }

        $client->request('POST', '/api/me/surgicalhub/link-code', server: $this->userHeaders($token));
        self::assertResponseStatusCodeSame(429);
    }

    public function testRedeemIsRateLimited(): void
    {
        $client = static::createClient();
        $ip = $this->randomTestIp();

        for ($i = 0; $i < 60; ++$i) {
            $this->redeemLinkCode($client, '0000-0000-0000', ip: $ip);
            self::assertResponseStatusCodeSame(422);
        }

        $this->redeemLinkCode($client, '0000-0000-0000', ip: $ip);
        self::assertResponseStatusCodeSame(429);
    }

    public function testDatabaseRefusesTwoActiveAssociationsAndAnyRewriteOfTheJournal(): void
    {
        $client = static::createClient();
        $this->linkedUser($client, 'sh.db@example.com', '6006');
        $connection = $this->connection();

        $connection->executeStatement('SAVEPOINT duplicate');
        try {
            $connection->executeStatement(
                "INSERT INTO surgical_hub_links (stable_id, surgical_hub_user_id, surgical_hub_display_name, status, linked_by_administrator, linked_at, user_id)
                 SELECT gen_random_uuid(), '6007', 'X', 'ACTIVE', false, NOW(), user_id FROM surgical_hub_links WHERE surgical_hub_user_id = '6006'",
            );
            self::fail('A second ACTIVE association for the same user must be refused.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $connection->executeStatement('ROLLBACK TO SAVEPOINT duplicate');
        }

        $connection->executeStatement('SAVEPOINT journal');
        try {
            $connection->executeStatement("UPDATE surgical_hub_link_events SET actor_label = 'rewritten'");
            self::fail('The journal must be append-only.');
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::assertStringContainsString('append-only', $exception->getMessage());
            $connection->executeStatement('ROLLBACK TO SAVEPOINT journal');
        }
    }
}
