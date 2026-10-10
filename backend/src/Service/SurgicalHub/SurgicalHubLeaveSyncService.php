<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

use App\Entity\SurgicalHubImportedLeave;
use App\Entity\SurgicalHubLink;
use App\Repository\SurgicalHubImportedLeaveRepository;
use App\Repository\SurgicalHubLinkRepository;
use App\Service\UserAvailabilityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Brings one person's SurgicalHub leave into their MedVue calendar
 * (docs/surgicalhub-integration.md §7, docs/decisions.md D183).
 *
 * 1. Read a complete snapshot of the window (SurgicalHubApiClient validates
 *    it entirely) — outside any transaction, nothing written yet.
 * 2. One transaction, the association row locked (concurrent syncs, or a
 *    sync racing a revocation, serialize there): create what is new, move
 *    what changed, remove what disappeared — only among the imports of this
 *    pair of accounts that intersect the window, only from a complete
 *    snapshot. Unchanged absences are not written at all.
 *
 * Every write goes through UserAvailabilityService's import*() methods, so
 * open collections note the change (never as an answer), and a MANUAL period
 * is structurally out of reach. An import entirely outside the window is
 * never touched (D6: the window is not a retention policy).
 *
 * A failure writes nothing but the failure itself on the association;
 * SurgicalHub saying it no longer knows the association revokes it (D9).
 */
final class SurgicalHubLeaveSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SurgicalHubApiClient $client,
        private readonly SurgicalHubLinkRepository $linkRepository,
        private readonly SurgicalHubImportedLeaveRepository $importRepository,
        private readonly UserAvailabilityService $availabilityService,
        private readonly SurgicalHubLinkService $linkService,
        private readonly SurgicalHubCalendar $calendar,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param float|null $timeoutSeconds a tighter bound than the client's default (the launch path, D8)
     */
    public function sync(SurgicalHubLink $link, ?float $timeoutSeconds = null): SurgicalHubSyncOutcome
    {
        if (!$link->isActive()) {
            return new SurgicalHubSyncOutcome(SurgicalHubSyncStatus::INACTIVE);
        }

        $now = $this->now();
        [$from, $to] = $this->calendar->window($now);

        try {
            $snapshot = $this->client->fetchAbsences((string) $link->getStableId(), $from, $to, $timeoutSeconds);
        } catch (SurgicalHubLinkGoneException $gone) {
            if (SurgicalHubLinkGoneException::NOT_FOUND === $gone->reason) {
                // A doubt, never a mass deletion (§9): a restored SurgicalHub backup answers this for every link.
                $this->linkService->suspend($link);

                return new SurgicalHubSyncOutcome(SurgicalHubSyncStatus::SUSPENDED);
            }

            $removed = $this->linkService->revokeRemotely($link);

            return new SurgicalHubSyncOutcome(SurgicalHubSyncStatus::REVOKED, removed: $removed);
        } catch (SurgicalHubSyncFailedException $exception) {
            $this->entityManager->wrapInTransaction(function () use ($link, $exception, $now): void {
                $this->linkRepository->lock($link);
                if ($link->isActive()) {
                    $link->recordSyncFailure($exception->error->value, $now);
                }
            });

            return new SurgicalHubSyncOutcome(SurgicalHubSyncStatus::FAILED, $exception->error);
        }

        return $this->entityManager->wrapInTransaction(function () use ($link, $snapshot, $now): SurgicalHubSyncOutcome {
            $this->linkRepository->lock($link);
            if (!$link->isActive()) {
                // Revoked while SurgicalHub was being read: its answer is no longer ours to apply.
                return new SurgicalHubSyncOutcome(SurgicalHubSyncStatus::INACTIVE);
            }

            [$created, $updated, $removed] = $this->reconcile($link, $snapshot, $now);
            $link->recordSyncSuccess($now);
            $this->entityManager->flush();

            return new SurgicalHubSyncOutcome(SurgicalHubSyncStatus::SYNCED, null, $created, $updated, $removed);
        });
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function reconcile(SurgicalHubLink $link, SurgicalHubAbsenceSnapshot $snapshot, \DateTimeImmutable $now): array
    {
        $known = $this->importRepository->findForPairOf($link);
        $created = $updated = $removed = 0;

        // Never the array keys as identifiers: PHP turns a numeric-string key ("8120") into an int.
        foreach ($snapshot->absences as $absence) {
            $id = $absence->id;
            if (!$absence->isConfirmed()) {
                // D1: anything but CONFIRMED never excludes anyone — handled below as absent.
                continue;
            }

            $import = $known[$id] ?? null;
            unset($known[$id]);
            [$startsAt, $endsAt] = SurgicalHubCalendar::instants($absence->startDate, $absence->endDate);

            if (null === $import) {
                $period = $this->availabilityService->importCreate($link->getUser(), $startsAt, $endsAt);
                $this->entityManager->persist(new SurgicalHubImportedLeave($link, $id, $period, $absence->startDate, $absence->endDate, $now));
                ++$created;
                continue;
            }

            if ($import->getLink() !== $link) {
                $import->adoptBy($link);
            }
            if ($import->describes($absence->startDate, $absence->endDate)) {
                continue;
            }

            $this->availabilityService->importReschedule($import->getPeriod(), $startsAt, $endsAt);
            $import->redescribe($absence->startDate, $absence->endDate, $now);
            ++$updated;
        }

        // What SurgicalHub no longer lists (deleted, or no longer CONFIRMED) —
        // only inside the window: an import outside it was simply not asked about.
        foreach ($known as $import) {
            if (!$import->intersects($snapshot->from, $snapshot->to)) {
                continue;
            }

            $this->entityManager->remove($import);
            $this->availabilityService->importDelete($import->getPeriod());
            ++$removed;
        }

        $this->entityManager->flush();

        return [$created, $updated, $removed];
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
