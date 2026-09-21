<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DutyType;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningGenerationStatus;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\UserRepository;
use App\Service\DutyAssignmentService;
use App\Service\DutyMaterializationService;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningSnapshotService;
use App\Tests\InvitationTestHelpers;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "A person's planning" (docs/planning.md §14, D125): the assignments of a
 * planning, whole team or one member, with a summary computed from the
 * person's actual assignments only.
 *
 * Fridays 2027-02-05, Saturdays 2027-02-06, Sundays 2027-02-07 (1 January
 * 2027 is a Friday).
 */
final class PlanningAssignmentControllerTest extends WebTestCase
{
    use InvitationTestHelpers;
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;

    /**
     * @return array<string, mixed>
     */
    private function scenario(KernelBrowser $client, bool $completed = true): array
    {
        $creator = $this->userToken($client, 'creator@example.com');
        $alice = $this->userToken($client, 'alice@example.com');
        $bob = $this->userToken($client, 'bob@example.com');
        $outsider = $this->userToken($client, 'outsider@example.com');
        [$planningId, $teamId] = $this->createPlanningWithTeam($client, $creator);
        $this->addMemberAs($client, $creator, $planningId, $teamId, 'alice@example.com', 'MEMBER');
        $this->addMemberAs($client, $creator, $planningId, $teamId, 'bob@example.com', 'MEMBER');

        // Domain fixtures (resolved after the last request: WebTestCase reboots the kernel around each one).
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningId);
        $period = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningPeriod();
        $team = $period->getTeam();
        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $team);

        $day = new DutyType($team, 'DAY', 'Jour', 1.0);
        $night = new DutyType($team, 'NIGHT', 'Nuit', 1.5);
        $em->persist($day);
        $em->persist($night);
        $em->flush();

        $users = $container->get(UserRepository::class);
        $members = $container->get(PlanningTeamMemberRepository::class);
        $aliceMember = $members->findOpenMembershipForUserInPlanning($planning, $users->findOneByEmail('alice@example.com'));
        $bobMember = $members->findOpenMembershipForUserInPlanning($planning, $users->findOneByEmail('bob@example.com'));

        $materialization = $container->get(DutyMaterializationService::class);
        $generation = new PlanningGeneration($period);
        $em->persist($generation);
        $em->flush();
        $container->get(PlanningSnapshotService::class)->createSnapshot($generation);

        $assignments = $container->get(DutyAssignmentService::class);
        $plan = [
            [$aliceMember, $night, '2027-02-05 20:00', '2027-02-06 08:00'], // Friday night
            [$aliceMember, $day, '2027-02-06 08:00', '2027-02-06 20:00'],   // Saturday
            [$aliceMember, $day, '2027-03-02 08:00', '2027-03-02 20:00'],   // Tuesday, in March
            [$bobMember, $day, '2027-02-07 08:00', '2027-02-07 20:00'],     // Sunday
        ];
        foreach ($plan as [$member, $type, $from, $to]) {
            $duty = $this->createDuty($materialization, $period, $type, $from, $to);
            $assignments->createManual($generation, $duty, $member, false);
        }

        if ($completed) {
            $generation->transitionTo(PlanningGenerationStatus::SOLVING);
            $generation->transitionTo(PlanningGenerationStatus::COMPLETED);
            $em->flush();
        }

        return [
            'planningId' => $planningId,
            'creator' => $creator,
            'alice' => $alice,
            'bob' => $bob,
            'outsider' => $outsider,
            'aliceUserId' => (string) $users->findOneByEmail('alice@example.com')->getStableId(),
            'bobUserId' => (string) $users->findOneByEmail('bob@example.com')->getStableId(),
            'outsiderUserId' => (string) $users->findOneByEmail('outsider@example.com')->getStableId(),
        ];
    }

    public function testTheWholeTeamViewListsEveryAssignmentWithoutSummary(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $data = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/assignments", token: $s['bob']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $data['generations']);
        self::assertCount(4, $data['assignments']);
        self::assertNull($data['summary']);
        self::assertSame(
            ['2027-02-05', '2027-02-06', '2027-02-07', '2027-03-02'],
            array_map(static fn (array $a): string => $a['date'], $data['assignments']),
            'chronological order',
        );
        self::assertSame('Nuit', $data['assignments'][0]['dutyType']['name']);
        self::assertSame('Seniors', $data['assignments'][0]['lineName']);
    }

    public function testOnePersonViewShowsOnlyTheirDutiesAndTheirSummary(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $data = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/assignments?userStableId={$s['aliceUserId']}", token: $s['bob']);

        self::assertResponseIsSuccessful();
        self::assertCount(3, $data['assignments']);
        foreach ($data['assignments'] as $assignment) {
            self::assertSame($s['aliceUserId'], $assignment['user']['stableId'], 'only Alice\'s duties, never Bob\'s');
        }
        $summary = $data['summary'];
        self::assertSame($s['aliceUserId'], $summary['userStableId']);
        self::assertSame(3, $summary['totalDuties']);
        self::assertSame(3.5, $summary['weightedWorkload'], '1.5 (night) + 1 + 1');
        self::assertSame(1, $summary['fridays']);
        self::assertSame(1, $summary['saturdays']);
        self::assertSame(0, $summary['sundays']);
        self::assertSame(1, $summary['weekendDays']);
        $byType = [];
        foreach ($summary['byDutyType'] as $row) {
            $byType[$row['code']] = $row['count'];
        }
        self::assertSame(['NIGHT' => 1, 'DAY' => 2], $byType, 'a night duty type shows up in the breakdown — there is no separate "nights" metric');
    }

    public function testMonthNavigationFiltersTheListButNotTheSummary(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $february = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/assignments?userStableId={$s['aliceUserId']}&from=2027-02-01&to=2027-03-01", token: $s['alice']);
        $march = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/assignments?userStableId={$s['aliceUserId']}&from=2027-03-01&to=2027-04-01", token: $s['alice']);

        self::assertCount(2, $february['assignments']);
        self::assertCount(1, $march['assignments']);
        self::assertSame(3, $february['summary']['totalDuties'], 'the summary always covers the whole displayed planning');
        self::assertSame($february['summary'], $march['summary']);
    }

    public function testAPersonWithoutDutiesHasAnEmptyViewAndAZeroSummary(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->api($client, 'POST', '/api/register', [
            'email' => 'carol@example.com', 'plainPassword' => 'correct-horse-battery', 'firstName' => 'Carol', 'lastName' => 'Idle', 'phone' => '+32 470 12 34 56',
        ]);
        // Carol joins the planning but is never assigned anything.
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($s['planningId']);
        $team = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningTeam();
        $carol = $container->get(UserRepository::class)->findOneByEmail('carol@example.com');
        $container->get(\App\Service\PlanningTeamMembershipService::class)->addMember($team, $carol, \App\Entity\TeamMemberRole::MEMBER, $this->date('2027-01-01'));

        $data = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/assignments?userStableId={$carol->getStableId()}", token: $s['creator']);

        self::assertResponseIsSuccessful();
        self::assertSame([], $data['assignments']);
        self::assertSame(0, $data['summary']['totalDuties']);
        self::assertSame([], $data['summary']['byDutyType']);
    }

    public function testOnlyThePlanningsPeopleCanBeSelectedAndOnlyViewersMayRead(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/assignments", token: $s['outsider']);
        self::assertResponseStatusCodeSame(403);

        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/assignments?userStableId={$s['outsiderUserId']}", token: $s['creator']);
        self::assertResponseStatusCodeSame(404, 'somebody outside this planning is not a selectable person');

        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/assignments?userStableId=nope", token: $s['creator']);
        self::assertResponseStatusCodeSame(404);

        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/assignments");
        self::assertResponseStatusCodeSame(401);
    }

    public function testDateParametersAreValidated(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/assignments?from=yesterday", token: $s['creator']);
        self::assertResponseStatusCodeSame(422);

        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/assignments?from=2027-03-01&to=2027-02-01", token: $s['creator']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAGenerationThatIsNotCompletedIsNotDisplayed(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, completed: false);

        $data = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/assignments", token: $s['creator']);

        self::assertResponseIsSuccessful();
        self::assertSame([], $data['generations']);
        self::assertSame([], $data['assignments'], 'assignments of a draft/snapshotted generation are work in progress, not the planning');
    }
}
