<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\ExclusionReason;
use App\Entity\Duty;
use App\Entity\DutySwapAudience;
use App\Entity\DutySwapEvent;
use App\Entity\DutySwapEventType;
use App\Entity\DutySwapKind;
use App\Entity\DutySwapNotification;
use App\Entity\DutySwapNotificationKind;
use App\Entity\DutySwapProposal;
use App\Entity\DutySwapProposalStatus;
use App\Entity\DutySwapRequest;
use App\Entity\DutySwapRequestStatus;
use App\Entity\PlanningPeriodStatus;
use App\Entity\User;
use App\Exception\DutySwapException;
use App\Exception\DutySwapNotApplicableException;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\DutySwapProposalRepository;
use App\Repository\DutySwapRequestRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The duty swap workflow between members (docs/duty-swaps.md,
 * docs/decisions.md D178) — requests, proposals, decisions — and the one
 * moment it touches the calendar: accept(), which hands the swap itself to
 * DutyReassignmentService::applySwap() (the calendar's single write path,
 * D131/D144 — never a parallel reassignment engine).
 *
 * The rule everything here follows: a request or a proposal, whatever its
 * status, NEVER changes who holds a duty. Until accept() commits, the
 * requester holds their duty and remains responsible for it;
 * DutyAssignment.current is the only source of truth.
 *
 * Concurrency (docs/duty-swaps.md §11): every step that changes a request
 * or its proposals first locks the request row (SELECT … FOR UPDATE) and
 * re-reads it, so two steps on one request are serialized and the second
 * sees the first one's result. accept() takes the Planning's
 * CalendarWriteLock BEFORE the request row (always that order, and only
 * accept() takes the calendar lock — so no two steps can wait on each
 * other in a cycle): two acceptances on one planning are serialized, and
 * the second one revalidates against the first one's swap. A double click
 * on "Accepter" returns the already-applied swap, never a second one.
 *
 * Emails are outbox rows written in the step's own transaction and sent
 * only after its commit (DutySwapNotificationSender) — an email failure
 * never undoes a step, a rolled-back step never sends anything.
 */
