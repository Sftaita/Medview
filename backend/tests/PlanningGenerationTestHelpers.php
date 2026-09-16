<?php

declare(strict_types=1);

namespace App\Tests;

use App\Dto\PlanningRuleSetConfiguration;
use App\Entity\Duty;
use App\Entity\DutyType;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningRuleSet;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\TeamMemberNonParticipationPeriod;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use App\Entity\UserAvailabilityPeriod;
use App\Entity\UserAvailabilityType;
use App\Service\DutyMaterializationService;
use App\Service\PlanningRuleSetService;
use App\Service\TeamMemberNonParticipationService;
use App\Service\TeamMembershipService;
use App\Service\UserAvailabilityService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fixture builders shared by PlanningGeneration/PlanningSnapshot/
 * DutyAssignment tests — built on top of PlanningDomainTestHelpers, going
 * through the real domain services (never constructing entities directly
 * where a service exists) so fixtures stay representative of real usage.
 *
 * Every method takes the service it needs as a parameter rather than
 * resolving it via self::getContainer() internally — WebTestCase reboots
 * the kernel (and its container) around each $client->request() call, so a
 * service resolved *after* such a request can belong to a different
 * EntityManager instance than one resolved earlier, which makes Doctrine
 * treat an already-persisted entity as unknown. Callers resolve services
 * once, from the same container reference, and pass them in explicitly.
 */
trait PlanningGenerationTestHelpers
{
    private function addMember(
        TeamMembershipService $service,
        Team $team,
        User $user,
        TeamMemberRole $role = TeamMemberRole::MEMBER,
        string $membershipStart = '2027-01-01',
        float $participationFactor = 1.0,
    ): TeamMember {
        return $service->addMember($team, $user, $role, $this->date($membershipStart), $participationFactor);
    }

    private function createDutyType(EntityManagerInterface $em, Team $team, string $code = 'ONCALL'): DutyType
    {
        $dutyType = new DutyType($team, $code, ucfirst(strtolower($code)));
        $em->persist($dutyType);
        $em->flush();

        return $dutyType;
    }

    private function createDuty(
        DutyMaterializationService $service,
        PlanningPeriod $planningPeriod,
        DutyType $dutyType,
        string $localStartsAt,
        string $localEndsAt,
    ): Duty {
        return $service->createStandaloneDuty($planningPeriod, $dutyType, $this->date($localStartsAt), $this->date($localEndsAt));
    }

    private function activateRuleSet(PlanningRuleSetService $service, Team $team, ?PlanningRuleSetConfiguration $configuration = null): PlanningRuleSet
    {
        $ruleSet = $service->createDraft($team, $this->date('2027-01-01'), $configuration ?? new PlanningRuleSetConfiguration());
        $service->activate($ruleSet);

        return $ruleSet;
    }

    private function addAvailability(
        UserAvailabilityService $service,
        User $user,
        UserAvailabilityType $type,
        string $startsAt,
        string $endsAt,
    ): UserAvailabilityPeriod {
        return $service->create($user, $type, $this->date($startsAt), $this->date($endsAt));
    }

    private function addNonParticipation(TeamMemberNonParticipationService $service, TeamMember $member, string $startsAt, string $endsAt): TeamMemberNonParticipationPeriod
    {
        return $service->create($member, $this->date($startsAt), $this->date($endsAt));
    }
}
