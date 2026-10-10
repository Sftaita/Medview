<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

use App\Entity\Planning;
use App\Entity\SurgicalHubLink;
use App\Entity\SurgicalHubLinkEvent;
use App\Entity\SurgicalHubLinkEventKind;
use App\Entity\User;
use App\Exception\SurgicalHubDataStaleException;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\SurgicalHubLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * SurgicalHub leave before a generation (docs/surgicalhub-integration.md §7.5,
 * decision D8). Runs in the launch request, before the job is queued — never
 * in the OR-Tools worker (D7) — so the snapshot the worker takes next reads
 * leave as fresh as SurgicalHub could give it.
 *
 * For every participant of the planning with an ACTIVE association, a
 * synchronisation is always attempted. When it fails:
 *  - last success less than SURGICALHUB_FRESHNESS_HOURS ago: a warning;
 *  - otherwise, or never synchronised: blocking — unless the planning's
 *    creator explicitly overrides, which is journaled per person and never
 *    recorded as a successful synchronisation.
 * People without an association are never concerned.
 */
final class SurgicalHubFreshnessGate
{
    public function __construct(
        private readonly PlanningTeamMemberRepository $memberRepository,
        private readonly SurgicalHubLinkRepository $linkRepository,
        private readonly SurgicalHubLeaveSyncService $syncService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'int:SURGICALHUB_FRESHNESS_HOURS')]
        private readonly int $freshnessHours,
        /** Per call, at launch (4 s): much shorter than the periodic synchronisation's 10 s. */
        #[Autowire(env: 'float:SURGICALHUB_LAUNCH_TIMEOUT_SECONDS')]
        private readonly float $launchTimeoutSeconds,
        /** For all the calls of one launch together (20 s). */
        #[Autowire(env: 'float:SURGICALHUB_LAUNCH_BUDGET_SECONDS')]
        private readonly float $launchBudgetSeconds,
    ) {
    }

    /**
     * Refreshes, then refuses a launch with blocking participants unless the
     * planning's creator explicitly overrides (then recordOverride() must
     * follow once the job exists).
     *
     * @throws SurgicalHubDataStaleException blocking participants, and no (allowed) override
     */
    public function check(Planning $planning, User $launchedBy, bool $override): SurgicalHubFreshnessReport
    {
        $report = $this->refresh($planning);
        if ([] === $report->blocking()) {
            return $report;
        }

        if (!$override) {
            throw new SurgicalHubDataStaleException($report);
        }
        if ($launchedBy !== $planning->getCreator()) {
            throw new SurgicalHubDataStaleException($report, overrideRefused: true);
        }

        return $report;
    }

    /**
     * Journals the override, one event per blocking participant, attached to
     * the queued generation job. Never touches lastSuccessfulSyncAt.
     */
    public function recordOverride(SurgicalHubFreshnessReport $report, Planning $planning, User $launchedBy, string $jobStableId): void
    {
        $blocking = $report->blocking();
        if ([] === $blocking) {
            return;
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
        foreach ($blocking as $participant) {
            $this->entityManager->persist(new SurgicalHubLinkEvent(
                $participant->user,
                SurgicalHubLinkEventKind::STALE_DATA_OVERRIDDEN,
                $now,
                $participant->link,
                $participant->link->getSurgicalHubUserId(),
                trim($launchedBy->getFirstName().' '.$launchedBy->getLastName()),
                [
                    'planningStableId' => (string) $planning->getStableId(),
                    'jobStableId' => $jobStableId,
                    'launchedByStableId' => (string) $launchedBy->getStableId(),
                    'lastSuccessfulSyncAt' => $participant->link->getLastSuccessfulSyncAt()?->format(\DATE_ATOM),
                    'error' => $participant->error?->value,
                ],
            ));
        }
        $this->entityManager->flush();
    }

    /**
     * The participants with a current (ACTIVE or SUSPENDED) association, and
     * that association — what the preflight shows, without calling SurgicalHub.
     *
     * @return list<array{0: User, 1: SurgicalHubLink}>
     */
    public function associatedParticipants(Planning $planning): array
    {
        // Every person with a membership in the planning's period, once — whatever their number of lines.
        $users = [];
        foreach ($this->memberRepository->findIntersectingForPlanning($planning, $planning->getStartsAt(), $planning->getEndsAt()) as $member) {
            $users[$member->getUser()->getId()] = $member->getUser();
        }

        $associated = [];
        foreach ($users as $user) {
            $link = $this->linkRepository->findCurrentForUser($user);
            if (null !== $link) {
                $associated[] = [$user, $link];
            }
        }

        return $associated;
    }

    /**
     * Synchronises every ACTIVE associated participant and classifies them all
     * — writes nothing else. Bounded in time, since it runs inside the launch
     * request: a short timeout per call, and once SurgicalHub looks down (the
     * first failure that is not about one person's data) or the overall budget
     * is spent, the remaining people are not called — they are classified by
     * their last success like a failed refresh. A SUSPENDED association is
     * never called (§9): its leave is as old as its last success.
     */
    public function refresh(Planning $planning): SurgicalHubFreshnessReport
    {
        $participants = [];
        $deadline = hrtime(true) + (int) ($this->launchBudgetSeconds * 1e9);
        $outage = null;

        foreach ($this->associatedParticipants($planning) as [$user, $link]) {
            if ($link->isSuspended()) {
                $participants[] = $this->stale($user, $link, SurgicalHubSyncError::LINK_SUSPENDED);
                continue;
            }
            if (null === $outage && hrtime(true) >= $deadline) {
                $outage = SurgicalHubSyncError::UNREACHABLE;
            }
            if (null !== $outage) {
                $participants[] = $this->stale($user, $link, $outage);
                continue;
            }

            $outcome = $this->syncService->sync($link, $this->launchTimeoutSeconds);
            match ($outcome->status) {
                SurgicalHubSyncStatus::SYNCED => $participants[] = new SurgicalHubParticipantFreshness($user, $link, SurgicalHubFreshness::FRESH, null),
                SurgicalHubSyncStatus::SUSPENDED => $participants[] = $this->stale($user, $link, SurgicalHubSyncError::LINK_SUSPENDED),
                // Revoked meanwhile: no longer associated, nothing left to be stale.
                SurgicalHubSyncStatus::REVOKED, SurgicalHubSyncStatus::INACTIVE => null,
                SurgicalHubSyncStatus::FAILED => $participants[] = $this->stale($user, $link, $outcome->error),
            };

            if (SurgicalHubSyncStatus::FAILED === $outcome->status && null !== $outcome->error && self::isOutage($outcome->error)) {
                $outage = $outcome->error;
            }
        }

        return new SurgicalHubFreshnessReport($participants);
    }

    private function stale(User $user, SurgicalHubLink $link, ?SurgicalHubSyncError $error): SurgicalHubParticipantFreshness
    {
        return new SurgicalHubParticipantFreshness($user, $link, $this->isRecent($link->getLastSuccessfulSyncAt())
            ? SurgicalHubFreshness::STALE_RECENT
            : SurgicalHubFreshness::STALE_BLOCKING, $error);
    }

    /** A failure about SurgicalHub itself, not about one person's data: asking the others is pointless. */
    private static function isOutage(SurgicalHubSyncError $error): bool
    {
        return !\in_array($error, [SurgicalHubSyncError::INVALID_RESPONSE, SurgicalHubSyncError::WINDOW_TOO_LARGE], true);
    }

    private function isRecent(?\DateTimeImmutable $lastSuccess): bool
    {
        if (null === $lastSuccess) {
            return false;
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));

        return $lastSuccess > $now->modify(sprintf('-%d hours', $this->freshnessHours));
    }
}
