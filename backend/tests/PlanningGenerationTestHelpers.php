<?php

declare(strict_types=1);

namespace App\Tests;

use App\Dto\PlanningRuleSetConfiguration;
use App\Entity\Duty;
use App\Entity\DutyGroupInstance;
use App\Entity\DutyPattern;
use App\Entity\DutyType;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningRuleSet;
use App\Entity\PlanningTeam;
use App\Entity\PlanningTeamMember;
use App\Entity\TeamMemberNonParticipationPeriod;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use App\Entity\UserAvailabilityPeriod;
use App\Entity\UserAvailabilityType;
use App\Service\DutyMaterializationService;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningTeamMembershipService;
use App\Service\TeamMemberNonParticipationService;
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
        PlanningTeamMembershipService $service,
        PlanningTeam $team,
        User $user,
        TeamMemberRole $role = TeamMemberRole::MEMBER,
        string $membershipStart = '2027-01-01',
        float $participationFactor = 1.0,
    ): PlanningTeamMember {
        return $service->addMember($team, $user, $role, $this->date($membershipStart), $participationFactor);
    }

    private function createDutyType(EntityManagerInterface $em, PlanningTeam $team, string $code = 'ONCALL'): DutyType
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

    /**
     * A two-component DutyGroupInstance (dayOffset 0 and 1), for group
     * eligibility tests. Returns the group and its two Duty rows ordered
     * by localDate.
     *
     * @return array{0: DutyGroupInstance, 1: Duty, 2: Duty}
     */
    private function createTwoDutyGroup(
        EntityManagerInterface $em,
        DutyMaterializationService $service,
        PlanningTeam $team,
        PlanningPeriod $planningPeriod,
        string $anchorDate,
        string $day0StartsAt,
        string $day0EndsAt,
        string $day1StartsAt,
        string $day1EndsAt,
    ): array {
        $pattern = new DutyPattern($team, 'GRP-'.bin2hex(random_bytes(3)), 'Test group');
        $em->persist($pattern);

        $dutyType0 = new DutyType($team, 'D0-'.bin2hex(random_bytes(3)), 'Day 0');
        $dutyType1 = new DutyType($team, 'D1-'.bin2hex(random_bytes(3)), 'Day 1');
        $em->persist($dutyType0);
        $em->persist($dutyType1);
        $pattern->addComponent(0, $dutyType0);
        $pattern->addComponent(1, $dutyType1);
        $em->flush();

        $group = $service->materializeGroup($planningPeriod, $pattern, $this->date($anchorDate), [
            0 => [$this->date($day0StartsAt), $this->date($day0EndsAt)],
            1 => [$this->date($day1StartsAt), $this->date($day1EndsAt)],
        ]);

        $duties = $group->getDuties()->toArray();
        usort($duties, static fn (Duty $a, Duty $b): int => $a->getLocalDate() <=> $b->getLocalDate());

        return [$group, $duties[0], $duties[1]];
    }

    private function activateRuleSet(PlanningRuleSetService $service, PlanningTeam $team, ?PlanningRuleSetConfiguration $configuration = null): PlanningRuleSet
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

    private function addNonParticipation(TeamMemberNonParticipationService $service, PlanningTeamMember $member, string $startsAt, string $endsAt): TeamMemberNonParticipationPeriod
    {
        return $service->create($member, $this->date($startsAt), $this->date($endsAt));
    }
}
