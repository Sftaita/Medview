<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamInvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An offer to join a PlanningTeam, addressed to an email that has no User
 * yet (docs/decisions.md D111). Deliberately NOT a half-filled User row:
 * a User is always a real, authenticable account, and an invitation is
 * the only place where "someone we know only by email" may live. The
 * membership itself (PlanningTeamMember) is created only when the
 * invitation is consumed, in the same transaction as the User.
 *
 * The raw token exists only in the invitation email's link; only its
 * SHA-256 hash is stored ($tokenHash). A 256-bit random token needs no
 * slow/salted hash — brute force is infeasible and a plain hash lets the
 * row be looked up by index.
 *
 * $email is stored normalized (trimmed, lower case); the database
 * enforces that (CHECK) and, through a partial unique index, that a
 * (team, email) pair has at most one PENDING invitation at a time.
 */
#[ORM\Entity(repositoryClass: TeamInvitationRepository::class)]
#[ORM\Table(name: 'team_invitations')]
#[ORM\UniqueConstraint(name: 'uniq_team_invitations_stable_id', columns: ['stable_id'])]
#[ORM\UniqueConstraint(name: 'uniq_team_invitations_token_hash', columns: ['token_hash'])]
#[ORM\Index(columns: ['email'], name: 'idx_team_invitations_email')]
#[ORM\Index(columns: ['planning_team_id'], name: 'idx_team_invitations_planning_team_id')]
class TeamInvitation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stableId;

    #[ORM\ManyToOne(targetEntity: PlanningTeam::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningTeam $planningTeam;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 100)]
    private string $proposedFirstName;

    #[ORM\Column(length: 100)]
    private string $proposedLastName;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $invitedBy;

    /** Role the invitee gets on acceptance. Always MEMBER in v1: no endpoint lets a client choose it. */
    #[ORM\Column(length: 20, enumType: TeamMemberRole::class)]
    private TeamMemberRole $role;

    /** SHA-256 (hex) of the raw token. */
    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(length: 20, enumType: TeamInvitationStatus::class)]
    private TeamInvitationStatus $status = TeamInvitationStatus::PENDING;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $acceptedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Changes whenever the status does — a real audit timestamp, not decoration. */
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PlanningTeam $planningTeam,
        string $email,
        string $proposedFirstName,
        string $proposedLastName,
        User $invitedBy,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
        TeamMemberRole $role = TeamMemberRole::MEMBER,
    ) {
        $this->stableId = Uuid::v7();
        $this->planningTeam = $planningTeam;
        $this->email = self::normalizeEmail($email);
        $this->proposedFirstName = trim($proposedFirstName);
        $this->proposedLastName = trim($proposedLastName);
        $this->invitedBy = $invitedBy;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStableId(): Uuid
    {
        return $this->stableId;
    }

    public function getPlanningTeam(): PlanningTeam
    {
        return $this->planningTeam;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getProposedFirstName(): string
    {
        return $this->proposedFirstName;
    }

    public function getProposedLastName(): string
    {
        return $this->proposedLastName;
    }

    public function getInvitedBy(): User
    {
        return $this->invitedBy;
    }

    public function getRole(): TeamMemberRole
    {
        return $this->role;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getStatus(): TeamInvitationStatus
    {
        return $this->status;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getAcceptedBy(): ?User
    {
        return $this->acceptedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return TeamInvitationStatus::PENDING === $this->status && $this->expiresAt > $now;
    }

    /** The status a reader should see: a PENDING row past its expiry is EXPIRED even if not yet flipped in the database. */
    public function effectiveStatusAt(\DateTimeImmutable $now): TeamInvitationStatus
    {
        if (TeamInvitationStatus::PENDING === $this->status && $this->expiresAt <= $now) {
            return TeamInvitationStatus::EXPIRED;
        }

        return $this->status;
    }

    /** Persists the lazy PENDING → EXPIRED flip. No-op unless PENDING. */
    public function markExpired(): void
    {
        if (TeamInvitationStatus::PENDING === $this->status) {
            $this->status = TeamInvitationStatus::EXPIRED;
            $this->touch();
        }
    }

    /**
     * @throws \LogicException unless the invitation is PENDING
     */
    public function revoke(): void
    {
        if (TeamInvitationStatus::PENDING !== $this->status) {
            throw new \LogicException('Only a PENDING invitation can be revoked.');
        }

        $this->status = TeamInvitationStatus::REVOKED;
        $this->touch();
    }

    /**
     * @throws \LogicException unless the invitation is PENDING
     */
    public function accept(User $user, \DateTimeImmutable $now): void
    {
        if (TeamInvitationStatus::PENDING !== $this->status) {
            throw new \LogicException('Only a PENDING invitation can be accepted.');
        }

        $this->status = TeamInvitationStatus::ACCEPTED;
        $this->acceptedAt = $now;
        $this->acceptedBy = $user;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
