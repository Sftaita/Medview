<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\JwtCredentialsVersionListener;
use App\Repository\UserRepository;
use App\Tests\AuthenticationTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * JwtCredentialsVersionListener in isolation from PasswordResetService
 * (docs/decisions.md D142): the claim is embedded at creation, and any
 * mismatch against the account's current version is rejected — whatever
 * caused the version to change, not only a password reset.
 */
final class JwtCredentialsVersionListenerTest extends WebTestCase
{
    use AuthenticationTestHelpers;

    public function testAFreshlyIssuedJwtEmbedsTheCurrentCredentialsVersion(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'jwtver.fresh@example.com', 'correct-horse-battery');
        $accessToken = $this->loginUser($client, 'jwtver.fresh@example.com', 'correct-horse-battery');

        $payload = static::getContainer()->get(JWTTokenManagerInterface::class)->parse($accessToken);

        self::assertArrayHasKey(JwtCredentialsVersionListener::CLAIM, $payload);
        self::assertSame(1, $payload[JwtCredentialsVersionListener::CLAIM]);
    }

    public function testABumpedCredentialsVersionInvalidatesAnAlreadyIssuedJwtImmediately(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'jwtver.bump@example.com', 'correct-horse-battery');
        $accessToken = $this->loginUser($client, 'jwtver.bump@example.com', 'correct-horse-battery');

        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken]);
        self::assertResponseStatusCodeSame(200);

        $container = static::getContainer();
        $user = $container->get(UserRepository::class)->findOneByEmail('jwtver.bump@example.com');
        self::assertNotNull($user);
        $user->bumpCredentialsVersion();
        $container->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken]);
        self::assertResponseStatusCodeSame(401, 'A version bump — whatever its cause — must reject an already-issued JWT right away.');
    }

    public function testANewlyIssuedJwtAfterTheBumpWorksAgain(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'jwtver.relogin@example.com', 'correct-horse-battery');
        $this->loginUser($client, 'jwtver.relogin@example.com', 'correct-horse-battery');

        $container = static::getContainer();
        $user = $container->get(UserRepository::class)->findOneByEmail('jwtver.relogin@example.com');
        self::assertNotNull($user);
        $user->bumpCredentialsVersion();
        $container->get(EntityManagerInterface::class)->flush();

        $freshToken = $this->loginUser($client, 'jwtver.relogin@example.com', 'correct-horse-battery');
        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$freshToken]);

        self::assertResponseStatusCodeSame(200, 'A JWT issued after the bump must embed the new version and work.');
    }

    /**
     * Migration scenario at deploy time: a JWT issued by the previous
     * version of MedVue, before this feature existed, carries no
     * credentialsVersion claim at all. It must be rejected exactly like a
     * mismatched version — never treated as "matching the current value"
     * — and, crucially, the still-valid refresh cookie from that same
     * pre-deployment session must transparently mint a working
     * replacement, so existing sessions migrate without a forced logout.
     *
     * The encoder is used directly (bypassing
     * JWTTokenManagerInterface::create()/createFromPayload(), which both
     * unconditionally dispatch JWT_CREATED — the very event that adds the
     * claim) to produce a token that is cryptographically valid but has
     * exactly the shape a pre-migration JWT would have had.
     */
    public function testAPreDeploymentJwtWithNoClaimAtAllIsRejectedButRefreshStillMigratesTheSession(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'jwtver.migration@example.com', 'correct-horse-battery');
        // A real login to obtain a valid refresh cookie for this account —
        // the pre-deployment session's surviving half.
        $this->loginUser($client, 'jwtver.migration@example.com', 'correct-horse-battery');

        $encoder = static::getContainer()->get(JWTEncoderInterface::class);
        $legacyToken = $encoder->encode([
            'username' => 'jwtver.migration@example.com',
            'roles' => ['ROLE_USER'],
            'iat' => time(),
            'exp' => time() + 900,
            // Deliberately no JwtCredentialsVersionListener::CLAIM key.
        ]);

        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$legacyToken]);
        self::assertResponseStatusCodeSame(401, 'A JWT with no credentialsVersion claim at all must never be treated as matching the current version.');

        // The frontend's apiClient reacts to that 401 with exactly one
        // silent refresh (unaffected by this feature: the refresh cookie
        // is still valid) and replays the request with the new token —
        // exercised here at the HTTP level directly.
        $client->request('POST', '/api/token/refresh');
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $migratedToken = $data['token'];

        $payload = static::getContainer()->get(JWTTokenManagerInterface::class)->parse($migratedToken);
        self::assertArrayHasKey(JwtCredentialsVersionListener::CLAIM, $payload, 'The freshly minted token must carry the claim.');

        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$migratedToken]);
        self::assertResponseStatusCodeSame(200, 'The migrated token must work: existing sessions survive the deploy transparently.');
    }
}
