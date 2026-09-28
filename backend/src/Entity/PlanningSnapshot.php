<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningSnapshotRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The immutable root of one PlanningGeneration's frozen input data
 * (docs/planning-generation.md "Snapshot"). At most one per generation —
 * enforced by the unique constraint on $generation, which is also the
 * mechanism PlanningSnapshotService relies on to turn a concurrent
 * double-snapshot attempt into a clean conflict instead of a duplicate
 * (see PlanningGenerationAlreadySnapshottedException).
 *
 * Genuinely immutable after construction: no setter exists anywhere on
 * this entity or any of its children (PlanningSnapshotMember and its own
 * children, PlanningSnapshotRuleSet, PlanningSnapshotExternalCommitment)
 * — a new generation gets a new snapshot, the old one is never edited
 * (CLAUDE.md).
 */
#[ORM\Entity(repositoryClass: PlanningSnapshotRepository::class)]
#[ORM\Table(name: 'planning_snapshots')]
#[ORM\UniqueConstraint(name: 'uniq_planning_snapshots_generation_id', columns: ['generation_id'])]
class PlanningSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningGeneration::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningGeneration $generation;

    /**
     * @var Collection<int, PlanningSnapshotMember>
     */
    #[ORM\OneToMany(targetEntity: PlanningSnapshotMember::class, mappedBy: 'snapshot')]
    private Collection $members;

    /**
     * Duties the snapshot's people already hold on other lines of the
     * Planning (docs/decisions.md D161).
     *
     * @var Collection<int, PlanningSnapshotExternalCommitment>
     */
    #[ORM\OneToMany(targetEntity: PlanningSnapshotExternalCommitment::class, mappedBy: 'snapshot')]
    private Collection $externalCommitments;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(PlanningGeneration $generation)
    {
        $this->generation = $generation;
        $this->members = new ArrayCollection();
        $this->externalCommitments = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGeneration(): PlanningGeneration
    {
        return $this->generation;
    }

    /**
     * @return Collection<int, PlanningSnapshotMember>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    /**
     * Keeps the inverse side of the association in sync in-memory — see
     * TeamMember::addParticipationPeriod() for why this is necessary.
     *
     * @internal
     */
    public function addMember(PlanningSnapshotMember $member): void
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
        }
    }

    /**
     * @return Collection<int, PlanningSnapshotExternalCommitment>
     */
    public function getExternalCommitments(): Collection
    {
        return $this->externalCommitments;
    }

    /**
     * Keeps the inverse side in sync in-memory, same as addMember().
     *
     * @internal
     */
    public function addExternalCommitment(PlanningSnapshotExternalCommitment $commitment): void
    {
        if (!$this->externalCommitments->contains($commitment)) {
            $this->externalCommitments->add($commitment);
        }
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
