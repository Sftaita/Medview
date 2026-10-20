<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningGeneration;
use App\Entity\PlanningPeriod;

/**
 * What the lines already solved in a launch are about to hold, before
 * anything is written (docs/decisions.md D191): a generation is solved, its
 * result kept here, and written only once the whole launch is checked
 * (PlanningGenerationService::writeLaunch()).
 *
 * For the period of a generation it holds, it replaces the period's current
 * calendar wherever a calendar is read during the launch: the commitments a
 * later line's snapshot freezes (D161 order unchanged), the holders a
 * conditional line's demand is decided from (D164), and the final calendar
 * checked before writing.
 */
final class PlannedCalendar
{
    /** @var array<int, PlanningGeneration> by period id */
    private array $generations = [];

    /** @var array<int, list<PersonCommitment>> by period id */
    private array $commitments = [];

    /**
     * @param list<PersonCommitment> $commitments the assignments $generation would write
     */
    public function add(PlanningGeneration $generation, array $commitments): void
    {
        $periodId = (int) $generation->getPlanningPeriod()->getId();
        $this->generations[$periodId] = $generation;
        $this->commitments[$periodId] = $commitments;
    }

    public function covers(PlanningPeriod $period): bool
    {
        return isset($this->generations[(int) $period->getId()]);
    }

    public function generationOf(PlanningPeriod $period): ?PlanningGeneration
    {
        return $this->generations[(int) $period->getId()] ?? null;
    }

    /**
     * @return list<PersonCommitment>
     */
    public function commitmentsOf(PlanningPeriod $period): array
    {
        return $this->commitments[(int) $period->getId()] ?? [];
    }

    /**
     * @return array<int, string> the planned holder (User stable id) of each duty of $period, by duty id
     */
    public function holdersOf(PlanningPeriod $period): array
    {
        $holders = [];
        foreach ($this->commitmentsOf($period) as $commitment) {
            $holders[(int) $commitment->duty->getId()] = (string) $commitment->teamMember->getUser()->getStableId();
        }

        return $holders;
    }
}