final class DutySwapService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DutySwapRequestRepository $requestRepository,
        private readonly DutySwapProposalRepository $proposalRepository,
        private readonly DutyRepository $dutyRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly PlanningLineRepository $lineRepository,
        private readonly UserRepository $userRepository,
        private readonly ReassignmentCandidateService $candidateService,
        private readonly DutyReassignmentService $reassignmentService,
        private readonly CalendarWriteLock $calendarWriteLock,
        private readonly DutySwapAccess $access,
        private readonly DutySwapUnitDescriber $describer,
        private readonly DutySwapNotificationSender $notificationSender,
        private readonly ExclusionReasonLabeler $reasonLabeler,
        private readonly ClockInterface $clock,
    ) {
    }

    // ---------------------------------------------------------------------------------------------
    // Reading the calendar
    // ---------------------------------------------------------------------------------------------

    /**
     * The unit $duty belongs to, as $expectedHolder holds it right now — or
     * the reason it cannot be swapped (not published, not theirs, started,
     * locked).
     *
     * @throws DutySwapException
     */
    public function heldUnit(Duty $duty, User $expectedHolder, bool $own = true): HeldDutyUnit
    {
        $line = $this->lineRepository->findOneByPlanningPeriod($duty->getPlanningPeriod());
        if (null === $line || !$line->isActive() || PlanningPeriodStatus::PUBLISHED !== $duty->getPlanningPeriod()->getStatus()) {
            throw DutySwapException::conflict('duty_not_swappable', 'Seules les gardes d\'un planning publié peuvent être échangées.');
        }

        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($duty->getPlanningPeriod());
        $block = $this->candidateService->blockDuties($duty);
        $current = null !== $generation ? $this->assignmentRepository->findCurrentByGenerationAndDuties($generation, $block) : [];
        $holder = $this->candidateService->currentBlockTeamMember($block, $current);
        if (null === $holder || $holder->getUser() !== $expectedHolder) {
            throw $own ? DutySwapException::conflict('duty_not_held', 'Cette garde ne vous est pas attribuée.') : DutySwapException::conflict('duty_not_held', 'Cette garde n\'est pas attribuée à la personne choisie.');
        }

        $unit = new HeldDutyUnit($line, $holder, $block[0], $current[(int) $block[0]->getId()], $block);
        if ($unit->startsAt() <= $this->now()) {
            throw DutySwapException::conflict('duty_started', 'Cette garde a déjà commencé : elle ne peut plus être échangée.');
        }
        foreach ($current as $row) {
            if ($row->isLocked()) {
                throw DutySwapException::conflict('duty_locked', 'Cette garde est verrouillée par le gestionnaire du planning.');
            }
        }

        return $unit;
    }

    /**
     * @throws DutySwapException
     */
    public function dutyByStableId(string $stableId): Duty
    {
        return $this->dutyRepository->findOneByStableId($stableId)
            ?? throw new DutySwapException('not_found', 404, 'Garde introuvable.');
    }

    // ---------------------------------------------------------------------------------------------
    // Workflow steps
    // ---------------------------------------------------------------------------------------------

    /**
     * Opens a request on a unit $actor holds. AGREED: "J'ai déjà convenu
     * d'un échange" — $counterpartDutyStableId names the colleague's unit,
     * its holder is the one recipient, and the one proposal is written now
     * (authored by $actor, decided by the colleague). SEARCH: SELECTED
     * recipients or the whole line (ALL) may then propose.
     *
     * Nothing in the calendar changes. $acknowledgedResponsibility must be
     * true: the member confirmed they read that they remain responsible for
     * their duty until a swap is confirmed (docs/duty-swaps.md §1).
     *
     * @param list<string> $recipientUserStableIds
     *
     * @throws DutySwapException
     */
    public function createRequest(
        User $actor,
        string $dutyStableId,
        DutySwapKind $kind,
        DutySwapAudience $audience,
        array $recipientUserStableIds,
        ?string $counterpartDutyStableId,
        bool $acknowledgedResponsibility,
    ): DutySwapRequest {
        if (!$acknowledgedResponsibility) {
            throw DutySwapException::invalid('responsibility_not_acknowledged', 'Vous devez confirmer avoir pris connaissance de votre responsabilité sur votre garde.');
        }

        $now = $this->now();
        $unit = $this->heldUnit($this->dutyByStableId($dutyStableId), $actor);

        $counterpartUnit = null;
        if (DutySwapKind::AGREED === $kind) {
            if (null === $counterpartDutyStableId) {
                throw DutySwapException::invalid('counterpart_required', 'Choisissez la garde de votre collègue.');
            }
            $counterpartDuty = $this->dutyByStableId($counterpartDutyStableId);
            $recipients = $this->resolveRecipients($unit, $recipientUserStableIds, $actor);
            if (1 !== \count($recipients)) {
                throw DutySwapException::invalid('invalid_recipients', 'Un échange convenu se fait avec un seul collègue.');
            }
            $counterpartUnit = $this->heldUnit($counterpartDuty, $recipients[0], own: false);
            if ($counterpartUnit->line !== $unit->line) {
                throw DutySwapException::invalid('other_line', 'Les deux gardes doivent appartenir à la même ligne de garde.');
            }
            $audience = DutySwapAudience::SELECTED;
        } elseif (DutySwapAudience::SELECTED === $audience) {
            $recipients = $this->resolveRecipients($unit, $recipientUserStableIds, $actor);
            if ([] === $recipients) {
                throw DutySwapException::invalid('invalid_recipients', 'Choisissez au moins un collègue.');
            }
        } else {
            $recipients = [];
        }

        $request = new DutySwapRequest($unit->line, $unit->holder, $unit->representative, $unit->assignment, $kind, $audience, $recipients, $unit->startsAt(), $now);
        $proposal = null;
        if (null !== $counterpartUnit) {
            $proposal = new DutySwapProposal($request, $actor, $counterpartUnit->assignment, $now);
            $this->precheck($proposal, $now);
        }

        $notifications = $this->inTransaction(function () use ($request, $proposal, $actor, $now, $unit, $recipients): array {
            $this->entityManager->persist($request);
            $offered = $this->describer->describe($unit->representative, $unit->line);
            $this->record($request, null, DutySwapEventType::REQUEST_CREATED, $actor, $now, ['kind' => $request->getKind()->value, 'audience' => $request->getAudience()->value, 'offered' => $offered]);

            $notifications = [];
            if (null !== $proposal) {
                $this->entityManager->persist($proposal);
                $this->record($request, $proposal, DutySwapEventType::PROPOSAL_CREATED, $actor, $now, ['counterpart' => $this->describer->describe($proposal->getCounterpartDuty(), $unit->line), 'counterpartName' => self::name($proposal->getCounterpart())]);
                $notifications[] = $this->notify($request, $proposal, $proposal->getCounterpart(), DutySwapNotificationKind::AGREED_PROPOSAL_RECEIVED, $now);
            } else {
                $audienceUsers = DutySwapAudience::ALL === $request->getAudience()
                    ? array_map(static fn ($member): User => $member->getUser(), $this->access->currentLineMembers($unit->line))
                    : $recipients;
                foreach ($audienceUsers as $user) {
                    if ($user !== $actor && $user->isActive()) {
                        $notifications[] = $this->notify($request, null, $user, DutySwapNotificationKind::REQUEST_RECEIVED, $now);
                    }
                }
            }

            $this->record($request, null, DutySwapEventType::REQUEST_SENT, $actor, $now, [
                'audience' => $request->getAudience()->value,
                'recipients' => array_map(static fn (User $user): array => ['userStableId' => (string) $user->getStableId(), 'name' => self::name($user)], $recipients),
                'notifiedCount' => \count($notifications),
            ]);

            return $notifications;
        }, onUniqueViolation: static fn () => DutySwapException::conflict('already_requested', 'Une demande d\'échange est déjà en cours pour cette garde.'));

        $this->notificationSender->sendNow($notifications);

        return $request;
    }

    /**
     * A recipient (or, for ALL, a current member of the line) offers one of
     * their own units in exchange. Nothing in the calendar changes.
     *
     * @throws DutySwapException
     */
    public function propose(User $actor, DutySwapRequest $request, string $dutyStableId): DutySwapProposal
    {
        if (DutySwapKind::SEARCH !== $request->getKind()) {
            throw DutySwapException::conflict('not_proposable', 'Cette demande porte déjà sur un échange convenu.');
        }
        if (!$this->access->isAudience($request, $actor)) {
            throw DutySwapException::forbidden('Cette demande d\'échange ne vous est pas adressée.');
        }

        $now = $this->now();
        $unit = $this->heldUnit($this->dutyByStableId($dutyStableId), $actor);
        if ($unit->line !== $request->getLine()) {
            throw DutySwapException::invalid('other_line', 'La garde proposée doit appartenir à la même ligne de garde.');
        }

        [$proposal, $notifications] = $this->inTransaction(function () use ($actor, $request, $unit, $now): array {
            $this->lockRequest($request);
            $this->assertOpen($request);
            foreach ($this->pendingProposals($request) as $existing) {
                if ($existing->getAuthor() === $actor) {
                    throw DutySwapException::conflict('already_proposed', 'Vous avez déjà une proposition en attente sur cette demande. Retirez-la pour en faire une autre.');
                }
            }

            $proposal = new DutySwapProposal($request, $actor, $unit->assignment, $now);
            $this->precheck($proposal, $now);
            $this->entityManager->persist($proposal);
            $this->record($request, $proposal, DutySwapEventType::PROPOSAL_CREATED, $actor, $now, ['counterpart' => $this->describer->describe($unit->representative, $unit->line), 'counterpartName' => self::name($actor)]);

            return [$proposal, [$this->notify($request, $proposal, $request->getRequester(), DutySwapNotificationKind::PROPOSAL_RECEIVED, $now)]];
        }, onUniqueViolation: static fn () => DutySwapException::conflict('already_proposed', 'Vous avez déjà une proposition en attente sur cette demande.'));

        $this->notificationSender->sendNow($notifications);

        return $proposal;
    }

    /**
     * The decider accepts: the one moment the calendar changes. Revalidated
     * in full, under the calendar lock, against the state of right now —
     * and applied atomically (both units or neither). A refused
     * revalidation writes nothing, records SWAP_VALIDATION_FAILED, and
     * answers with the reason.
     *
     * @return bool true when this call applied the swap, false when it had already been applied (a repeated click)
     *
     * @throws DutySwapException
     */
    public function accept(User $actor, DutySwapProposal $proposal): bool
    {
        $request = $proposal->getRequest();
        if ($proposal->getDecider() !== $actor) {
            throw DutySwapException::forbidden('Seule la personne à qui cette proposition est adressée peut l\'accepter.');
        }

        $now = $this->now();
        try {
            $result = $this->inTransaction(function () use ($actor, $proposal, $request, $now): array {
                // Always the calendar lock first, then the request row (see class docblock).
                $this->calendarWriteLock->acquire($request->getPlanning());
                $this->lockRequest($request);
                $this->entityManager->refresh($proposal);

                if (DutySwapProposalStatus::ACCEPTED === $proposal->getStatus()) {
                    return [false, []];
                }
                $this->assertOpen($request);
                if (!$proposal->isPending()) {
                    throw DutySwapException::conflict('proposal_closed', 'Cette proposition n\'est plus en attente.');
                }
                // Read before any change: pendingProposals() re-reads rows from the database.
                $others = array_values(array_filter($this->pendingProposals($request), static fn (DutySwapProposal $p): bool => $p !== $proposal));

                $swap = $this->reassignmentService->applySwap($proposal, $actor, $now);

                $proposal->accept($actor, $now);
                $request->complete($proposal, $now);
                $this->record($request, $proposal, DutySwapEventType::PROPOSAL_ACCEPTED, $actor, $now);
                $requesterTakes = $this->describer->describe($swap->counterpartBlock[0], $request->getLine());
                $counterpartTakes = $this->describer->describe($swap->offeredBlock[0], $request->getLine());
                $this->record($request, $proposal, DutySwapEventType::SWAP_COMPLETED, $actor, $now, [
                    'requester' => ['userStableId' => (string) $request->getRequester()->getStableId(), 'name' => self::name($request->getRequester()), 'takes' => $requesterTakes],
                    'counterpart' => ['userStableId' => (string) $proposal->getCounterpart()->getStableId(), 'name' => self::name($proposal->getCounterpart()), 'takes' => $counterpartTakes],
                    'supersededAssignments' => array_map(static fn ($a): string => (string) $a->getStableId(), $swap->superseded),
                    'createdAssignments' => array_map(static fn ($a): string => (string) $a->getStableId(), $swap->created),
                ]);

                foreach ($others as $other) {
                    $other->close(DutySwapProposalStatus::NOT_SELECTED, $now);
                    $this->record($request, $other, DutySwapEventType::PROPOSAL_NOT_SELECTED, null, $now);
                }

                $this->obsoleteOthers($request, $swap, $now);

                $notifications = [
                    $this->notify($request, $proposal, $request->getRequester(), DutySwapNotificationKind::SWAP_CONFIRMED, $now),
                    $this->notify($request, $proposal, $proposal->getCounterpart(), DutySwapNotificationKind::SWAP_CONFIRMED, $now),
                ];

                return [true, $notifications];
            });
        } catch (DutySwapNotApplicableException $exception) {
            throw $this->recordValidationFailure((int) $proposal->getId(), (int) $actor->getId(), $exception);
        }

        [$applied, $notifications] = $result;
        $this->notificationSender->sendNow($notifications);

        return $applied;
    }

    /**
     * The decider says no. For an AGREED request this closes the request
     * (REFUSED); a SEARCH request stays open for other proposals. Nothing in
     * the calendar changes.
     *
     * @throws DutySwapException
     */
    public function refuse(User $actor, DutySwapProposal $proposal): void
    {
        $request = $proposal->getRequest();
        if ($proposal->getDecider() !== $actor) {
            throw DutySwapException::forbidden('Seule la personne à qui cette proposition est adressée peut la refuser.');
        }

        $now = $this->now();
        $notifications = $this->inTransaction(function () use ($actor, $proposal, $request, $now): array {
            $this->lockRequest($request);
            $this->entityManager->refresh($proposal);
            $this->assertOpen($request);
            if (!$proposal->isPending()) {
                throw DutySwapException::conflict('proposal_closed', 'Cette proposition n\'est plus en attente.');
            }

            $proposal->refuse($actor, $now);
            $this->record($request, $proposal, DutySwapEventType::PROPOSAL_REFUSED, $actor, $now);
            if (DutySwapKind::AGREED === $request->getKind()) {
                $request->refuse($now);
                $this->record($request, null, DutySwapEventType::REQUEST_REFUSED, $actor, $now);
            }

            return [$this->notify($request, $proposal, $proposal->getAuthor(), DutySwapNotificationKind::PROPOSAL_REFUSED, $now)];
        });

        $this->notificationSender->sendNow($notifications);
    }

    /**
     * The author takes a pending proposal back. An AGREED request's one
     * proposal is the request itself: withdrawing it cancels the request.
     *
     * @throws DutySwapException
     */
    public function withdraw(User $actor, DutySwapProposal $proposal): void
    {
        $request = $proposal->getRequest();
        if ($proposal->getAuthor() !== $actor) {
            throw DutySwapException::forbidden('Seul l\'auteur d\'une proposition peut la retirer.');
        }
        if (DutySwapKind::AGREED === $request->getKind()) {
            $this->cancel($actor, $request);

            return;
        }

        $now = $this->now();
        $this->inTransaction(function () use ($proposal, $request, $now, $actor): void {
            $this->lockRequest($request);
            $this->entityManager->refresh($proposal);
            if (!$proposal->isPending()) {
                throw DutySwapException::conflict('proposal_closed', 'Cette proposition n\'est plus en attente.');
            }
            $proposal->withdraw($now);
            $this->record($request, $proposal, DutySwapEventType::PROPOSAL_WITHDRAWN, $actor, $now);
        });
    }

    /**
     * The requester cancels an open request — never a completed one: an
     * applied swap is undone only by a new swap or a manager's explicit
     * reassignment, never by silently restoring the old holders.
     *
     * @throws DutySwapException
     */
    public function cancel(User $actor, DutySwapRequest $request): void
    {
        if ($request->getRequester() !== $actor) {
            throw DutySwapException::forbidden('Seule la personne qui a demandé l\'échange peut annuler la demande.');
        }

        $now = $this->now();
        $this->inTransaction(function () use ($actor, $request, $now): void {
            $this->lockRequest($request);
            if (DutySwapRequestStatus::COMPLETED === $request->getStatus()) {
                throw DutySwapException::conflict('swap_already_completed', 'L\'échange a déjà été enregistré : il ne peut plus être annulé. Un nouvel échange, ou une réattribution par le gestionnaire, est nécessaire.');
            }
            $this->assertOpen($request);

            foreach ($this->pendingProposals($request) as $proposal) {
                $proposal->close(DutySwapProposalStatus::CANCELLED, $now);
            }
            $request->cancel($now);
            $this->record($request, null, DutySwapEventType::REQUEST_CANCELLED, $actor, $now);
        });
    }

    // ---------------------------------------------------------------------------------------------
    // Housekeeping: expiry and obsolescence, recorded as events
    // ---------------------------------------------------------------------------------------------

    /**
     * Closes, with their events, the given requests that can no longer
     * conclude: past their start (EXPIRED), or whose offered duty is no
     * longer held through the frozen row / no longer on a published period
     * (OBSOLETE); and their pending proposals whose counterpart changed or
     * started (OBSOLETE — which closes an AGREED request too). Cheap
     * read-only check first; a request is locked and re-checked only when
     * something must change. Called before showing requests, and by
     * `app:duty-swaps:maintain` for all of them.
     *
     * @param list<DutySwapRequest> $requests
     *
     * @return int how many requests or proposals were closed
     */
    public function settle(array $requests): int
    {
        $closed = 0;
        foreach ($requests as $request) {
            if (!$request->isOpen() || !$this->needsSettling($request)) {
                continue;
            }
            $closed += $this->inTransaction(function () use ($request): int {
                $this->lockRequest($request);

                return $request->isOpen() ? $this->settleLocked($request) : 0;
            });
        }

        return $closed;
    }

    /** @return int how many open requests were closed or had a proposal closed */
    public function settleAllOpen(): int
    {
        return $this->settle($this->requestRepository->findOpen());
    }

    // ---------------------------------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------------------------------

    private function needsSettling(DutySwapRequest $request): bool
    {
        $now = $this->now();
        if ($request->getExpiresAt() <= $now || !$this->offeredStillValid($request)) {
            return true;
        }
        foreach ($request->getProposals() as $proposal) {
            if ($proposal->isPending() && !$this->counterpartStillValid($proposal, $now)) {
                return true;
            }
        }

        return false;
    }

    private function settleLocked(DutySwapRequest $request): int
    {
        $now = $this->now();
        if ($request->getExpiresAt() <= $now) {
            foreach ($this->pendingProposals($request) as $proposal) {
                $proposal->close(DutySwapProposalStatus::EXPIRED, $now);
            }
            $request->expire($now);
            $this->record($request, null, DutySwapEventType::REQUEST_EXPIRED, null, $now);

            return 1;
        }

        if (!$this->offeredStillValid($request)) {
            $this->obsoleteRequest($request, $now, 'offered_duty_changed');

            return 1;
        }

        $closed = 0;
        foreach ($this->pendingProposals($request) as $proposal) {
            if ($this->counterpartStillValid($proposal, $now)) {
                continue;
            }
            $proposal->close(DutySwapProposalStatus::OBSOLETE, $now);
            $this->record($request, $proposal, DutySwapEventType::PROPOSAL_OBSOLETE, null, $now, ['reason' => 'counterpart_duty_changed']);
            ++$closed;
            if (DutySwapKind::AGREED === $request->getKind()) {
                $request->markObsolete($now);
                $this->record($request, null, DutySwapEventType::REQUEST_OBSOLETE, null, $now, ['reason' => 'counterpart_duty_changed']);
            }
        }

        return $closed;
    }

    private function offeredStillValid(DutySwapRequest $request): bool
    {
        return PlanningPeriodStatus::PUBLISHED === $request->getOfferedDuty()->getPlanningPeriod()->getStatus()
            && $request->getLine()->isActive()
            && $this->isCurrentRow((int) $request->getOfferedAssignment()->getId());
    }

    private function counterpartStillValid(DutySwapProposal $proposal, \DateTimeImmutable $now): bool
    {
        foreach ($this->candidateService->blockDuties($proposal->getCounterpartDuty()) as $duty) {
            if ($duty->getStartsAt() <= $now) {
                return false;
            }
        }

        return $this->isCurrentRow((int) $proposal->getCounterpartAssignment()->getId());
    }

    /** Read from the database, never from a possibly stale in-memory DutyAssignment. */
    private function isCurrentRow(int $assignmentId): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne('SELECT current FROM duty_assignments WHERE id = :id', ['id' => $assignmentId]);
    }

    private function obsoleteRequest(DutySwapRequest $request, \DateTimeImmutable $now, string $reason, array $data = []): void
    {
        foreach ($this->pendingProposals($request) as $proposal) {
            $proposal->close(DutySwapProposalStatus::OBSOLETE, $now);
        }
        $request->markObsolete($now);
        $this->record($request, null, DutySwapEventType::REQUEST_OBSOLETE, null, $now, ['reason' => $reason] + $data);
    }

    /**
     * Inside accept()'s transaction: every other open request offering one
     * of the rows the swap just superseded, and every pending proposal
     * offering one, can no longer conclude — closed now (OBSOLETE), with
     * their events, rather than left to fail later.
     */
    private function obsoleteOthers(DutySwapRequest $completed, SwapApplication $swap, \DateTimeImmutable $now): void
    {
        $data = ['swapRequestStableId' => (string) $completed->getStableId()];
        foreach ($this->requestRepository->findOpenOfferingAny($swap->superseded) as $other) {
            if ($other === $completed) {
                continue;
            }
            $this->lockRequest($other);
            if ($other->isOpen()) {
                $this->obsoleteRequest($other, $now, 'swapped_elsewhere', $data);
            }
        }

        foreach ($this->proposalRepository->findPendingWithCounterpartAny($swap->superseded) as $proposal) {
            $other = $proposal->getRequest();
            if ($other === $completed) {
                continue;
            }
            $this->lockRequest($other);
            $this->entityManager->refresh($proposal);
            if (!$other->isOpen() || !$proposal->isPending()) {
                continue;
            }
            $proposal->close(DutySwapProposalStatus::OBSOLETE, $now);
            $this->record($other, $proposal, DutySwapEventType::PROPOSAL_OBSOLETE, null, $now, ['reason' => 'swapped_elsewhere'] + $data);
            if (DutySwapKind::AGREED === $other->getKind()) {
                $other->markObsolete($now);
                $this->record($other, null, DutySwapEventType::REQUEST_OBSOLETE, null, $now, ['reason' => 'swapped_elsewhere'] + $data);
            }
        }
    }

    /**
     * The early check when a proposal is made: an impossible swap is
     * refused right away with its reason, rather than sent to a colleague
     * who could only fail to accept it. Never the final word — accept()
     * revalidates everything.
     *
     * @throws DutySwapException
     */
    private function precheck(DutySwapProposal $proposal, \DateTimeImmutable $now): void
    {
        try {
            $this->reassignmentService->checkSwap($proposal, $now);
        } catch (DutySwapNotApplicableException $exception) {
            throw $this->toUserError($exception, $proposal);
        }
    }

    /**
     * After the rollback of a refused acceptance: records the failure in its
     * own transaction (the attempt is part of the history even though
     * nothing changed), then settles the request — a duty that changed
     * hands or started closes it for good.
     */
    private function recordValidationFailure(int $proposalId, int $actorId, DutySwapNotApplicableException $exception): DutySwapException
    {
        // The rolled-back transaction left managed entities in a state the database never had.
        $this->entityManager->clear();
        $proposal = $this->proposalRepository->find($proposalId) ?? throw new \LogicException('The proposal vanished.');
        $actor = $this->userRepository->find($actorId) ?? throw new \LogicException('The actor vanished.');
        $request = $proposal->getRequest();

        $this->inTransaction(function () use ($request, $proposal, $actor, $exception): void {
            $this->record($request, $proposal, DutySwapEventType::SWAP_VALIDATION_FAILED, $actor, $this->now(), ['reason' => $exception->reason, 'party' => $exception->party]);
        });
        $this->settle([$request]);

        return $this->toUserError($exception, $proposal);
    }

    private function toUserError(DutySwapNotApplicableException $exception, DutySwapProposal $proposal): DutySwapException
    {
        $request = $proposal->getRequest();
        $line = $request->getLine();
        $message = match ($exception->reason) {
            DutySwapNotApplicableException::PERIOD_NOT_PUBLISHED => 'Ce planning n\'est plus publié : ses gardes ne peuvent plus être échangées.',
            DutySwapNotApplicableException::DUTY_STARTED => 'Une des deux gardes a déjà commencé : l\'échange n\'est plus possible.',
            DutySwapNotApplicableException::DUTY_CHANGED => 'Une des deux gardes a changé de titulaire depuis la demande : cet échange n\'est plus valable.',
            DutySwapNotApplicableException::DUTY_LOCKED => 'Une des deux gardes est verrouillée par le gestionnaire du planning.',
            DutySwapNotApplicableException::NOT_SWAPPABLE => 'Ces deux gardes ne peuvent pas être échangées entre elles.',
            DutySwapNotApplicableException::COVERAGE_NOT_REQUIRED => 'Ce renfort n\'est pas requis actuellement : il ne peut pas être échangé.',
            DutySwapNotApplicableException::CHANGES_REINFORCEMENTS => 'Cet échange modifierait les renforts nécessaires sur une autre ligne de ce planning : adressez-vous au gestionnaire du planning.',
            default => null,
        };

        if (null === $message) {
            // One of the two people cannot take the other's unit on the calendar after the swap.
            [$who, $takes] = 'requester' === $exception->party
                ? [$request->getRequester(), $this->describer->describe($proposal->getCounterpartDuty(), $line)]
                : [$proposal->getCounterpart(), $this->describer->describe($request->getOfferedDuty(), $line)];
            $message = \sprintf('%s ne peut pas assurer %s : %s.', self::name($who), DutySwapUnitDescriber::phrase($takes), $this->reasonLabel($exception->reason));
        }

        return new DutySwapException('swap_not_applicable', 409, $message, $exception->reason, $exception->party);
    }

    private function reasonLabel(string $reason): string
    {
        $exclusion = ExclusionReason::tryFrom($reason);
        if (null !== $exclusion) {
            return $this->reasonLabeler->label($exclusion);
        }

        return match ($reason) {
            ReassignmentCandidateService::NOT_A_LINE_MEMBER => 'ne fait pas partie de cette ligne',
            ReassignmentCandidateService::NOT_IN_GENERATION_SNAPSHOT => 'a rejoint la ligne après la génération du planning',
            default => 'ne remplit pas les conditions',
        };
    }

    /**
     * @param list<string> $userStableIds
     *
     * @return list<User> each a current member of the unit's line, never the requester
     *
     * @throws DutySwapException
     */
    private function resolveRecipients(HeldDutyUnit $unit, array $userStableIds, User $actor): array
    {
        $recipients = [];
        foreach (array_unique($userStableIds) as $stableId) {
            $user = $this->userRepository->findOneByStableId((string) $stableId);
            if (null === $user || $user === $actor || !$user->isActive() || null === $this->access->currentLineMember($unit->line, $user)) {
                throw DutySwapException::invalid('invalid_recipients', 'Chaque destinataire doit être un membre actif de votre ligne de garde.');
            }
            $recipients[] = $user;
        }

        return $recipients;
    }

    /**
     * Must be called BEFORE changing any of the request's proposals in this
     * transaction: it refreshes them from the database (discarding unflushed
     * changes).
     *
     * @return list<DutySwapProposal> its PENDING proposals as the database has them now (under the request lock)
     */
    private function pendingProposals(DutySwapRequest $request): array
    {
        $pending = [];
        foreach ($this->proposalRepository->findBy(['request' => $request, 'status' => DutySwapProposalStatus::PENDING], ['id' => 'ASC']) as $proposal) {
            $this->entityManager->refresh($proposal);
            if ($proposal->isPending()) {
                $pending[] = $proposal;
            }
        }

        return $pending;
    }

    private function lockRequest(DutySwapRequest $request): void
    {
        $this->entityManager->refresh($request, LockMode::PESSIMISTIC_WRITE);
    }

    /**
     * @throws DutySwapException
     */
    private function assertOpen(DutySwapRequest $request): void
    {
        if ($request->isOpen()) {
            return;
        }

        throw match ($request->getStatus()) {
            DutySwapRequestStatus::COMPLETED => DutySwapException::conflict('request_closed', 'Cet échange a déjà été conclu.'),
            DutySwapRequestStatus::EXPIRED => DutySwapException::conflict('request_closed', 'Cette demande a expiré : la garde a commencé.'),
            DutySwapRequestStatus::OBSOLETE => DutySwapException::conflict('request_closed', 'Cette demande n\'est plus valable : la garde a changé de titulaire.'),
            default => DutySwapException::conflict('request_closed', 'Cette demande n\'est plus ouverte.'),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function record(DutySwapRequest $request, ?DutySwapProposal $proposal, DutySwapEventType $type, ?User $actor, \DateTimeImmutable $now, array $data = []): void
    {
        $this->entityManager->persist(new DutySwapEvent($request, $proposal, $type, $actor, $now, $data));
    }

    /**
     * An outbox row, its content frozen now (docs/duty-swaps.md §8).
     */
    private function notify(DutySwapRequest $request, ?DutySwapProposal $proposal, User $recipient, DutySwapNotificationKind $kind, \DateTimeImmutable $now): DutySwapNotification
    {
        $line = $request->getLine();
        $offered = DutySwapUnitDescriber::phrase($this->describer->describe($request->getOfferedDuty(), $line));
        $counterpartUnit = null !== $proposal ? DutySwapUnitDescriber::phrase($this->describer->describe($proposal->getCounterpartDuty(), $line)) : null;

        $payload = [
            'requestStableId' => (string) $request->getStableId(),
            'planningName' => $request->getPlanning()->getName(),
            'lineName' => $line->getName(),
            'requesterName' => self::name($request->getRequester()),
            'counterpartName' => null !== $proposal ? self::name($proposal->getCounterpart()) : null,
            'toWholeLine' => DutySwapAudience::ALL === $request->getAudience(),
            // What each side currently holds — and therefore what the OTHER one would take.
            'requesterGives' => $offered,
            'recipientGives' => $counterpartUnit,
            'counterpartGives' => $counterpartUnit,
            'requesterTakes' => $counterpartUnit,
            'counterpartTakes' => $offered,
            'deciderName' => null !== $proposal ? self::name($proposal->getDecider()) : null,
            'yourUnit' => null !== $proposal ? ($proposal->getAuthor() === $request->getRequester() ? $offered : $counterpartUnit) : null,
        ];

        $notification = new DutySwapNotification($request, $proposal, $recipient, $kind, $payload, $now);
        $this->entityManager->persist($notification);

        return $notification;
    }

    /**
     * Runs $work in a transaction, flushes and commits. On any failure: rollback,
     * then clear() — the rolled-back writes may linger in managed entities.
     *
     * @template T
     *
     * @param callable(): T                 $work
     * @param (callable(): \Throwable)|null $onUniqueViolation
     *
     * @return T
     */
    private function inTransaction(callable $work, ?callable $onUniqueViolation = null): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $this->entityManager->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            if ($exception instanceof DutySwapException) {
                // Nothing was flushed before a workflow refusal except inside accept() — clear either way.
                $this->entityManager->clear();
                throw $exception;
            }
            $this->entityManager->clear();
            if ($exception instanceof UniqueConstraintViolationException && null !== $onUniqueViolation) {
                throw $onUniqueViolation();
            }
            throw $exception;
        }
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function name(User $user): string
    {
        return $user->getFirstName().' '.$user->getLastName();
    }
}
