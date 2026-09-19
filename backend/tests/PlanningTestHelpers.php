<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningLineType;
use App\Entity\User;
use App\Service\PlanningLineService;
use App\Service\PlanningService;

/**
 * Fixture builders for the Planning/PlanningLine lot (docs/planning.md),
 * built on top of PlanningDomainTestHelpers/PlanningGenerationTestHelpers.
 * Same convention as PlanningGenerationTestHelpers: every method takes the
 * service it needs as a parameter, never resolved internally via
 * self::getContainer() — see that trait's docblock for why (WebTestCase
 * kernel reboot around each request).
 *
 * Since PlanningLineService::addLine() now creates its own PlanningTeam
 * inline from a name (docs/decisions.md D079), these helpers no longer take
 * a pre-existing Team — createPlanning()'s $primaryTeamName and addLine()'s
 * $name are plain strings.
 */
trait PlanningTestHelpers
{
    private function createPlanning(
        PlanningService $service,
        User $creator,
        string $primaryTeamName = 'Ligne principale',
        string $name = 'Gardes Test',
        string $startsAt = '2027-01-01',
        string $endsAt = '2027-05-01',
        string $timezone = 'Europe/Brussels',
    ): Planning {
        return $service->create($name, $creator, $this->date($startsAt), $this->date($endsAt), $timezone, $primaryTeamName);
    }

    private function addLine(
        PlanningLineService $service,
        Planning $planning,
        string $name = 'Ligne secondaire',
        PlanningLineType $type = PlanningLineType::SECONDARY,
    ): PlanningLine {
        return $service->addLine($planning, $name, $type);
    }
}
