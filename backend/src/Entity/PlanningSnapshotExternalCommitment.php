<?php

declare(strict_types=1);

namespace App\Entity;

use App\Eligibility\CommitmentInterval;
use App\Repository\PlanningSnapshotExternalCommitmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A duty one person of this snapshot already holds on ANOTHER line of the
 * Planning, frozen when the snapshot is taken (docs/decisions.md D161).
 *
 * A User may belong to several lines of one Planning (D160). Lines are
 * solved one after another (sources first, then position —
 * PlanningLineOrder): when a line's snapshot is taken, the current
 * assignments of the lines that precede it are commitments of their
 * holders, and EligibilityService removes every edge incompatible with
 * them (CROSS_LINE_CONFLICT, CROSS_LINE_LEGAL_MIN_REST,
 * CROSS_LINE_TEAM_MIN_REST) — local exclusions, never a global CP-SAT
 * constraint, since the other line's duty is already decided.
 *
 * Keyed by the person ($sourceUserStableId), never by a
 * PlanningTeamMember: the same User has a different stint on each line,
 * and it is the person who cannot be in two places at once.
 *
 * Only values, never a live relation (same rule as
 * PlanningSnapshotMember, docs/planning-generation.md §3): the other
 * line's calendar may change afterwards — a reassignment, a new generation
 * — and an old snapshot must keep explaining its own solve. The owning
 * generation's rest thresholds are copied too, because the cross-line rest
 * rule uses the stricter of both generations' thresholds
 * (PersonCommitmentChecker).
 *
 * No setter: constructed once, never edited (PlanningSnapshot).
 */
#[ORM\Entity(repositoryClass: PlanningSnapshotExternalCommitmentRepository::class)]
#[ORM\Table(name: 'planning_snapshot_external_commitments')]
#[ORM\Index(columns: ['snapshot_id', 'source_user_stable_id'], name: 'idx_snapshot_external_commitments_snapshot_user')]
class PlanningSnapshotExternalCommitment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningSnapshot::class, inversedBy: 'externalCommitments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanningSnapshot $snapshot;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sourceUserStableId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sourceLineStableId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sourceGenerationStableId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sourceDutyStableId;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $endsAt;

    /** The owning generation's LEGAL_MIN_REST in hours, null when it was disabled there. */
    #[ORM\Column(nullable: true)]
    private ?int $sourceLegalMinRestHours;

    /** The owning generation's TEAM_MIN_REST in hours, null when it was disabled there. */
    #[ORM\Column(nullable: true)]
    private ?int $sourceTeamMinRestHours;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        PlanningSnapshot $snapshot,
        Uuid $sourceUserStableId,
        Uuid $sourceLineStableId,
        Uuid $sourceGenerationStableId,
        Uuid $sourceDutyStableId,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ?int $sourceLegalMinRestHours,
        ?int $sourceTeamMinRestHours,
    ) {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('endsAt must be strictly after startsAt.');
        }

        $this->snapshot = $snapshot;
        $this->sourceUserStableId = $sourceUserStableId;
        $this->sourceLineStableId = $sourceLineStableId;
        $this->sourceGenerationStableId = $sourceGenerationStableId;
        $this->sourceDutyStableId = $sourceDutyStableId;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->sourceLegalMinRestHours = $sourceLegalMinRestHours;
        $this->sourceTeamMinRestHours = $sourceTeamMinRestHours;
        $this->createdAt = new \DateTimeImmutable();
        $snapshot->addExternalCommitment($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSnapshot(): PlanningSnapshot
    {
        return $this->snapshot;
    }

    public function getSourceUserStableId(): Uuid
    {
        return $this->sourceUserStableId;
    }

    public function getSourceLineStableId(): Uuid
    {
        return $this->sourceLineStableId;
    }

    public function getSourceGenerationStableId(): Uuid
    {
        return $this->sourceGenerationStableId;
    }

    public function getSourceDutyStableId(): Uuid
    {
        return $this->sourceDutyStableId;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getSourceLegalMinRestHours(): ?int
    {
        return $this->sourceLegalMinRestHours;
    }

    public function getSourceTeamMinRestHours(): ?int
    {
        return $this->sourceTeamMinRestHours;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Always a commitment of ANOTHER generation: a snapshot only ever
     * freezes duties of other lines.
     */
    public function toInterval(): CommitmentInterval
    {
        return new CommitmentInterval(
            $this->startsAt,
            $this->endsAt,
            false,
            $this->sourceLegalMinRestHours,
            $this->sourceTeamMinRestHours,
            (string) $this->sourceDutyStableId,
            (string) $this->sourceLineStableId,
        );
    }
}
