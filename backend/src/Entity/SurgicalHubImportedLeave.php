<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SurgicalHubImportedLeaveRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Which SurgicalHub absence a SURGICAL_HUB UserAvailabilityPeriod mirrors
 * (docs/surgicalhub-integration.md §6.1, §7.3). One row per imported
 * period, unique per (user, SurgicalHub account, absence id): the same
 * absence can never be imported twice, even across a revocation and a new
 * association of the same pair of accounts — the new association takes the
 * existing rows over ($link is the one that last reconciled it).
 *
 * $startDate/$endDate are the absence as SurgicalHub last described it
 * (calendar dates, end inclusive): comparing them is how an unchanged
 * absence is recognized without touching its period.
 *
 * Deleting the period (synchronisation, revocation) deletes this row
 * (ON DELETE CASCADE); nothing else ever deletes it.
 */
#[ORM\Entity(repositoryClass: SurgicalHubImportedLeaveRepository::class)]
#[ORM\Table(name: 'surgical_hub_imported_leaves')]
#[ORM\UniqueConstraint(name: 'uniq_surgical_hub_imported_leaves_absence', columns: ['user_id', 'surgical_hub_user_id', 'external_absence_id'])]
#[ORM\UniqueConstraint(name: 'uniq_surgical_hub_imported_leaves_period', columns: ['availability_period_id'])]
#[ORM\Index(columns: ['link_id'], name: 'idx_surgical_hub_imported_leaves_link_id')]
class SurgicalHubImportedLeave
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $surgicalHubUserId;

    #[ORM\Column(length: 64)]
    private string $externalAbsenceId;

    #[ORM\ManyToOne(targetEntity: SurgicalHubLink::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private SurgicalHubLink $link;

    #[ORM\OneToOne(targetEntity: UserAvailabilityPeriod::class)]
    #[ORM\JoinColumn(name: 'availability_period_id', nullable: false, onDelete: 'CASCADE')]
    private UserAvailabilityPeriod $period;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $endDate;

    #[ORM\Column]
    private \DateTimeImmutable $firstImportedAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastChangedAt;

    public function __construct(
        SurgicalHubLink $link,
        string $externalAbsenceId,
        UserAvailabilityPeriod $period,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        \DateTimeImmutable $now,
    ) {
        if (!$period->isImported() || $period->getUser() !== $link->getUser()) {
            throw new \InvalidArgumentException('Only an imported period of the link owner can mirror a SurgicalHub absence.');
        }

        $this->user = $link->getUser();
        $this->surgicalHubUserId = $link->getSurgicalHubUserId();
        $this->externalAbsenceId = $externalAbsenceId;
        $this->link = $link;
        $this->period = $period;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->firstImportedAt = $now;
        $this->lastChangedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSurgicalHubUserId(): string
    {
        return $this->surgicalHubUserId;
    }

    public function getExternalAbsenceId(): string
    {
        return $this->externalAbsenceId;
    }

    public function getLink(): SurgicalHubLink
    {
        return $this->link;
    }

    public function getPeriod(): UserAvailabilityPeriod
    {
        return $this->period;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getFirstImportedAt(): \DateTimeImmutable
    {
        return $this->firstImportedAt;
    }

    public function getLastChangedAt(): \DateTimeImmutable
    {
        return $this->lastChangedAt;
    }

    public function describes(\DateTimeImmutable $startDate, \DateTimeImmutable $endDate): bool
    {
        return $this->startDate->format('Y-m-d') === $startDate->format('Y-m-d')
            && $this->endDate->format('Y-m-d') === $endDate->format('Y-m-d');
    }

    /** $link reconciled it (possibly a newer association of the same pair). */
    public function adoptBy(SurgicalHubLink $link): void
    {
        if ($link->getUser() !== $this->user || $link->getSurgicalHubUserId() !== $this->surgicalHubUserId) {
            throw new \InvalidArgumentException('Only an association of the same pair of accounts can take an import over.');
        }

        $this->link = $link;
    }

    public function redescribe(\DateTimeImmutable $startDate, \DateTimeImmutable $endDate, \DateTimeImmutable $now): void
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->lastChangedAt = $now;
    }

    /** Whether the absence, as last described, has a day in [$from, $to] (dates, both inclusive). */
    public function intersects(\DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        return $this->startDate->format('Y-m-d') <= $to->format('Y-m-d')
            && $this->endDate->format('Y-m-d') >= $from->format('Y-m-d');
    }
}
