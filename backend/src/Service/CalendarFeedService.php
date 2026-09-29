<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarFeed;
use App\Entity\User;
use App\Repository\CalendarFeedRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Lifecycle of a User's calendar subscription address (docs/decisions.md
 * D170). Every write locks the User row first: two concurrent requests of
 * the same person serialize, so the "one active feed per user" index is
 * never what turns a double click into a 500.
 */
final class CalendarFeedService
{
    public function __construct(
        private readonly CalendarFeedRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function activeOf(User $user): ?CalendarFeed
    {
        return $this->repository->findActiveOf($user);
    }

    /** The active feed, created on first use — never a second address for the same person. */
    public function enable(User $user): CalendarFeed
    {
        return $this->entityManager->wrapInTransaction(function () use ($user): CalendarFeed {
            $this->entityManager->lock($user, LockMode::PESSIMISTIC_WRITE);

            return $this->repository->findActiveOf($user) ?? $this->create($user);
        });
    }

    /** A new address; the previous one stops working at once (a link shared by mistake). */
    public function regenerate(User $user): CalendarFeed
    {
        return $this->entityManager->wrapInTransaction(function () use ($user): CalendarFeed {
            $this->entityManager->lock($user, LockMode::PESSIMISTIC_WRITE);
            $this->revokeActive($user);

            return $this->create($user);
        });
    }

    public function disable(User $user): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user): void {
            $this->entityManager->lock($user, LockMode::PESSIMISTIC_WRITE);
            $this->revokeActive($user);
        });
    }

    /**
     * The owner of an active, usable feed — null for an unknown or revoked
     * token and for a deactivated account, never told apart.
     */
    public function resolve(string $token): ?User
    {
        $feed = $this->repository->findActiveByToken($token);
        if (null === $feed || !$feed->getUser()->isActive()) {
            return null;
        }

        if ($feed->recordFetch($this->clock->now())) {
            $this->entityManager->flush();
        }

        return $feed->getUser();
    }

    private function create(User $user): CalendarFeed
    {
        $feed = new CalendarFeed($user, CalendarFeed::generateToken(), $this->clock->now());
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function revokeActive(User $user): void
    {
        $active = $this->repository->findActiveOf($user);
        if (null !== $active) {
            $active->revoke($this->clock->now());
            // Flushed before any insert: the partial unique index sees the revocation first.
            $this->entityManager->flush();
        }
    }
}
