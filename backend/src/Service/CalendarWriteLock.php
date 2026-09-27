<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Serializes every write to one Planning's current calendar
 * (docs/decisions.md D144): a manual reassignment, a removal, the
 * persistence step of "Compléter automatiquement" and the snapshot a
 * publication takes of the calendar. A Postgres transaction-level advisory
 * lock — released automatically at commit/rollback, so it can never leak —
 * in its own namespace, distinct from the generation (D129) and publication
 * (D133) session locks.
 *
 * Why it is needed on top of D131's identity check: two managers saving the
 * same block at the same instant both read "current = Dupont" before either
 * commits, and both pass the check. With the lock taken *before* reading,
 * the second waits, then reads the first one's result and gets a clean 409
 * (StaleReassignmentException) instead of a unique-index violation.
 *
 * Must be called inside an open transaction.
 */
final class CalendarWriteLock
{
    private const ADVISORY_LOCK_NAMESPACE = 7354;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function acquire(Planning $planning): void
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('CalendarWriteLock::acquire() must be called inside a transaction.');
        }

        $connection->fetchOne(
            'SELECT pg_advisory_xact_lock(:namespace, :planning)',
            ['namespace' => self::ADVISORY_LOCK_NAMESPACE, 'planning' => $planning->getId()],
        );
    }
}
