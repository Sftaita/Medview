<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningPublicationEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What one PlanningPublication diffused for one Duty (docs/decisions.md
 * D143): the PlanningTeamMember then assigned, or null when the Duty was
 * published uncovered. Append-only (database trigger), never recomputed.
 * Holds the member, not the DutyAssignment row: a duty reassigned A → B → A
 * gets a new DutyAssignment row each time (D131), yet nothing changed for
 * the people who were told "A".
 */
#[ORM\Entity(repositoryClass: PlanningPublicationEntryRepository::class)]
#[ORM\Table(name: 'planning_publication_entries')]
#[ORM\UniqueConstraint(name: 'uniq_planning_publication_entries_duty', columns: ['publication_id', 'duty_id'])]
class PlanningPublicationEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningPublication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private PlanningPublication $publication;

    #[ORM\ManyToOne(targetEntity: Duty::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Duty $duty;

    #[ORM\ManyToOne(targetEntity: PlanningTeamMember::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?PlanningTeamMember $teamMember;

    public function __construct(PlanningPublication $publication, Duty $duty, ?PlanningTeamMember $teamMember)
    {
        $this->publication = $publication;
        $this->duty = $duty;
        $this->teamMember = $teamMember;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublication(): PlanningPublication
    {
        return $this->publication;
    }

    public function getDuty(): Duty
    {
        return $this->duty;
    }

    public function getTeamMember(): ?PlanningTeamMember
    {
        return $this->teamMember;
    }
}
