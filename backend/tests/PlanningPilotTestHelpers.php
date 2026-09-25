<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\PlanningPeriodStatus;
use App\Entity\User;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\UserRepository;
use App\Service\DutyMaterializationService;
use App\Service\PlanningRuleSetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Fixtures for the OWNER/ADMIN pilot lot (docs/availability-collection.md §15):
 * a planning with a creator, a team ADMIN, two plain members and an outsider,
 * driven through the real API. Expects a WebTestCase using ClockSensitiveTrait.
 */
trait PlanningPilotTestHelpers
{
    use InvitationTestHelpers;
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;

    /**
     * Planning 2027-01-01 → 2027-05-01 (last day 2027-04-30), primary team "Seniors".
     *
     * @return array<string, mixed>
     */
    private function pilotScenario(KernelBrowser $client): array
    {
        $creator = $this->userToken($client, 'creator@example.com');
        $admin = $this->userToken($client, 'admin@example.com');
        $alice = $this->userToken($client, 'alice@example.com');
        $bob = $this->userToken($client, 'bob@example.com');
        $outsider = $this->userToken($client, 'outsider@example.com');

        [$planningId, $teamId] = $this->createPlanningWithTeam($client, $creator);
        $this->addMemberAs($client, $creator, $planningId, $teamId, 'admin@example.com', 'ADMIN');
        $this->addMemberAs($client, $creator, $planningId, $teamId, 'alice@example.com', 'MEMBER');
        $this->addMemberAs($client, $creator, $planningId, $teamId, 'bob@example.com', 'MEMBER');

        $collections = $this->api($client, 'GET', "/api/plannings/{$planningId}/availability-collections", token: $creator);
        self::assertResponseIsSuccessful();

        return [
            'planningId' => $planningId,
            'teamId' => $teamId,
            'collectionId' => $collections[0]['stableId'],
            'creator' => $creator,
            'admin' => $admin,
            'alice' => $alice,
            'bob' => $bob,
            'outsider' => $outsider,
        ];
    }

    /**
     * The PlanningTeamMember stableId of $email in the planning's primary team.
     *
     * @param array<string, mixed> $s
     */
    private function memberIdOf(KernelBrowser $client, array $s, string $email): string
    {
        $userStableId = (string) static::getContainer()->get(UserRepository::class)->findOneByEmail($email)->getStableId();
        $members = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/teams/{$s['teamId']}/members", token: $s['creator']);
        foreach ($members as $member) {
            if ($member['userStableId'] === $userStableId) {
                return $member['stableId'];
            }
        }

        self::fail("{$email} is not a member of the planning.");
    }

    /**
     * Declares a whole-day range in the person's own calendar ($to inclusive-exclusive like the API:
     * midnight Brussels of $from to midnight Brussels of $to).
     *
     * @return array<string, mixed>
     */
    private function declareRange(KernelBrowser $client, string $token, string $from, string $to, string $type = 'UNAVAILABLE'): array
    {
        $tz = new \DateTimeZone('Europe/Brussels');
        $body = [
            'type' => $type,
            'startsAt' => (new \DateTimeImmutable($from.' 00:00:00', $tz))->format(\DATE_ATOM),
            'endsAt' => (new \DateTimeImmutable($to.' 00:00:00', $tz))->format(\DATE_ATOM),
        ];
        $result = $this->api($client, 'POST', '/api/me/calendar', $body, $token);
        self::assertResponseStatusCodeSame(201);

        return $result;
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function collectionStatus(KernelBrowser $client, array $s, ?string $token = null): array
    {
        $result = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/collection-status", token: $token ?? $s['creator']);
        self::assertResponseIsSuccessful();

        return $result;
    }

    /**
     * @param array<string, mixed> $status a collection-status body
     *
     * @return array<string, mixed> the row of $email
     */
    private function rowOf(array $status, string $email): array
    {
        $userStableId = (string) static::getContainer()->get(UserRepository::class)->findOneByEmail($email)->getStableId();
        foreach ($status['members'] as $row) {
            if ($row['userStableId'] === $userStableId) {
                return $row;
            }
        }

        self::fail("{$email} has no row in the collection status.");
    }

    /**
     * Gives the planning's primary line what the generation pipeline needs and
     * the UI cannot create yet (an active rule set and duties): the same
     * services a future configuration screen would call.
     *
     * @param list<array{0: string, 1: string}> $dutyRanges [localStartsAt, localEndsAt] of standalone duties
     */
    private function prepareGeneration(string $planningStableId, array $dutyRanges = [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07']], int $lineIndex = 0): void
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[$lineIndex];
        $em = $container->get(EntityManagerInterface::class);

        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $line->getPlanningTeam());
        $dutyType = $this->createDutyType($em, $line->getPlanningTeam());
        foreach ($dutyRanges as [$from, $to]) {
            $this->createDuty($container->get(DutyMaterializationService::class), $line->getPlanningPeriod(), $dutyType, $from, $to);
        }
    }

    /**
     * Same as prepareGeneration(), but the demand is one atomic two-day
     * DutyGroupInstance (a "WE") instead of standalone duties — used by the
     * dynamic-calendar block tests (docs/decisions.md D131).
     *
     * @return array{0: \App\Entity\DutyGroupInstance, 1: \App\Entity\Duty, 2: \App\Entity\Duty}
     */
    private function prepareBlockGeneration(string $planningStableId, string $anchorDate, string $day0StartsAt, string $day0EndsAt, string $day1StartsAt, string $day1EndsAt, int $lineIndex = 0): array
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[$lineIndex];
        $em = $container->get(EntityManagerInterface::class);

        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $line->getPlanningTeam());

        return $this->createTwoDutyGroup(
            $em,
            $container->get(DutyMaterializationService::class),
            $line->getPlanningTeam(),
            $line->getPlanningPeriod(),
            $anchorDate,
            $day0StartsAt,
            $day0EndsAt,
            $day1StartsAt,
            $day1EndsAt,
        );
    }

    private function periodStatusOf(string $planningStableId): PlanningPeriodStatus
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);

        return $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningPeriod()->getStatus();
    }

    private function userOf(string $email): User
    {
        return static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
    }
}
