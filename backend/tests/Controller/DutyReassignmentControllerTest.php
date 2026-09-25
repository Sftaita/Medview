<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DutyAssignmentEvent;
use App\Entity\DutyAssignmentSource;
use App\Repository\DutyAssignmentEventRepository;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\UserAvailabilityPeriodRepository;
use App\Tests\PlanningPilotTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The dynamic calendar's write surface (docs/decisions.md D131): live
 * candidates (never the frozen snapshot), atomic block reassignment,
 * explicit save with real server-side revalidation, optimistic
 * concurrency, and an append-only history. A real OR-Tools solve runs
 * first, as in PlanningResultControllerTest — the pilot launcher always
 * seeds RestPolicyOptions::none() (D129), so LEGAL_MIN_REST/TEAM_MIN_REST
 * live-blocking is covered separately at the service level
 * (ReassignmentCandidateServiceTest), not here.
 */
final class DutyReassignmentControllerTest extends WebTestCase
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
    private function candidates(KernelBrowser $client, array $s, string $dutyStableId, ?string $token = null): array
    {
        return $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/duties/{$dutyStableId}/reassignment-candidates", token: $token ?? $s['creator']);
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function reassign(KernelBrowser $client, array $s, string $dutyStableId, string $teamMemberStableId, ?string $expectedCurrent, ?string $token = null): array
    {
        return $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$dutyStableId}/reassign", [
            'teamMemberStableId' => $teamMemberStableId,
            'expectedCurrentTeamMemberStableId' => $expectedCurrent,
        ], $token ?? $s['creator']);
    }

    /**
     * @param array<string, mixed> $candidateList
     *
     * @return array<string, mixed>
     */
    private function candidateOf(array $candidateList, string $teamMemberStableId): array
    {
        foreach ($candidateList as $candidate) {
            if ($candidate['teamMemberStableId'] === $teamMemberStableId) {
                return $candidate;
            }
        }

        self::fail('No candidate with that teamMemberStableId.');
    }

    // --- live candidates ------------------------------------------------------------

    public function testAvailableCandidateIsSelectableAndTheCurrentAssigneeIsIdentified(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        // admin and alice are unavailable: bob is the only real candidate, so he gets it.
        foreach (['admin', 'alice'] as $who) {
            $this->declareRange($client, $s[$who], '2027-01-05', '2027-01-06');
        }
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $dutyStableId = $this->onlyDutyStableIdOf($s['planningId']);
        $bobMemberId = $this->memberIdOf($client, $s, 'bob@example.com');
        $adminMemberId = $this->memberIdOf($client, $s, 'admin@example.com');

        $view = $this->candidates($client, $s, $dutyStableId);
        self::assertResponseIsSuccessful();
        self::assertSame($bobMemberId, $view['currentTeamMemberStableId']);

        $bob = $this->candidateOf($view['candidates'], $bobMemberId);
        self::assertTrue($bob['selectable']);
        self::assertTrue($bob['isCurrent']);
        self::assertSame([], $bob['blockingReasons']);

        $admin = $this->candidateOf($view['candidates'], $adminMemberId);
        self::assertFalse($admin['selectable']);
        self::assertFalse($admin['isCurrent']);
        self::assertSame(['indisponible'], $admin['blockingReasons']);
    }

    public function testConflictingCandidateIsDisabled(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        // Two overlapping standalone duties (05→07 and 06→08): whoever holds one can never take the other.
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-07'], ['2027-01-06', '2027-01-08']]);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $result = $this->readResult($client, $s['planningId'], $s['creator']);
        $duties = $result['lines'][0]['duties'];
        $duty05 = '2027-01-05' === $duties[0]['date'] ? $duties[0] : $duties[1];
        $duty06 = '2027-01-06' === $duties[0]['date'] ? $duties[0] : $duties[1];
        $firstAssigneeStableId = $duty05['assignment']['user']['stableId'] ?? null;
        self::assertNotNull($firstAssigneeStableId, 'Both duties are covered (3 candidates, CONFLICT just forces two different ones).');

        $view = $this->candidates($client, $s, $duty06['dutyStableId']);
        self::assertResponseIsSuccessful();

        $conflicting = $this->candidateOf($view['candidates'], $this->memberStableIdOfUser($client, $s, $firstAssigneeStableId));
        self::assertFalse($conflicting['isCurrent'], 'This candidate holds the OTHER duty of the pair, not this one.');
        self::assertFalse($conflicting['selectable']);
        self::assertSame(['déjà affecté à une garde incompatible'], $conflicting['blockingReasons']);
    }

    // --- block atomicity --------------------------------------------------------------

    public function testBlockIsDetectedAndReassignedAtomically(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        [$group, $duty0, $duty1] = $this->prepareBlockGeneration($s['planningId'], '2027-01-09', '2027-01-09', '2027-01-10', '2027-01-10', '2027-01-11');
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $view = $this->candidates($client, $s, (string) $duty0->getStableId());
        self::assertResponseIsSuccessful();
        self::assertSame((string) $group->getStableId(), $view['groupInstanceStableId']);
        self::assertCount(2, $view['blockDuties']);

        $bobMemberId = $this->memberIdOf($client, $s, 'bob@example.com');
        $currentTeamMemberId = $view['currentTeamMemberStableId'];
        self::assertNotSame($bobMemberId, $currentTeamMemberId, 'Precondition: bob is not already the one holding this block.');

        $response = $this->reassign($client, $s, (string) $duty0->getStableId(), $bobMemberId, $currentTeamMemberId);
        self::assertResponseIsSuccessful();
        self::assertSame('reassigned', $response['status']);

        $current = static::getContainer()->get(DutyAssignmentRepository::class);
        $generation = static::getContainer()->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($duty0->getPlanningPeriod());
        $freshDuty0 = static::getContainer()->get(DutyRepository::class)->findOneByStableId((string) $duty0->getStableId());
        $freshDuty1 = static::getContainer()->get(DutyRepository::class)->findOneByStableId((string) $duty1->getStableId());

        $a0 = $current->findCurrentByGenerationAndDuty($generation, $freshDuty0);
        $a1 = $current->findCurrentByGenerationAndDuty($generation, $freshDuty1);
        self::assertSame($bobMemberId, (string) $a0->getTeamMember()->getStableId(), 'Both constituent duties moved together.');
        self::assertSame($bobMemberId, (string) $a1->getTeamMember()->getStableId(), 'Both constituent duties moved together.');
        self::assertSame(DutyAssignmentSource::MANUAL, $a0->getSource());
        self::assertSame(DutyAssignmentSource::MANUAL, $a1->getSource());
    }

    // --- explicit save, source, concurrency --------------------------------------------

    public function testReassignmentPersistsAndTurnsAutoIntoManual(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $dutyStableId = $this->onlyDutyStableIdOf($s['planningId']);
        $view = $this->candidates($client, $s, $dutyStableId);
        $previousMemberId = $view['currentTeamMemberStableId'];
        $newCandidate = $this->firstOtherSelectable($view['candidates'], $previousMemberId);

        $response = $this->reassign($client, $s, $dutyStableId, $newCandidate, $previousMemberId);
        self::assertResponseIsSuccessful();
        self::assertSame('reassigned', $response['status']);

        $result = $this->readResult($client, $s['planningId'], $s['creator']);
        $duty = $result['lines'][0]['duties'][0];
        self::assertSame($this->userStableIdOfMember($newCandidate), $duty['assignment']['user']['stableId']);
        self::assertSame('MANUAL', $duty['assignment']['source']);
    }

    /**
     * Regression test: a duty with *no* current assignment at all (a real
     * NON COUVERTE duty, the "Attribuer" case) crashed the whole endpoint —
     * caught by the mandatory manual browser smoke test, not by the
     * automated suite until this test was added.
     */
    public function testAssigningAPreviouslyUncoveredDutySucceeds(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        // Everyone unavailable at generation time: a real, genuinely uncovered duty.
        foreach (['admin', 'alice', 'bob'] as $who) {
            $this->declareRange($client, $s[$who], '2027-01-05', '2027-01-06');
        }
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        self::assertResponseIsSuccessful();

        $dutyStableId = $this->onlyDutyStableIdOf($s['planningId']);
        $view = $this->candidates($client, $s, $dutyStableId);
        self::assertNull($view['currentTeamMemberStableId'], 'Precondition: the duty starts genuinely uncovered.');

        // bob becomes available again (like the live browser smoke test: remove the
        // unavailability, live eligibility re-reads it fresh) — a real, valid candidate now.
        $this->api($client, 'DELETE', '/api/me/calendar/'.$this->onlyAvailabilityStableIdOf('bob'), token: $s['bob']);
        self::assertResponseStatusCodeSame(204);

        $bobMemberId = $this->memberIdOf($client, $s, 'bob@example.com');
        $response = $this->reassign($client, $s, $dutyStableId, $bobMemberId, null);
        self::assertResponseIsSuccessful();
        self::assertSame('reassigned', $response['status']);

        $result = $this->readResult($client, $s['planningId'], $s['creator']);
        $duty = $result['lines'][0]['duties'][0];
        self::assertTrue($duty['covered']);
        self::assertSame($this->userStableIdOfMember($bobMemberId), $duty['assignment']['user']['stableId']);
        self::assertSame('MANUAL', $duty['assignment']['source']);
    }

    /** @return string the stableId of the one UserAvailabilityPeriod declared for $who in this test class's scenarios */
    private function onlyAvailabilityStableIdOf(string $who): string
    {
        $periods = static::getContainer()->get(UserAvailabilityPeriodRepository::class)->findByUser($this->userOf("{$who}@example.com"));

        return (string) $periods[0]->getStableId();
    }

    public function testStaleReassignmentIsRejectedWithConflict(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $dutyStableId = $this->onlyDutyStableIdOf($s['planningId']);
        $view = $this->candidates($client, $s, $dutyStableId);
        $original = $view['currentTeamMemberStableId'];
        $candidateA = $this->firstOtherSelectable($view['candidates'], $original);

        // Manager B reassigns first, using the real (fresh) current state.
        $this->reassign($client, $s, $dutyStableId, $candidateA, $original);
        self::assertResponseIsSuccessful();

        // Manager A still believes the ORIGINAL assignee is current — a real second gestionnaire scenario.
        $viewAfter = $this->candidates($client, $s, $dutyStableId);
        $candidateB = $this->firstOtherSelectable($viewAfter['candidates'], $candidateA);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$dutyStableId}/reassign", [
            'teamMemberStableId' => $candidateB,
            'expectedCurrentTeamMemberStableId' => $original,
        ], $s['creator']);
        self::assertResponseStatusCodeSame(409, 'A stale expectedCurrentTeamMemberStableId must never silently overwrite the real current state.');

        // The calendar still reflects candidateA's successful save, never candidateB's rejected one.
        $result = $this->readResult($client, $s['planningId'], $s['creator']);
        self::assertSame($this->userStableIdOfMember($candidateA), $result['lines'][0]['duties'][0]['assignment']['user']['stableId']);
    }

    public function testAnInvalidCandidateIsRejectedAtSaveTimeEvenIfTheModalOnceShowedItSelectable(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $dutyStableId = $this->onlyDutyStableIdOf($s['planningId']);
        $view = $this->candidates($client, $s, $dutyStableId);
        $original = $view['currentTeamMemberStableId'];
        $aliceMemberId = $this->memberIdOf($client, $s, 'alice@example.com');
        self::assertTrue($this->candidateOf($view['candidates'], $aliceMemberId)['selectable'], 'Precondition: alice starts selectable.');

        // alice becomes unavailable after the modal opened, before "Enregistrer" is clicked — the
        // concurrency identity ($original) is unaffected, only her own eligibility changed.
        $this->declareRange($client, $s['alice'], '2027-01-05', '2027-01-06');

        $response = $this->reassign($client, $s, $dutyStableId, $aliceMemberId, $original);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('invalid_candidate', $response['error']);
    }

    // --- history ------------------------------------------------------------------------

    public function testHistoryRecordsAuthorPreviousAndNewAssignee(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);

        $dutyStableId = $this->onlyDutyStableIdOf($s['planningId']);
        $view = $this->candidates($client, $s, $dutyStableId);
        $original = $view['currentTeamMemberStableId'];
        $newCandidate = $this->firstOtherSelectable($view['candidates'], $original);

        $this->reassign($client, $s, $dutyStableId, $newCandidate, $original);
        self::assertResponseIsSuccessful();

        $duty = static::getContainer()->get(DutyRepository::class)->findOneByStableId($dutyStableId);
        $events = static::getContainer()->get(DutyAssignmentEventRepository::class)->findByDuty($duty);
        self::assertCount(1, $events);
        /** @var DutyAssignmentEvent $event */
        $event = $events[0];
        self::assertSame((string) $this->userOf('creator@example.com')->getStableId(), (string) $event->getAuthor()->getStableId());
        self::assertSame($original, (string) $event->getPreviousAssignment()?->getTeamMember()->getStableId());
        self::assertSame($newCandidate, (string) $event->getNewAssignment()->getTeamMember()->getStableId());
        self::assertFalse($event->wasPublished(), 'The period was never published in this test.');
    }

    // --- authorization --------------------------------------------------------------------

    public function testOnlyTheCreatorOrOwnerAdminCanViewCandidatesOrReassign(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $s['creator']);
        $dutyStableId = $this->onlyDutyStableIdOf($s['planningId']);

        // A plain member (alice) cannot view candidates nor reassign.
        $this->candidates($client, $s, $dutyStableId, $s['alice']);
        self::assertResponseStatusCodeSame(403);
        $this->reassign($client, $s, $dutyStableId, $this->memberIdOf($client, $s, 'bob@example.com'), null, $s['alice']);
        self::assertResponseStatusCodeSame(403);

        // An outsider gets the same refusal.
        $this->candidates($client, $s, $dutyStableId, $s['outsider']);
        self::assertResponseStatusCodeSame(403);

        // The team ADMIN can, exactly like the creator.
        $this->candidates($client, $s, $dutyStableId, $s['admin']);
        self::assertResponseIsSuccessful();
    }

    // --- helpers specific to this file ------------------------------------------------

    private function readResult(KernelBrowser $client, string $planningStableId, string $token): array
    {
        return $this->api($client, 'GET', "/api/plannings/{$planningStableId}/result", token: $token);
    }

    private function onlyDutyStableIdOf(string $planningStableId): string
    {
        $ids = $this->allDutyStableIdsOf($planningStableId);
        self::assertCount(1, $ids);

        return $ids[0];
    }

    /**
     * @return list<string>
     */
    private function allDutyStableIdsOf(string $planningStableId): array
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
        $duties = $container->get(DutyRepository::class)->findByPlanningPeriod($line->getPlanningPeriod());

        return array_map(static fn ($d) => (string) $d->getStableId(), $duties);
    }

    private function userStableIdOfMember(string $teamMemberStableId): string
    {
        $member = static::getContainer()->get(PlanningTeamMemberRepository::class)->findOneByStableId($teamMemberStableId);

        return (string) $member->getUser()->getStableId();
    }

    private function memberStableIdOfUser(KernelBrowser $client, array $s, string $userStableId): string
    {
        foreach (['admin', 'alice', 'bob'] as $who) {
            $email = "{$who}@example.com";
            if ((string) $this->userOf($email)->getStableId() === $userStableId) {
                return $this->memberIdOf($client, $s, $email);
            }
        }

        self::fail('No pilot member with that userStableId.');
    }

    /**
     * @param array<int, array<string, mixed>> $candidateList
     */
    private function firstOtherSelectable(array $candidateList, ?string $excludingTeamMemberStableId): string
    {
        foreach ($candidateList as $candidate) {
            if ($candidate['selectable'] && $candidate['teamMemberStableId'] !== $excludingTeamMemberStableId) {
                return $candidate['teamMemberStableId'];
            }
        }

        self::fail('No other selectable candidate found.');
    }
}
