<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CalendarFeedRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The secret subscription address of a User's personal duty calendar
 * (docs/decisions.md D170): Google Calendar, Apple Calendar and Outlook poll
 * GET /api/calendar-feeds/{token}.ics with no JWT, so the token IS the
 * credential — read-only, and only for the duties of its own User.
 *
 * Unlike every other token of this codebase (password reset, invitation,
 * refresh token), the raw value is stored, never only its hash: the address
 * has to be shown again whenever its owner adds it to another calendar
 * app, and it grants nothing the database does not already hold in clear
 * (the duties themselves) — hashing would protect nothing a database leak
 * does not already expose.
 *
 * At most one active feed per User (partial unique index): regenerating
 * revokes the previous one, which stops working at once. Revoked rows are
 * kept, like every other token history.
 */
#[ORM\Entity(repositoryClass: CalendarFeedRepository::class)]
#[ORM\Table(name: 'calendar_feeds')]
#[ORM\UniqueConstraint(name: 'uniq_calendar_feeds_token', columns: ['token'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_calendar_feeds_user_id')]
class CalendarFeed
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** 64 lowercase hex characters (256 random bits). */
    #[ORM\Column(length: 64)]
    private string $token;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** Last time a calendar app fetched it — shown to the owner, refreshed at most hourly. */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    public function __construct(User $user, string $token, \DateTimeImmutable $createdAt)
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $token)) {
            throw new \InvalidArgumentException('A calendar feed token is 64 lowercase hex characters.');
        }

        $this->user = $user;
        $this->token = $token;
        $this->createdAt = $createdAt;
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isActive(): bool
    {
        return null === $this->revokedAt;
    }

    public function revoke(\DateTimeImmutable $at): void
    {
        $this->revokedAt ??= $at;
    }

    public function getLastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    /**
     * @return bool whether it changed — calendar apps poll often, one write an hour is enough
     */
    public function recordFetch(\DateTimeImmutable $at): bool
    {
        if (null !== $this->lastFetchedAt && $at < $this->lastFetchedAt->modify('+1 hour')) {
            return false;
        }

        $this->lastFetchedAt = $at;

        return true;
    }
}
