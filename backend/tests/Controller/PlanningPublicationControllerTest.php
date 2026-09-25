<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\PlanningPeriodStatus;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\PlanningSnapshotRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Service\DutyAssignmentService;
use App\Tests\PlanningPilotTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * "Publier le planning" (docs/decisions.md D133): the preflight reads the
 * *current* calendar (never the solver's original historical result), the
 * publish action always re-validates for real server-side, PUBLISHED stays
 * fully editable, and the underlying transition always goes through
 * PlanningPeriodLifecycleService — never a direct status write.
 */
final class PlanningPublicationControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use PlanningPilotTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function preflight(KernelBrowser $client, array $s, ?string $token = null): array
    {
        return $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/publication-preflight", token: $token ?? $s['creator']);
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function publish(KernelBrowser $client, array $s, ?string $token = null): array
    {
        return $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $token ?? $s['creator']);
    }

    private function generateComplete(KernelBrowser $client, array $s): void
    {
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        self::assertResponseIsSuccessful();
    }

    // --- preflight -----------------------------------------------------------------

    public function testACompleteCoherentPlanningIsPublishable(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->generateComplete($client, $s);

        $preflight = $this->preflight($client, $s);

        self::assertTrue($preflight['publishable']);
        self::assertSame([], $preflight['uncoveredDuties']);
        self::assertSame('GENERATED', $preflight['lines'][0]['periodStatus']);
        self::assertTrue($preflight['lines'][0]['hasGeneration']);
    }

    public function testAnUncoveredRequiredDutyBlocksPublicationAndIsListed(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07']]);
        foreach (['admin', 'alice', 'bob'] as $who) {
            $this->declareRange($client, $s[$who], '2027-01-05', '2027-01-06');
        }
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $preflight = $this->preflight($client, $s);

        self::assertFalse($preflight['publishable']);
        self::assertCount(1, $preflight['uncoveredDuties']);
        self::assertSame('2027-01-05', $preflight['uncoveredDuties'][0]['date']);
    }

    public function testAPlanningWithNoGenerationAtAllIsNotPublishable(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $preflight = $this->preflight($client, $s);

        self::assertFalse($preflight['publishable']);
        self::assertFalse($preflight['lines'][0]['hasGeneration']);
    }

    public function testAnInconsistentBlockIsDetectedIndependently(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        [$group, $duty0, $duty1] = $this->prepareBlockGeneration($s['planningId'], '2027-01-09', '2027-01-09', '2027-01-10', '2027-01-10', '2027-01-11');
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        self::assertResponseIsSuccessful();

        // Corrupt the coherent block directly (bypassing Sub-lot A's own atomicity guard —
        // the preflight must catch this as an independent defense, §4 of the spec).
        $this->corruptOneConstituentOfABlock($s, $duty1);

        $preflight = $this->preflight($client, $s);

        self::assertFalse($preflight['publishable']);
        self::assertCount(1, $preflight['inconsistentGroups']);
        self::assertSame((string) $group->getStableId(), $preflight['inconsistentGroups'][0]['groupInstanceStableId']);
    }

    public function testAMemberWhoBecameUnavailableAfterGenerationBlocksPublicationAsAnInvalidAssignment(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->generateComplete($client, $s);

        $result = $this->readResultOf($client, $s);
        $assignedEmail = $this->emailOfAssignee($client, $s, $result);
        // Declared unavailable *after* the generation — the current assignment is now invalid.
        $this->declareRange($client, $s[$assignedEmail], '2027-01-05', '2027-01-06');

        $preflight = $this->preflight($client, $s);

        self::assertFalse($preflight['publishable']);
        self::assertCount(1, $preflight['invalidAssignments']);
        self::assertSame('indisponible', $preflight['invalidAssignments'][0]['reason']);
    }

    public function testAnOverlapConflictIsDetectedIndependently(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-07'], ['2027-01-06', '2027-01-08']]);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        self::assertResponseIsSuccessful();

        // Force both overlapping duties onto the SAME member directly — the solver and
        // DutyReassignmentService both actively prevent this; the preflight must catch it anyway.
        $this->forceSameMemberOnBothOverlappingDuties($s['planningId']);

        $preflight = $this->preflight($client, $s);

        self::assertFalse($preflight['publishable']);
        self::assertNotEmpty($preflight['conflicts']);
        self::assertSame('déjà affecté à une garde incompatible', $preflight['conflicts'][0]['reason']);
    }

    // --- publish ---------------------------------------------------------------------

    public function testPublishingATransitionsToPublishedViaTheLifecycleService(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->generateComplete($client, $s);

        $result = $this->publish($client, $s);
        self::assertResponseIsSuccessful();

        self::assertSame('PUBLISHED', $result['lines'][0]['periodStatus']);
        self::assertFalse($result['lines'][0]['alreadyPublished']);

        $status = $this->periodStatusOf($s['planningId']);
        self::assertSame(PlanningPeriodStatus::PUBLISHED, $status);
    }

    public function testANotPublishablePlanningRefusesTheRealPostEvenIfAStaleGetSaidOtherwise(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        // Nobody available: real INCOMPLETE.
        foreach (['admin', 'alice', 'bob'] as $who) {
            $this->declareRange($client, $s[$who], '2027-01-05', '2027-01-06');
        }
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $response = $this->publish($client, $s);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_publishable', $response['error']);
        self::assertFalse($response['preflight']['publishable']);
        self::assertNotEmpty($response['preflight']['uncoveredDuties']);

        self::assertSame(PlanningPeriodStatus::GENERATED, $this->periodStatusOf($s['planningId']));
    }

    public function testDoublePublicationIsRefusedIdempotently(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->generateComplete($client, $s);
        $this->publish($client, $s);
        self::assertResponseIsSuccessful();

        $second = $this->publish($client, $s);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_published', $second['error']);
    }

    public function testOnlyAManagerCanPreflightOrPublish(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->generateComplete($client, $s);

        $this->preflight($client, $s, $s['alice']);
        self::assertResponseStatusCodeSame(403);
        $this->publish($client, $s, $s['alice']);
        self::assertResponseStatusCodeSame(403);

        $this->preflight($client, $s, $s['admin']);
        self::assertResponseIsSuccessful();
    }

    // --- PUBLISHED stays editable --------------------------------------------------

    public function testAPublishedPlanningStaysFullyReassignableAndKeepsItsStatus(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->generateComplete($client, $s);
        $this->publish($client, $s);
        self::assertResponseIsSuccessful();

        $result = $this->readResultOf($client, $s);
        $dutyStableId = $result['lines'][0]['duties'][0]['dutyStableId'];
        $originalMemberId = $result['lines'][0]['duties'][0]['assignment']['user']['stableId'];

        $view = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/duties/{$dutyStableId}/reassignment-candidates", token: $s['creator']);
        $newMemberId = null;
        foreach ($view['candidates'] as $candidate) {
            if ($candidate['selectable'] && $candidate['teamMemberStableId'] !== $view['currentTeamMemberStableId']) {
                $newMemberId = $candidate['teamMemberStableId'];
                break;
            }
        }
        self::assertNotNull($newMemberId, 'A published planning must still offer real reassignment candidates.');

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$dutyStableId}/reassign", [
            'teamMemberStableId' => $newMemberId,
            'expectedCurrentTeamMemberStableId' => $view['currentTeamMemberStableId'],
        ], $s['creator']);
        self::assertResponseIsSuccessful();

        self::assertSame(PlanningPeriodStatus::PUBLISHED, $this->periodStatusOf($s['planningId']), 'Reassigning after publication must never change the lifecycle status.');

        $stats = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/statistics", token: $s['creator']);
        self::assertResponseIsSuccessful();
        $total = array_sum(array_column($stats['currentPeriod']['groups'][0]['members'], 'total'));
        self::assertSame(1, $total, 'Statistics still reflect the current (post-publication) state.');
    }

    // --- helpers specific to this file ------------------------------------------------

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function readResultOf(KernelBrowser $client, array $s): array
    {
        return $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/result", token: $s['creator']);
    }

    /**
     * @param array<string, mixed> $s
     * @param array<string, mixed> $result
     */
    private function emailOfAssignee(KernelBrowser $client, array $s, array $result): string
    {
        $userStableId = $result['lines'][0]['duties'][0]['assignment']['user']['stableId'];
        foreach (['admin', 'alice', 'bob'] as $who) {
            if ((string) $this->userOf("{$who}@example.com")->getStableId() === $userStableId) {
                return $who;
            }
        }

        self::fail('Assignee is not one of the pilot members.');
    }

    private function periodStatusOf(string $planningStableId): PlanningPeriodStatus
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0];

        return $line->getPlanningPeriod()->getStatus();
    }

    /**
     * Directly supersedes duty1's current assignment and gives it to a *different*
     * team member than duty0's — a real, low-level way to produce the inconsistent
     * block state Sub-lot A's own atomicity is designed to prevent.
     *
     * @param array<string, mixed> $s
     */
    private function corruptOneConstituentOfABlock(array $s, Duty $duty1): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($s['planningId']);
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
        $generation = $container->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($line->getPlanningPeriod());
        $snapshot = $container->get(PlanningSnapshotRepository::class)->findOneByGeneration($generation);
        $freshDuty1 = $container->get(DutyRepository::class)->findOneByStableId((string) $duty1->getStableId());

        $current = $container->get(DutyAssignmentRepository::class)->findCurrentByGenerationAndDuty($generation, $freshDuty1);
        $currentMember = $current->getTeamMember();
        $otherMember = null;
        foreach ($container->get(PlanningTeamMemberRepository::class)->findByTeam($line->getPlanningTeam()) as $candidate) {
            if ($candidate !== $currentMember) {
                $otherMember = $candidate;
                break;
            }
        }
        self::assertNotNull($otherMember);

        // Two flushes, in this order, exactly like DutyReassignmentService (D131): the partial
        // unique index on `current` is checked immediately per statement, never deferred.
        $current->markSuperseded();
        $em->flush();
        $container->get(DutyAssignmentService::class)->createManualBatchItem($generation, $snapshot, $freshDuty1, $otherMember);
        $em->flush();
    }

    /**
     * Directly forces the same member onto both duties of an overlapping pair —
     * bypassing both the solver and DutyReassignmentService's own live checks, which
     * both actively prevent this in normal operation.
     */
    private function forceSameMemberOnBothOverlappingDuties(string $planningStableId): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
        $generation = $container->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($line->getPlanningPeriod());
        $snapshot = $container->get(PlanningSnapshotRepository::class)->findOneByGeneration($generation);
        $duties = $container->get(DutyRepository::class)->findByPlanningPeriod($line->getPlanningPeriod());
        self::assertCount(2, $duties);

        /** @var DutyAssignment $first */
        $first = $container->get(DutyAssignmentRepository::class)->findCurrentByGenerationAndDuty($generation, $duties[0]);
        $member = $first->getTeamMember();

        $secondCurrent = $container->get(DutyAssignmentRepository::class)->findCurrentByGenerationAndDuty($generation, $duties[1]);
        $secondCurrent->markSuperseded();
        $em->flush();
        $container->get(DutyAssignmentService::class)->createManualBatchItem($generation, $snapshot, $duties[1], $member);
        $em->flush();
    }
}
