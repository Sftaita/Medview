<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningPublicationLineRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One line a PlanningPublication concerned (docs/decisions.md D196):
 * FIRST_FOR_LINE — its first publication in the period, whether or not the
 * period already had one — or UPDATED — changes to its published calendar
 * announced. Append-only (database trigger), frozen with the publication.
 *
 * The line is kept as values (stable id, name at publication time): in
 * single-period compatibility a published line can still be deleted, its
 * period staying behind (D188). The period and the cycle are real keys, tied
 * to each other and to the publication's cycle in the database.
 */
#[ORM\Entity(repositoryClass: PlanningPublicationLineRepository::class)]
#[ORM\Table(name: 'planning_publication_lines')]
#[ORM\UniqueConstraint(name: 'uniq_planning_publication_lines_period', columns: ['publication_id', 'planning_period_id'])]
#[ORM\Index(columns: ['planning_cycle_id'], name: 'idx_planning_publication_lines_cycle')]
class PlanningPublicationLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningPublication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningPublication $publication;

    #[ORM\ManyToOne(targetEntity: PlanningCycle::class)]
    #[ORM\JoinColumn(name: 'planning_cycle_id', nullable: false, onDelete: 'RESTRICT')]
    private PlanningCycle $cycle;

    #[ORM\ManyToOne(targetEntity: PlanningPeriod::class)]
    #[ORM\JoinColumn(name: 'planning_period_id', nullable: false, onDelete: 'RESTRICT')]
    private PlanningPeriod $period;

    /** Null only on a row reconstructed by the L4c migration for a period whose line was deleted before. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $lineStableId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lineName;

    #[ORM\Column(length: 20, enumType: PlanningPublicationLineKind::class)]
    private PlanningPublicationLineKind $kind;

    /** Duties of the line this publication froze (its entries). */
    #[ORM\Column]
    private int $dutyCount;

    /** Duties whose published holder changed since the line's previous publication — 0 for FIRST_FOR_LINE. */
    #[ORM\Column]
    private int $changedDutyCount;

    /** Rebuilt from the frozen entries of a publication made before L4c — never by a publication. */
    #[ORM\Column]
    private bool $reconstructed = false;

    public function __construct(PlanningPublication $publication, PlanningPeriod $period, PlanningLine $line, PlanningPublicationLineKind $kind, int $dutyCount, int $changedDutyCount)
    {
        $cycle = $period->getCycle() ?? throw new \InvalidArgumentException('A published period always belongs to a cycle.');
        if ($cycle !== $publication->getCycle()) {
            throw new \InvalidArgumentException('A publication only concerns lines of its own period.');
        }
        if ($period->getLine() !== $line) {
            throw new \InvalidArgumentException('The period must be the line\'s.');
        }
        if (PlanningPublicationLineKind::UPDATED === $kind && $changedDutyCount < 1) {
            throw new \InvalidArgumentException('A line is UPDATED only when its published content changed.');
        }
        if (PlanningPublicationLineKind::FIRST_FOR_LINE === $kind && 0 !== $changedDutyCount) {
            throw new \InvalidArgumentException('A first publication changes nothing previously published.');
        }

        $this->publication = $publication;
        $this->cycle = $cycle;
        $this->period = $period;
        $this->lineStableId = $line->getStableId();
        $this->lineName = $line->getName();
        $this->kind = $kind;
        $this->dutyCount = $dutyCount;
        $this->changedDutyCount = $changedDutyCount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublication(): PlanningPublication
    {
        return $this->publication;
    }

    public function getCycle(): PlanningCycle
    {
        return $this->cycle;
    }

    public function getPeriod(): PlanningPeriod
    {
        return $this->period;
    }

    public function getLineStableId(): ?Uuid
    {
        return $this->lineStableId;
    }

    public function getLineName(): ?string
    {
        return $this->lineName;
    }

    public function getKind(): PlanningPublicationLineKind
    {
        return $this->kind;
    }

    public function getDutyCount(): int
    {
        return $this->dutyCount;
    }

    public function getChangedDutyCount(): int
    {
        return $this->changedDutyCount;
    }

    public function isReconstructed(): bool
    {
        return $this->reconstructed;
    }
}
