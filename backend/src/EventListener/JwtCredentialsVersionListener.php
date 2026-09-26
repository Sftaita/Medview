<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Makes User::$credentialsVersion actually invalidate already-issued,
 * stateless JWTs (docs/decisions.md D142) — the missing piece revoking
 * refresh tokens alone doesn't cover, since a short-lived access token
 * needs no refresh token to keep working until it naturally expires.
 *
 * Two hooks, both systemic (no per-controller/per-route check anywhere):
 *  - JWT_CREATED: every JWT this app ever issues (login, refresh — both go
 *    through JWTTokenManagerInterface::create()) embeds the user's current
 *    version as a custom claim.
 *  - JWT_AUTHENTICATED: dispatched by JWTAuthenticator::createToken(),
 *    *after* the user has been loaded from the claimed identity, on every
 *    authenticated /api request. A version mismatch throws
 *    AuthenticationException here, which — because this fires from inside
 *    the authenticator's authenticate/createToken flow — Symfony Security
 *    turns into the same clean 401 as any other authentication failure
 *    (JWTAuthenticator::onAuthenticationFailure(), see the JWT_INVALID
 *    event), with no bespoke error handling needed.
 *
 * A JWT that predates this feature (no claim at all) is rejected the same
 * way a stale version would be: there is nothing to trust it against. In
 * practice this only affects access tokens already in flight at deploy
 * time — at most JWT_TOKEN_TTL (15 minutes) of disruption, the same window
 * every other "log out everywhere" limitation already lives with.
 */
final class JwtCredentialsVersionListener
{
    public const CLAIM = 'credentialsVersion';

    #[AsEventListener(event: Events::JWT_CREATED)]
    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $event->setData([...$event->getData(), self::CLAIM => $user->getCredentialsVersion()]);
    }

    #[AsEventListener(event: Events::JWT_AUTHENTICATED)]
    public function onJwtAuthenticated(JWTAuthenticatedEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if (!$user instanceof User) {
            return;
        }

        $claim = $event->getPayload()[self::CLAIM] ?? null;
        if ($claim !== $user->getCredentialsVersion()) {
            throw new AuthenticationException('This token was issued before the account\'s credentials last changed.');
        }
    }
}
