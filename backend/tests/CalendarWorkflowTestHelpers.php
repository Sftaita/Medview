<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Duty;
use App\Entity\DutyGroupInstance;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Service\DutyMaterializationService;
use App\Service\PlanningRuleSetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The generation → edition → publication workflow (docs/decisions.md
 * D143-D147), driven through the real API on top of PlanningPilotTestHelpers'
 * scenario (creator, admin, alice, bob; planning 2027-01-01 → 2027-05-01,
 * Europe/Brussels). A real OR-Tools solve runs on every generation.
 */
trait CalendarWorkflowTestHelpers
{
    use PlanningPilotTestHelpers;

    /**
     * Gives line $lineIndex an active rule set, standalone duties (one per
     * [from, to] range) and, optionally, one two-day atomic block.
     *
     * @param list<array{0: string, 1: string}>           $standalone
     * @param array{0: string, 1: string, 2: string}|null $block      [anchor/day0 start, day0 end = day1 start, day1 end]
     *
     * @return DutyGroupInstance|null the block, when requested
     */
    private function prepareLine(string $planningStableId, array $standalone, ?array $block = null, int $lineIndex = 0): ?DutyGroupInstance
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[$lineIndex];
        $em = $container->get(EntityManagerInterface::class);
        $materializer = $container->get(DutyMaterializationService::class);

        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $line->getPlanningTeam());
        $dutyType = $this->createDutyType($em, $line->getPlanningTeam());
        foreach ($standalone as [$from, $to]) {
            $this->createDuty($materializer, $line->getPlanningPeriod(), $dutyType, $from, $to);
        }

        if (null === $block) {
            return null;
        }

        [$group] = $this->createTwoDutyGroup($em, $materializer, $line->getPlanningTeam(), $line->getPlanningPeriod(), $block[0], $block[0], $block[1], $block[1], $block[2]);

        return $group;
    }

    /**
     * @param array<string, mixed> $s
     */
    private function generate(KernelBrowser $client, array $s): void
    {
        $this->generateNow($client, $s['planningId'], $s['creator']);
        self::assertResponseIsSuccessful();
    }

    /**
     * Current holder of every duty of the planning, keyed "lineIndex|YYYY-MM-DD|dutyTypeCode" → user email, or null when uncovered.
     *
     * @return array<string, string|null>
     */
    private function currentCalendar(string $planningStableId): array
    {
        $container = static::getContainer();
        $container->get(EntityManagerInterface::class)->clear();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $calendar = [];
        foreach ($container->get(PlanningLineRepository::class)->findByPlanning($planning) as $index => $line) {
            $generation = $container->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($line->getPlanningPeriod());
            $byDuty = [];
            if (null !== $generation) {
                foreach ($container->get(DutyAssignmentRepository::class)->findForGenerations([$generation]) as $assignment) {
                    $byDuty[(int) $assignment->getDuty()->getId()] = $assignment->getTeamMember()->getUser()->getEmail();
                }
            }
            foreach ($container->get(DutyRepository::class)->findByPlanningPeriod($line->getPlanningPeriod()) as $duty) {
                $calendar[$index.'|'.$duty->getLocalDate()->format('Y-m-d').'|'.$duty->getDutyType()->getCode()] = $byDuty[(int) $duty->getId()] ?? null;
            }
        }
        ksort($calendar);

        return $calendar;
    }

    /** The stableId of the (first) duty of line $lineIndex on $date. */
    private function dutyOn(string $planningStableId, string $date, int $lineIndex = 0): string
    {
        return (string) $this->dutyEntityOn($planningStableId, $date, $lineIndex)->getStableId();
    }

    private function dutyEntityOn(string $planningStableId, string $date, int $lineIndex = 0): Duty
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[$lineIndex];
        foreach ($container->get(DutyRepository::class)->findByPlanningPeriod($line->getPlanningPeriod()) as $duty) {
            if ($duty->getLocalDate()->format('Y-m-d') === $date) {
                return $duty;
            }
        }

        self::fail("No duty on {$date} for line {$lineIndex}.");
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function candidatesFor(KernelBrowser $client, array $s, string $dutyStableId, ?string $token = null): array
    {
        return $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/duties/{$dutyStableId}/reassignment-candidates", token: $token ?? $s['creator']);
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function reassignTo(KernelBrowser $client, array $s, string $dutyStableId, string $email, ?string $token = null, int $lineIndex = 0): array
    {
        $view = $this->candidatesFor($client, $s, $dutyStableId, $token);

        return $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$dutyStableId}/reassign", [
            'teamMemberStableId' => $this->memberStableIdIn($s['planningId'], $email, $lineIndex),
            'expectedCurrentTeamMemberStableId' => $view['currentTeamMemberStableId'] ?? null,
        ], $token ?? $s['creator']);
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function unassignDuty(KernelBrowser $client, array $s, string $dutyStableId, ?string $token = null): array
    {
        $view = $this->candidatesFor($client, $s, $dutyStableId, $token);

        return $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$dutyStableId}/unassign", [
            'expectedCurrentTeamMemberStableId' => $view['currentTeamMemberStableId'] ?? null,
        ], $token ?? $s['creator']);
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function completePlanning(KernelBrowser $client, array $s, ?string $token = null): array
    {
        // Asynchronous since docs/decisions.md D149: 202 + a queued job, run here by "the worker";
        // returns the finished job's outcome (its per-line `lines`), plus the job itself.
        $response = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/complete", [], $token ?? $s['creator']);
        if (202 !== $client->getResponse()->getStatusCode()) {
            return $response;
        }
        $this->runQueuedPlanningJobs();
        $job = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/jobs/latest", token: $token ?? $s['creator'])['job'];

        return ($job['outcome'] ?? []) + ['job' => $job];
    }

    /** The PlanningTeamMember stableId of $email in line $lineIndex's team. */
    private function memberStableIdIn(string $planningStableId, string $email, int $lineIndex = 0): string
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $team = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[$lineIndex]->getPlanningTeam();
        foreach ($container->get(PlanningTeamMemberRepository::class)->findByTeam($team) as $member) {
            if ($member->getUser()->getEmail() === $email) {
                return (string) $member->getStableId();
            }
        }

        self::fail("{$email} is not a member of line {$lineIndex}.");
    }

    /**
     * Adds a SECONDARY line (its own team "Juniors") with the given members, all MEMBER.
     *
     * @param array<string, mixed> $s
     * @param list<string>         $emails
     */
    private function addSecondaryLine(KernelBrowser $client, array $s, array $emails): string
    {
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/lines", ['name' => 'Juniors'], $s['creator']);
        self::assertResponseStatusCodeSame(201);
        $detail = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator']);
        $teamId = $detail['lines'][1]['team']['stableId'];
        foreach ($emails as $email) {
            $this->userToken($client, $email);
            $this->addMemberAs($client, $s['creator'], $s['planningId'], $teamId, $email, 'MEMBER');
        }

        return $teamId;
    }
}
