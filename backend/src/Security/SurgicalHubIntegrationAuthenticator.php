<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates SurgicalHub's server on /api/integrations/surgicalhub/
 * (security.yaml, its own firewall — docs/surgicalhub-integration.md §3.1).
 *
 * A static Bearer secret dedicated to this one direction (SurgicalHub →
 * MedVue). Only its SHA-256 is configured here (SURGICALHUB_INBOUND_TOKEN_SHA256,
 * comma-separated so a rotation can accept the old and the new value for a
 * while); the presented secret is hashed and compared in constant time.
 * No secret configured = the integration is closed: every request is 401.
 *
 * Never a JWT and never a User: a MedVue access token is refused here, and
 * this secret is refused everywhere else (the `api` firewall only knows JWTs).
 */
final class SurgicalHubIntegrationAuthenticator extends AbstractAuthenticator
{
    public const ROLE = 'ROLE_SURGICALHUB_INTEGRATION';

    /** @var list<string> */
    private readonly array $acceptedHashes;

    public function __construct(
        #[Autowire(env: 'SURGICALHUB_INBOUND_TOKEN_SHA256')]
        string $acceptedHashes,
    ) {
        $this->acceptedHashes = array_values(array_filter(
            array_map(static fn (string $hash): string => strtolower(trim($hash)), explode(',', $acceptedHashes)),
            static fn (string $hash): bool => 1 === preg_match('/^[0-9a-f]{64}$/', $hash),
        ));
    }

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (1 !== preg_match('/^Bearer (\S{32,512})$/', $header, $matches)) {
            throw new CustomUserMessageAuthenticationException('Missing integration credentials.');
        }

        $presented = hash('sha256', $matches[1]);
        $accepted = false;
        foreach ($this->acceptedHashes as $hash) {
            // No early exit: the time taken does not depend on which hash matched.
            $accepted = hash_equals($hash, $presented) || $accepted;
        }
        if (!$accepted) {
            throw new CustomUserMessageAuthenticationException('Invalid integration credentials.');
        }

        return new SelfValidatingPassport(new UserBadge(
            'integration:surgicalhub',
            static fn (): IntegrationClient => new IntegrationClient('surgicalhub', self::ROLE),
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'unauthorized', 'message' => 'Invalid integration credentials.'], 401);
    }
}
