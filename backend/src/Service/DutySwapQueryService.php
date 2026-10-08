<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DutySwapAudience;
use App\Entity\DutySwapKind;
use App\Entity\DutySwapProposal;
use App\Entity\DutySwapRequest;
use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\User;
use App\Exception\DutySwapException;
use App\Repository\DutySwapEventRepository;
use App\Repository\DutySwapProposalRepository;
use App\Repository\DutySwapRequestRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * What the "Échanges" screens read (docs/duty-swaps.md §9): the three lists
 * of a member, one request in detail with its full history, the creation
 * options, and a planning's swap history for its managers — each request
 * shown only to whom DutySwapAccess allows, each proposal likewise, and
 * each action offered only when the service would accept it.
 *
 * Requests that can no longer conclude (started, duty changed hands) are
 * settled (DutySwapService::settle) before being shown, so a list never
 * offers "Accepter" on something that can only fail.
 */
final class DutySwapQueryService
{
    public function __construct(
        private readonly DutySwapRequestRepository $requestRepository,
        private readonly DutySwapProposalRepository $proposalRepository,
        private readonly DutySwapEventRepository $eventRepository,
        private readonly DutySwapService $swapService,
        private readonly DutySwapAccess $access,
        private readonly DutySwapUnitDescriber $describer,
        private readonly CurrentCalendarReader $calendarReader,
        private readonly MyDutiesService $myDuties,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{mine: list<array<string, mixed>>, received: list<array<string, mixed>>, team: list<array<string, mixed>>}
     */
    public function overviewFor(User $viewer): array
    {
        $mine = $this->requestRepository->findByRequester($viewer);
        $received = $this->requestRepository->findAddressedTo($viewer);
        $team = $this->requestRepository->findTeamRequestsFor($viewer);
        // A request answered by the viewer stays in their history even once closed.
        foreach ($this->proposalRepository->findRequestsProposedOnBy($viewer) as $request) {
            if (DutySwapAudience::ALL === $request->getAudience() && !\in_array($request, $team, true)) {
                $team[] = $request;
            }
        }
        $this->swapService->settle([...$mine, ...$received, ...$team]);

        $team = array_values(array_filter($team, fn (DutySwapRequest $r): bool => $this->access->canView($r, $viewer) && ($r->isOpen() || $this->hasProposalBy($r, $viewer))));
        usort($team, static fn (DutySwapRequest $a, DutySwapRequest $b): int => $b->getCreatedAt() <=> $a->getCreatedAt());

        return [
            'mine' => array_map(fn (DutySwapRequest $r): array => $this->requestView($r, $viewer), $mine),
            'received' => array_map(fn (DutySwapRequest $r): array => $this->requestView($r, $viewer), $received),
            'team' => array_map(fn (DutySwapRequest $r): array => $this->requestView($r, $viewer), $team),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws DutySwapException
     */
    public function detailFor(DutySwapRequest $request, User $viewer): array
    {
        if (!$this->access->canView($request, $viewer)) {
            throw DutySwapException::notFound();
        }
        $this->swapService->settle([$request]);

        $view = $this->requestView($request, $viewer);
        $view['history'] = $this->history($request, $viewer);
        $view['proposableUnits'] = $view['actions']['propose'] ? $this->proposableUnits($request, $viewer) : [];

        return $view;
    }

    /**
     * Everything the "Échanger ma garde" dialog needs: the unit (checked
     * swappable for the caller), the colleagues of its line, and for each
     * the future units they hold on it — for "J'ai déjà convenu d'un échange".
     *
     * @return array<string, mixed>
     *
     * @throws DutySwapException
     */
    public function creationOptions(User $viewer, string $dutyStableId): array
    {
        $unit = $this->swapService->heldUnit($this->swapService->dutyByStableId($dutyStableId), $viewer);
        $line = $unit->line;
        $now = $this->clock->now();

        $unitsByUser = [];
        foreach ($this->calendarReader->read($line->getPlanning(), static fn (PlanningLine $l): bool => $l === $line) as $cell) {
            $holder = $cell->member?->getUser();
            if (null === $holder || $holder === $viewer || !$cell->isShown()) {
                continue;
            }
            $described = $this->describer->describe($cell->duty, $line);
            if (new \DateTimeImmutable($described['startsAt']) <= $now) {
                continue;
            }
            $unitsByUser[(int) $holder->getId()][$described['dutyStableId']] = $described;
        }

        $colleagues = [];
        foreach ($this->access->currentLineMembers($line) as $member) {
            $user = $member->getUser();
            if ($user === $viewer || !$user->isActive()) {
                continue;
            }
            $units = array_values($unitsByUser[(int) $user->getId()] ?? []);
            usort($units, static fn (array $a, array $b): int => $a['startsAt'] <=> $b['startsAt']);
            $colleagues[] = self::person($user) + ['units' => $units];
        }
        usort($colleagues, static fn (array $a, array $b): int => [$a['lastName'], $a['firstName']] <=> [$b['lastName'], $b['firstName']]);

        $open = null;
        foreach ($this->requestRepository->findOpenByRequester($viewer) as $request) {
            if ($request->getOfferedAssignment() === $unit->assignment) {
                $open = (string) $request->getStableId();
            }
        }

        return [
            'offered' => $this->describer->describe($unit->representative, $line),
            'colleagues' => $colleagues,
            'openRequestStableId' => $open,
        ];
    }

    /**
     * The read-only swap history of a Planning, for its managers — who see
     * it, never approve it (docs/duty-swaps.md §5).
     *
     * @return list<array<string, mixed>>
     */
    public function planningHistory(Planning $planning, User $viewer): array
    {
        $requests = $this->requestRepository->findByPlanning($planning);
        $this->swapService->settle($requests);

        return array_map(function (DutySwapRequest $request) use ($viewer): array {
            $view = $this->requestView($request, $viewer);
            $view['history'] = $this->history($request, $viewer);

            return $view;
        }, $requests);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestView(DutySwapRequest $request, User $viewer): array
    {
        $line = $request->getLine();
        $isRequester = $request->getRequester() === $viewer;
        $seesRecipients = $isRequester || $request->isRecipient($viewer) || $this->access->isManager($request);
        $proposals = array_values(array_filter($request->getProposals(), fn (DutySwapProposal $p): bool => $this->access->canViewProposal($p, $viewer)));

        return [
            'stableId' => (string) $request->getStableId(),
            'kind' => $request->getKind()->value,
            'audience' => $request->getAudience()->value,
            'status' => $request->getStatus()->value,
            'createdAt' => $request->getCreatedAt()->format(\DATE_ATOM),
            'closedAt' => $request->getClosedAt()?->format(\DATE_ATOM),
            'expiresAt' => $request->getExpiresAt()->format(\DATE_ATOM),
            'planning' => ['stableId' => (string) $request->getPlanning()->getStableId(), 'name' => $request->getPlanning()->getName()],
            'line' => ['stableId' => (string) $line->getStableId(), 'name' => $line->getName()],
            'requester' => self::person($request->getRequester()),
            'offered' => $this->describer->describe($request->getOfferedDuty(), $line),
            'recipients' => $seesRecipients ? array_map(self::person(...), $request->getRecipientUsers()) : [],
            'viewerRole' => $isRequester ? 'REQUESTER' : ($request->isRecipient($viewer) ? 'RECIPIENT' : ($this->access->isAudience($request, $viewer) || $this->hasProposalBy($request, $viewer) ? 'TEAM' : 'MANAGER')),
            'acceptedProposalStableId' => null !== $request->getAcceptedProposal() ? (string) $request->getAcceptedProposal()->getStableId() : null,
            'proposals' => array_map(fn (DutySwapProposal $p): array => $this->proposalView($p, $viewer), $proposals),
            'actions' => [
                'cancel' => $isRequester && $request->isOpen(),
                'propose' => $request->isOpen() && DutySwapKind::SEARCH === $request->getKind() && $this->access->isAudience($request, $viewer) && !$this->hasPendingProposalBy($request, $viewer),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalView(DutySwapProposal $proposal, User $viewer): array
    {
        $open = $proposal->isPending() && $proposal->getRequest()->isOpen();

        return [
            'stableId' => (string) $proposal->getStableId(),
            'status' => $proposal->getStatus()->value,
            'author' => self::person($proposal->getAuthor()),
            'counterpart' => self::person($proposal->getCounterpart()),
            'decider' => self::person($proposal->getDecider()),
            'counterpartUnit' => $this->describer->describe($proposal->getCounterpartDuty(), $proposal->getRequest()->getLine()),
            'createdAt' => $proposal->getCreatedAt()->format(\DATE_ATOM),
            'decidedAt' => $proposal->getDecidedAt()?->format(\DATE_ATOM),
            'actions' => [
                'accept' => $open && $proposal->getDecider() === $viewer,
                'refuse' => $open && $proposal->getDecider() === $viewer,
                // An AGREED request's one proposal is withdrawn by cancelling the request.
                'withdraw' => $open && $proposal->getAuthor() === $viewer && DutySwapKind::SEARCH === $proposal->getRequest()->getKind(),
            ],
        ];
    }

    /**
     * The chronology, read from the append-only journal (never rebuilt from
     * statuses) — an event about a proposal the viewer may not see is left out.
     *
     * @return list<array<string, mixed>>
     */
    private function history(DutySwapRequest $request, User $viewer): array
    {
        $events = [];
        foreach ($this->eventRepository->findByRequest($request) as $event) {
            $proposal = $event->getProposal();
            if (null !== $proposal && !$this->access->canViewProposal($proposal, $viewer)) {
                continue;
            }
            $data = $event->getData();
            // Who else was notified is the requester's (and managers') business only.
            if (isset($data['recipients']) && $request->getRequester() !== $viewer && !$this->access->isManager($request)) {
                unset($data['recipients']);
            }
            $events[] = [
                'stableId' => (string) $event->getStableId(),
                'type' => $event->getType()->value,
                'occurredAt' => $event->getOccurredAt()->format(\DATE_ATOM),
                'actor' => null !== $event->getActor() ? self::person($event->getActor()) : null,
                'proposalStableId' => null !== $proposal ? (string) $proposal->getStableId() : null,
                'data' => $data,
            ];
        }

        return $events;
    }

    /**
     * The viewer's own future units on the request's line, other than the offered one.
     *
     * @return list<array<string, mixed>>
     */
    private function proposableUnits(DutySwapRequest $request, User $viewer): array
    {
        $line = $request->getLine();
        $units = [];
        foreach ($this->myDuties->dutiesWithSwapStateOf($viewer) as $unit) {
            if ($unit['lineStableId'] !== (string) $line->getStableId() || !$unit['swappable'] || $unit['dutyStableId'] === (string) $request->getOfferedDuty()->getStableId()) {
                continue;
            }
            $units[] = [
                'dutyStableId' => $unit['dutyStableId'],
                'planningStableId' => $unit['planningStableId'],
                'planningName' => $unit['planningName'],
                'lineStableId' => $unit['lineStableId'],
                'lineName' => $unit['lineName'],
                'dutyTypeName' => $unit['dutyTypeName'],
                'blockName' => $unit['blockName'],
                'dates' => $unit['dates'],
                'startsAt' => $unit['startsAt'],
                'endsAt' => $unit['endsAt'],
            ];
        }

        return $units;
    }

    private function hasProposalBy(DutySwapRequest $request, User $user): bool
    {
        foreach ($request->getProposals() as $proposal) {
            if ($proposal->getAuthor() === $user) {
                return true;
            }
        }

        return false;
    }

    private function hasPendingProposalBy(DutySwapRequest $request, User $user): bool
    {
        foreach ($request->getProposals() as $proposal) {
            if ($proposal->getAuthor() === $user && $proposal->isPending()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{userStableId: string, firstName: string, lastName: string}
     */
    private static function person(User $user): array
    {
        return ['userStableId' => (string) $user->getStableId(), 'firstName' => $user->getFirstName(), 'lastName' => $user->getLastName()];
    }
}
