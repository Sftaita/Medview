<?php

declare(strict_types=1);

namespace App\Service\SurgicalHub;

use App\Entity\SurgicalHubLink;
use App\Entity\SurgicalHubLinkCode;
use App\Entity\SurgicalHubLinkEvent;
use App\Entity\SurgicalHubLinkEventKind;
use App\Entity\SurgicalHubLinkStatus;
use App\Entity\User;
use App\Exception\SurgicalHubLinkRefusedException;
use App\Repository\SurgicalHubImportedLeaveRepository;
use App\Repository\SurgicalHubLinkCodeRepository;
use App\Repository\SurgicalHubLinkRepository;
use App\Service\UserAvailabilityService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Associating a MedVue account with a SurgicalHub account
 * (docs/surgicalhub-integration.md §4, docs/decisions.md D182).
 *
 * The person proves they own the MedVue account by generating a code here
 * and typing it into SurgicalHub (themselves, or by handing it to a
 * SurgicalHub administrator). SurgicalHub then exchanges it through the
 * machine-to-machine endpoint (redeem()). Nothing in this class ever writes
 * to SurgicalHub, and redeem() returns nothing about the MedVue account.
 *
 * Invariants: one ACTIVE association per MedVue User and per SurgicalHub
 * account (checked here, guaranteed by partial unique indexes); a code is
 * single-use, short-lived, superseded by the next one, and only its hash is
 * stored; every operation lands in surgical_hub_link_events, never the code.
 */
final class SurgicalHubLinkService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SurgicalHubLinkRepository $linkRepository,
        private readonly SurgicalHubLinkCodeRepository $codeRepository,
        private readonly SurgicalHubLinkMailer $mailer,
        private readonly SurgicalHubImportedLeaveRepository $importRepository,
        private readonly UserAvailabilityService $availabilityService,
        private readonly SurgicalHubApiClient $client,
        private readonly SurgicalHubCalendar $calendar,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'int:SURGICALHUB_LINK_CODE_TTL')]
        private readonly int $codeTtlSeconds,
    ) {
    }

    /** A new code for $user; any code they generated before stops working. */
    public function issueCode(User $user): IssuedLinkCode
    {
        $now = $this->now();
        $code = SurgicalHubLinkCodeFormat::generate();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->codeTtlSeconds));

        $this->entityManager->wrapInTransaction(function () use ($user, $code, $now, $expiresAt): void {
            foreach ($this->codeRepository->findPendingForUser($user) as $previous) {
                $previous->revoke($now);
            }
            $this->entityManager->persist(new SurgicalHubLinkCode($user, SurgicalHubLinkCodeFormat::hash($code), $now, $expiresAt));
            $this->entityManager->persist(new SurgicalHubLinkEvent($user, SurgicalHubLinkEventKind::CODE_ISSUED, $now));
            $this->entityManager->flush();
        });

        return new IssuedLinkCode(SurgicalHubLinkCodeFormat::display($code), $expiresAt);
    }

    /**
     * Exchanges a code typed into SurgicalHub for an association with
     * $surgicalHubUserId. The same pair redeeming a new code confirms the
     * existing association (SurgicalHub may have lost the first answer)
     * instead of creating a second one.
     *
     * @throws SurgicalHubLinkRefusedException
     */
    public function redeem(
        string $rawCode,
        string $surgicalHubUserId,
        string $surgicalHubDisplayName,
        string $actorLabel,
        bool $byAdministrator,
    ): SurgicalHubLink {
        $normalized = SurgicalHubLinkCodeFormat::normalize($rawCode);
        if (null === $normalized) {
            throw new SurgicalHubLinkRefusedException(SurgicalHubLinkRefusal::INVALID_CODE);
        }

        $now = $this->now();

        try {
            /** @var array{0: ?SurgicalHubLink, 1: ?SurgicalHubLinkRefusal, 2: bool} $outcome */
            $outcome = $this->entityManager->wrapInTransaction(function () use ($normalized, $surgicalHubUserId, $surgicalHubDisplayName, $actorLabel, $byAdministrator, $now): array {
                $code = $this->codeRepository->findOneByCodeHashForUpdate(SurgicalHubLinkCodeFormat::hash($normalized));
                if (null === $code || !$code->isUsableAt($now) || !$code->getUser()->isActive()) {
                    return [null, SurgicalHubLinkRefusal::INVALID_CODE, false];
                }

                $user = $code->getUser();
                $takenOnSurgicalHubSide = $this->linkRepository->findCurrentForSurgicalHubUser($surgicalHubUserId);
                if (null !== $takenOnSurgicalHubSide && $takenOnSurgicalHubSide->getUser() !== $user) {
                    // The code stays usable: the administrator may simply have picked the wrong account.
                    $this->record($user, SurgicalHubLinkEventKind::REFUSED_SURGICAL_HUB_ACCOUNT_TAKEN, $now, null, $surgicalHubUserId, $actorLabel);

                    return [null, SurgicalHubLinkRefusal::SURGICAL_HUB_ACCOUNT_TAKEN, false];
                }

                $current = $this->linkRepository->findCurrentForUser($user);
                if (null !== $current && $current->getSurgicalHubUserId() !== $surgicalHubUserId) {
                    $this->record($user, SurgicalHubLinkEventKind::REFUSED_MEDVUE_ACCOUNT_TAKEN, $now, null, $surgicalHubUserId, $actorLabel);

                    return [null, SurgicalHubLinkRefusal::MEDVUE_ACCOUNT_TAKEN, false];
                }

                $code->consume($now);

                if (null !== $current) {
                    // Same pair: confirmed — and resumed if a doubtful 404 had suspended it (§9): the owner
                    // just proved the association again, SurgicalHub stores this linkId anew.
                    $resumed = $current->isSuspended();
                    $current->resume();
                    $this->record($user, $resumed ? SurgicalHubLinkEventKind::LINK_RESUMED : SurgicalHubLinkEventKind::LINK_CONFIRMED, $now, $current, $surgicalHubUserId, $actorLabel);

                    return [$current, null, false];
                }

                $link = new SurgicalHubLink($user, $surgicalHubUserId, $surgicalHubDisplayName, $byAdministrator, $now);
                $this->entityManager->persist($link);
                $this->record($user, SurgicalHubLinkEventKind::LINKED, $now, $link, $surgicalHubUserId, $actorLabel);

                return [$link, null, true];
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent exchange created the other side of the pair first (partial unique indexes).
            throw new SurgicalHubLinkRefusedException(SurgicalHubLinkRefusal::SURGICAL_HUB_ACCOUNT_TAKEN);
        }

        [$link, $refusal, $created] = $outcome;
        if (null !== $refusal || null === $link) {
            throw new SurgicalHubLinkRefusedException($refusal ?? SurgicalHubLinkRefusal::INVALID_CODE);
        }

        if ($created) {
            // After commit, best effort: the owner learns who associated their account (D182).
            $this->mailer->sendLinkedNotice($link, $actorLabel);
        }

        return $link;
    }

    /**
     * The owner ends their association from MedVue — ACTIVE or SUSPENDED:
     * revoked here with its future imports removed (D9), then SurgicalHub is
     * told (D4) — after commit and best effort: MedVue stops reading either way.
     * Returns the revoked link, or null if there was none.
     */
    public function revokeLocally(User $user): ?SurgicalHubLink
    {
        $link = $this->linkRepository->findCurrentForUser($user);
        if (null === $link) {
            return null;
        }

        if (null !== $this->revoke($link, SurgicalHubLinkStatus::REVOKED_LOCAL, SurgicalHubLinkEventKind::UNLINKED_LOCAL)) {
            $this->client->deleteLink((string) $link->getStableId());
        }

        return $link;
    }

    /**
     * SurgicalHub revoked this association (`410 link_revoked`, exact v1 body).
     *
     * @return int the imported periods removed (D9)
     */
    public function revokeRemotely(SurgicalHubLink $link): int
    {
        return $this->revoke($link, SurgicalHubLinkStatus::REVOKED_REMOTE, SurgicalHubLinkEventKind::UNLINKED_REMOTE) ?? 0;
    }

    /**
     * SurgicalHub answered `404 link_not_found` (exact v1 body): it says it never
     * knew this association — after a restored SurgicalHub backup, every
     * association would answer that at once. A doubt, not a revocation
     * (docs/surgicalhub-integration.md §9): reading stops, nothing is deleted,
     * the owner is told on their pages. It ends only by the owner — a new code
     * for the same pair resumes it with its imports intact; « Dissocier »
     * revokes it (D9).
     *
     * @return bool whether this call suspended it (false: it was not ACTIVE)
     */
    public function suspend(SurgicalHubLink $link): bool
    {
        $now = $this->now();

        return $this->entityManager->wrapInTransaction(function () use ($link, $now): bool {
            $this->linkRepository->lock($link);
            if (!$link->isActive()) {
                return false;
            }

            $link->suspend($now);
            $this->record($link->getUser(), SurgicalHubLinkEventKind::SUSPENDED_BY_UNKNOWN_LINK, $now, $link, $link->getSurgicalHubUserId());

            return true;
        });
    }

    /**
     * D9, in the same transaction as the status change: the imports of this
     * pair of accounts that start today or later (business timezone) are
     * removed; past ones and the one in progress stay whole; MANUAL periods
     * are out of reach; snapshots keep their own copies.
     *
     * @return int|null the imported periods removed, or null when it was already revoked
     */
    private function revoke(SurgicalHubLink $link, SurgicalHubLinkStatus $status, SurgicalHubLinkEventKind $kind): ?int
    {
        $now = $this->now();

        return $this->entityManager->wrapInTransaction(function () use ($link, $status, $kind, $now): ?int {
            $this->linkRepository->lock($link);
            if (!$link->isCurrent()) {
                return null;
            }

            $link->revoke($status, $now);
            $startOfToday = $this->calendar->startOfToday($now);
            $removed = 0;
            foreach ($this->importRepository->findForPairOf($link) as $import) {
                if ($import->getPeriod()->getStartsAt() >= $startOfToday) {
                    $this->entityManager->remove($import);
                    $this->availabilityService->importDelete($import->getPeriod());
                    ++$removed;
                }
            }
            $this->record($link->getUser(), $kind, $now, $link, $link->getSurgicalHubUserId());

            return $removed;
        });
    }

    private function record(User $user, SurgicalHubLinkEventKind $kind, \DateTimeImmutable $now, ?SurgicalHubLink $link = null, ?string $surgicalHubUserId = null, ?string $actorLabel = null): void
    {
        $this->entityManager->persist(new SurgicalHubLinkEvent($user, $kind, $now, $link, $surgicalHubUserId, $actorLabel));
        $this->entityManager->flush();
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
