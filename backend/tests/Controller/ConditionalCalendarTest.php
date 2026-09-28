<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Fairness\FairnessDimensionKey;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\ConditionalLineTestHelpers;
use App\Tests\FaultInjectingPlanningSolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The live calendar of a conditional line after generation
 * (docs/decisions.md D165), end to end: the rules stay those frozen by the
 * line's current generation, the source holders come from
 * DutyAssignment.current — a change on the source line is reported
 * (dependentImpacts), never acted on; "Compléter automatiquement" covers
 * the live demand; the history never moves.
 *
 * Same scenario as ConditionalGenerationTest (ConditionalLineTestHelpers):
 * Dr A = admin (no trigger), Dr B = alice (every day), Dr C = bob (Friday to
 * Sunday); Renfort: Tuesday alone + a Friday–Sunday block, carol and dave.
 */
final class ConditionalCalendarTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;
    use ConditionalLineTestHelpers;

    private const TUE = '2027-01-05';
    private const FRI = '2027-01-08';
    private const SAT = '2027-01-09';
    private const SUN = '2027-01-10';
    private const DR_A_EVERYWHERE = [self::TUE => 'admin@example.com', self::FRI => 'admin@example.com', self::SAT => 'admin@example.com', self::SUN => 'admin@example.com'];
    private const DR_B_EVERYWHERE = [self::TUE => 'alice@example.com', self::FRI => 'alice@example.com', self::SAT => 'alice@example.com', self::SUN => 'alice@example.com'];

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        FaultInjectingPlanningSolver::reset();
    }

    protected function tearDown(): void
    {
        FaultInjectingPlanningSolver::reset();
        parent::tearDown();
    }

    /**
     * Generates with the given main-line holders, then frees everybody so the calendar can be edited.
     *
     * @param array<string, string> $holders
     *
     * @return array<string, mixed>
     */
    private function generatedScenario(KernelBrowser $client, array $holders, ...$scenarioArgs): array
    {
        $s = $this->scenario($client, ...$scenarioArgs);
        $declared = $this->forceHolders($client, $s, $holders);
        $job = $this->launch($client, $s);
        self::assertSame('SUCCEEDED', $job['status']);
        $this->liftUnavailabilities($client, $declared);

        return $s;
    }

    /** Replaces the main line's holder on $date. */
    private function replaceSource(KernelBrowser $client, array $s, string $date, string $email): array
    {
        $response = $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], $date), $email);
        self::assertResponseIsSuccessful();

        return $response;
    }

    /**
     * Every row ever written for one Renfort duty — current or superseded — and its events.
     *
     * @return array{assignments: list<array<string, mixed>>, events: list<array<string, mixed>>}
     */
    private function rowsOf(array $s, string $date): array
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $dutyId = $this->renfortDuties($s)[$date]->getId();

        return [
            'assignments' => $connection->fetchAllAssociative('SELECT id, current, source FROM duty_assignments WHERE duty_id = :d ORDER BY id', ['d' => $dutyId]),
            'events' => $connection->fetchAllAssociative('SELECT previous_assignment_id, new_assignment_id FROM duty_assignment_events WHERE duty_id = :d ORDER BY id', ['d' => $dutyId]),
        ];
    }

    private function renfortAssignmentRowCount(array $s): int
    {
        $ids = array_map(static fn ($duty): int => (int) $duty->getId(), $this->renfortDuties($s));

        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM duty_assignments WHERE duty_id IN (:ids)',
            ['ids' => array_values($ids)],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
    }

    // --- a source change creates a need: reported, never acted on -------------------------------------

    public function testATriggeringSourceReplacementCreatesANeedAndWritesNothing(): void
    {
        $client = static::createClient();
        $s = $this->generatedScenario($client, self::DR_A_EVERYWHERE);
        $history = $this->history($s);
        self::assertSame(0, $this->renfortAssignmentRowCount($s), 'Dr A everywhere: a zero-unit reinforcement line.');

        // Dr A → Dr B on the main line's Tuesday.
        $response = $this->replaceSource($client, $s, self::TUE, 'alice@example.com');

        self::assertSame('reassigned', $response['status']);
        self::assertCount(1, $response['dependentImpacts'], 'Only the Tuesday reinforcement depends on the main line\'s Tuesday.');
        $impact = $response['dependentImpacts'][0];
        self::assertSame($s['renfortLineId'], $impact['lineStableId']);
        self::assertSame([self::TUE], $impact['dates']);
        self::assertSame('NOT_REQUIRED_UNASSIGNED', $impact['previousState']);
        self::assertSame('REQUIRED_UNASSIGNED', $impact['newState']);
        self::assertTrue($impact['changed']);
        self::assertTrue($impact['required']);
        self::assertFalse($impact['assigned']);
        self::assertNull($impact['assignee']);
        self::assertSame('TRIGGERED', $impact['reason']);
        self::assertSame([self::TUE], $impact['triggeringDates']);

        // Nobody was picked: no DutyAssignment, no event on the reinforcement line.
        self::assertSame(0, $this->renfortAssignmentRowCount($s));
        self::assertSame([], array_filter($this->renfortCalendar($s)));

        // The calendar result says it all, without any frontend computation.
        $result = $this->renfortResult($client, $s);
        $tuesday = $result[self::TUE]['demand'];
        self::assertSame('REQUIRED_UNASSIGNED', $tuesday['state']);
        self::assertTrue($tuesday['required']);
        self::assertFalse($tuesday['superfluous']);
        self::assertSame('TRIGGERED', $tuesday['dayReason']);
        self::assertSame('TUESDAY', $tuesday['weekday']);
        self::assertSame($this->dutyOn($s['planningId'], self::TUE), $tuesday['sourceDutyStableId']);
        self::assertSame($this->userId('alice@example.com'), $tuesday['sourceHolder']['userStableId']);
        self::assertSame(self::ALL_DAYS, $tuesday['trigger']['weekdays']);
        self::assertTrue($result[self::TUE]['required']);
        self::assertFalse($result[self::TUE]['covered']);
        self::assertSame('NOT_REQUIRED_UNASSIGNED', $result[self::FRI]['demand']['state']);
        self::assertSame('HOLDER_HAS_NO_TRIGGER', $result[self::FRI]['demand']['dayReason']);

        // The history of the generation never moves.
        self::assertSame($history, $this->history($s));
    }

    public function testCompletionCoversTheReinforcementTheLiveDemandNowRequires(): void
    {
        $client = static::createClient();
        $s = $this->generatedScenario($client, self::DR_A_EVERYWHERE);
        $history = $this->history($s);
        $this->replaceSource($client, $s, self::TUE, 'alice@example.com');

        $outcome = $this->completePlanning($client, $s);

        self::assertSame('SUCCEEDED', $outcome['job']['status']);
        self::assertSame('COMPLETE', $outcome['coverage']);
        $renfort = array_values(array_filter($outcome['lines'], fn (array $l): bool => $l['lineStableId'] === $s['renfortLineId']))[0];
        self::assertSame('completed', $renfort['status']);
        self::assertSame(1, $renfort['holeCount'], 'The live need — never the (empty) demand of the generation.');
        self::assertSame(1, $renfort['filledUnitCount']);
        self::assertSame(0, $renfort['undeterminedUnitCount']);

        $calendar = $this->renfortCalendar($s);
        self::assertContains($calendar[self::TUE], ['carol@example.com', 'dave@example.com']);
        self::assertNull($calendar[self::FRI], 'Never a reinforcement nobody needs.');
        self::assertSame('REQUIRED_ASSIGNED', $this->renfortStates($client, $s)[self::TUE]);

        // The completion wrote into the current generation: its frozen demand is untouched.
        self::assertSame($history, $this->history($s));
    }

    // --- a source change removes a need: the reinforcement stays, and can be removed explicitly ----------

    public function testAnUntriggeringSourceReplacementKeepsTheReinforcementAsSuperfluousUntilRemoved(): void
    {
        $client = static::createClient();
        $s = $this->generatedScenario($client, self::DR_B_EVERYWHERE);
        $history = $this->history($s);
        $holder = $this->renfortCalendar($s)[self::TUE];
        self::assertNotNull($holder);

        // Dr B → Dr A on the main line's Tuesday.
        $response = $this->replaceSource($client, $s, self::TUE, 'admin@example.com');

        $impact = $response['dependentImpacts'][0];
        self::assertSame('REQUIRED_ASSIGNED', $impact['previousState']);
        self::assertSame('NOT_REQUIRED_ASSIGNED', $impact['newState']);
        self::assertFalse($impact['required']);
        self::assertTrue($impact['assigned']);
        self::assertSame($this->userId($holder), $impact['assignee']['userStableId']);
        self::assertSame('HOLDER_HAS_NO_TRIGGER', $impact['reason']);

        // Kept on purpose: a real load, visible, warned about.
        self::assertSame($holder, $this->renfortCalendar($s)[self::TUE]);
        $result = $this->renfortResult($client, $s)[self::TUE];
        self::assertSame('NOT_REQUIRED_ASSIGNED', $result['demand']['state']);
        self::assertTrue($result['demand']['superfluous']);

        // Replacing its holder would be a NEW assignment on a reinforcement nobody needs.
        $other = 'carol@example.com' === $holder ? 'dave@example.com' : 'carol@example.com';
        $refused = $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], self::TUE, 1), $other, lineIndex: 1);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('coverage_not_required', $refused['error']);

        // Publication: a warning, never a blocker.
        $preflight = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/publication-preflight", token: $s['creator']);
        self::assertTrue($preflight['publishable']);
        self::assertSame([], $preflight['undeterminedDuties']);
        self::assertCount(1, $preflight['superfluousCoverages']);
        self::assertSame(self::TUE, $preflight['superfluousCoverages'][0]['duty']['date']);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();

        // "Retirer l'affectation": explicit, append-only, the live state follows at once.
        $removed = $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], self::TUE, 1));
        self::assertSame('unassigned', $removed['status']);
        self::assertSame([], $removed['dependentImpacts']);
        self::assertNull($this->renfortCalendar($s)[self::TUE]);
        self::assertSame('NOT_REQUIRED_UNASSIGNED', $this->renfortStates($client, $s)[self::TUE]);
        $rows = $this->rowsOf($s, self::TUE);
        self::assertCount(1, $rows['assignments'], 'The generated assignment is still there…');
        self::assertFalse($rows['assignments'][0]['current'], '…superseded, never deleted.');
        self::assertCount(1, $rows['events']);
        self::assertSame($rows['assignments'][0]['id'], $rows['events'][0]['previous_assignment_id']);
        self::assertNull($rows['events'][0]['new_assignment_id']);
        self::assertSame([], $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/publication-preflight", token: $s['creator'])['superfluousCoverages']);

        self::assertSame($history, $this->history($s));
    }

    public function testNobodyCanBeNewlyAssignedToAReinforcementNobodyNeeds(): void
    {
        $client = static::createClient();
        $s = $this->generatedScenario($client, self::DR_A_EVERYWHERE);
        $tuesday = $this->dutyOn($s['planningId'], self::TUE, 1);

        // The candidate list says why it is empty — never an ambiguous empty list.
        $view = $this->candidatesFor($client, $s, $tuesday);
        self::assertFalse($view['assignable']);
        self::assertSame('coverage_not_required', $view['notAssignableReason']);
        self::assertSame([], $view['candidates']);
        self::assertSame('NOT_REQUIRED_UNASSIGNED', $view['demand']['state']);
        self::assertFalse($view['demand']['required']);

        // Refused at write time, whatever the path.
        $refused = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$tuesday}/reassign", [
            'teamMemberStableId' => $this->memberStableIdIn($s['planningId'], 'carol@example.com', 1),
            'expectedCurrentTeamMemberStableId' => null,
        ], $s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('coverage_not_required', $refused['error']);

        // The low-level assignment endpoint (a team OWNER/ADMIN's) enforces the same rule.
        $renfortTeamId = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'][1]['team']['stableId'];
        $this->addMemberAs($client, $s['creator'], $s['planningId'], $renfortTeamId, 'admin@example.com', 'ADMIN');
        $raw = $this->api($client, 'POST', "/api/planning-generations/{$this->currentGeneration($s, 1)->getStableId()}/assignments", [
            'dutyStableId' => $tuesday,
            'teamMemberStableId' => $this->memberStableIdIn($s['planningId'], 'carol@example.com', 1),
        ], $s['admin']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('coverage_not_required', $raw['error']);

        self::assertSame(0, $this->renfortAssignmentRowCount($s));
    }

    // --- the people rules on a reinforcement that became required ------------------------------------------

    public function testTheCandidatesOfANewNeedFollowSelfCoverageCrossLineConflictAndRest(): void
    {
        $client = static::createClient();
        // Main line: Tuesday only. Renfort: Tuesday only (alice, carol, dave). A third independent line "Autre":
        // Monday and Tuesday (carol, dave).
        $s = $this->scenario($client, [[self::TUE, '2027-01-06']], ['blocks' => [], 'solo' => ['MAR'], 'soloFamily' => '', 'excluded' => ['LUN', 'MER', 'JEU', 'VEN', 'SAM', 'DIM']], ['alice@example.com', 'carol@example.com', 'dave@example.com']);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/lines", ['name' => 'Autre'], $s['creator']);
        $this->prepareLine($s['planningId'], [['2027-01-04', self::TUE], [self::TUE, '2027-01-06']], lineIndex: 2);
        $autreTeamId = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'][2]['team']['stableId'];
        $this->addMemberAs($client, $s['creator'], $s['planningId'], $autreTeamId, 'carol@example.com', 'MEMBER');
        $this->addMemberAs($client, $s['creator'], $s['planningId'], $autreTeamId, 'dave@example.com', 'MEMBER');
        $declared = $this->forceHolders($client, $s, [self::TUE => 'admin@example.com']);
        self::assertSame('SUCCEEDED', $this->launch($client, $s, ['teamMinRestEnabled' => true, 'teamMinRestHours' => 12])['status']);
        $this->liftUnavailabilities($client, $declared);

        // "Autre": dave on Monday (ends Tuesday 00:00), carol on Tuesday.
        foreach (['2027-01-04', self::TUE] as $date) {
            if (null !== $this->currentCalendar($s['planningId'])['2|'.$date.'|ONCALL']) {
                $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], $date, 2));
                self::assertResponseIsSuccessful();
            }
        }
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-04', 2), 'dave@example.com', lineIndex: 2);
        self::assertResponseIsSuccessful();
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], self::TUE, 2), 'carol@example.com', lineIndex: 2);
        self::assertResponseIsSuccessful();

        // Dr B takes the main line's Tuesday: the reinforcement is now required.
        $this->replaceSource($client, $s, self::TUE, 'alice@example.com');
        $tuesday = $this->dutyOn($s['planningId'], self::TUE, 1);

        $view = $this->candidatesFor($client, $s, $tuesday);
        self::assertTrue($view['assignable'], 'Required: candidates are looked for…');
        self::assertNull($view['notAssignableReason']);
        self::assertSame('REQUIRED_UNASSIGNED', $view['demand']['state']);
        self::assertSame([], $view['candidates'], '…and nobody passes the person rules.');

        foreach (['alice@example.com' => 'SELF_COVERAGE', 'carol@example.com' => 'CROSS_LINE_CONFLICT', 'dave@example.com' => 'CROSS_LINE_TEAM_MIN_REST'] as $email => $reason) {
            $refused = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$tuesday}/reassign", [
                'teamMemberStableId' => $this->memberStableIdIn($s['planningId'], $email, 1),
                'expectedCurrentTeamMemberStableId' => null,
            ], $s['creator']);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('invalid_candidate', $refused['error']);
            self::assertStringContainsString($reason, $refused['message'], $email);
            self::assertSame($reason, $refused['reason'], 'The reason, as a stable code (D167)…');
            self::assertNotEmpty($refused['reasonLabel'], '…and in plain words.');
        }
        self::assertSame('déjà de garde sur la ligne à renforcer', $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/duties/{$tuesday}/reassign", [
            'teamMemberStableId' => $this->memberStableIdIn($s['planningId'], 'alice@example.com', 1),
            'expectedCurrentTeamMemberStableId' => null,
        ], $s['creator'])['reasonLabel']);

        // "Compléter automatiquement" applies the same rules: the need stays uncovered.
        $outcome = $this->completePlanning($client, $s);
        self::assertNull($this->renfortCalendar($s)[self::TUE]);
        self::assertSame('INCOMPLETE', $outcome['coverage']);
    }

    // --- undetermined demand ------------------------------------------------------------------------------

    public function testAnUndeterminedReinforcementIsNeverCompletedNorPublished(): void
    {
        $client = static::createClient();
        $s = $this->generatedScenario($client, self::DR_A_EVERYWHERE);

        // The main line's Tuesday loses its holder: its reinforcement's demand cannot be evaluated any more.
        $removed = $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], self::TUE));
        self::assertSame('NOT_REQUIRED_UNASSIGNED', $removed['dependentImpacts'][0]['previousState']);
        self::assertSame('UNDETERMINED', $removed['dependentImpacts'][0]['newState']);
        self::assertNull($removed['dependentImpacts'][0]['required']);
        self::assertSame('SOURCE_UNASSIGNED', $removed['dependentImpacts'][0]['reason']);

        $view = $this->candidatesFor($client, $s, $this->dutyOn($s['planningId'], self::TUE, 1));
        self::assertFalse($view['assignable']);
        self::assertSame('coverage_undetermined', $view['notAssignableReason']);
        self::assertNull($view['demand']['required']);

        // Nobody can hold the main line's Tuesday: the completion can settle neither.
        foreach (['admin', 'alice', 'bob'] as $who) {
            $this->declareRange($client, $s[$who], self::TUE, '2027-01-06');
        }
        $outcome = $this->completePlanning($client, $s);

        self::assertSame('INCOMPLETE', $outcome['coverage']);
        $renfort = array_values(array_filter($outcome['lines'], fn (array $l): bool => $l['lineStableId'] === $s['renfortLineId']))[0];
        self::assertSame(1, $renfort['undeterminedUnitCount']);
        self::assertNull($this->renfortCalendar($s)[self::TUE], 'Never completed while undetermined.');
        self::assertSame('UNDETERMINED', $this->renfortStates($client, $s)[self::TUE]);

        $preflight = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/publication-preflight", token: $s['creator']);
        self::assertFalse($preflight['publishable']);
        self::assertFalse($preflight['republishable']);
        self::assertCount(1, $preflight['undeterminedDuties']);
        self::assertSame(self::TUE, $preflight['undeterminedDuties'][0]['duty']['date']);
        self::assertSame(1, array_values(array_filter($this->api($client, 'GET', "/api/plannings/{$s['planningId']}/result", token: $s['creator'])['lines'], fn (array $l): bool => $l['lineStableId'] === $s['renfortLineId']))[0]['undeterminedDutyCount']);
    }

    public function testACompletionReadsTheSourceHoldersItIsAboutToWrite(): void
    {
        $client = static::createClient();
        // Nobody is forced on Tuesday (the completion keeps the snapshot's eligibility, D145): whoever the solver
        // chose, the main line's Tuesday — and its reinforcement if any — is then emptied.
        $s = $this->generatedScenario($client, [self::FRI => 'admin@example.com', self::SAT => 'admin@example.com', self::SUN => 'admin@example.com']);
        if (null !== $this->renfortCalendar($s)[self::TUE]) {
            $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], self::TUE, 1));
        }
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], self::TUE));
        self::assertSame('UNDETERMINED', $this->renfortStates($client, $s)[self::TUE]);
        // Only Dr B can take the main line's Tuesday now.
        $this->declareRange($client, $s['admin'], self::TUE, '2027-01-06');
        $this->declareRange($client, $s['bob'], self::TUE, '2027-01-06');

        $outcome = $this->completePlanning($client, $s);

        // One operation, source first: the main line's Tuesday goes to Dr B, which triggers its reinforcement,
        // covered in the same completion — by someone who is not Dr B (SELF_COVERAGE, virtual commitment).
        self::assertSame('COMPLETE', $outcome['coverage']);
        self::assertSame('alice@example.com', $this->currentCalendar($s['planningId'])['0|'.self::TUE.'|ONCALL']);
        self::assertContains($this->renfortCalendar($s)[self::TUE], ['carol@example.com', 'dave@example.com']);
        self::assertSame('REQUIRED_ASSIGNED', $this->renfortStates($client, $s)[self::TUE]);
    }

    // --- blocks, policies ---------------------------------------------------------------------------------

    public function testOneTriggeredDayOfABlockRequiresTheWholeBlockLive(): void
    {
        $client = static::createClient();
        $s = $this->generatedScenario($client, self::DR_A_EVERYWHERE);

        // Dr C takes the main line's Saturday.
        $response = $this->replaceSource($client, $s, self::SAT, 'bob@example.com');

        self::assertCount(1, $response['dependentImpacts'], 'One impact for the whole block.');
        $impact = $response['dependentImpacts'][0];
        self::assertSame([self::FRI, self::SAT, self::SUN], $impact['dates']);
        self::assertSame('REQUIRED_UNASSIGNED', $impact['newState']);
        self::assertSame([self::SAT], $impact['triggeringDates']);

        $result = $this->renfortResult($client, $s);
        foreach ([self::FRI, self::SAT, self::SUN] as $date) {
            self::assertSame('REQUIRED_UNASSIGNED', $result[$date]['demand']['state']);
            self::assertSame([self::SAT], $result[$date]['demand']['triggeringDates']);
        }
        self::assertSame('HOLDER_HAS_NO_TRIGGER', $result[self::FRI]['demand']['dayReason'], 'Friday keeps its own explanation.');
        self::assertSame('TRIGGERED', $result[self::SAT]['demand']['dayReason']);
        self::assertSame(['FRIDAY', 'SATURDAY', 'SUNDAY'], $result[self::SAT]['demand']['trigger']['weekdays']);
        self::assertSame('NOT_REQUIRED_UNASSIGNED', $result[self::TUE]['demand']['state']);

        $this->completePlanning($client, $s);
        $calendar = $this->renfortCalendar($s);
        self::assertNotNull($calendar[self::FRI]);
        self::assertSame($calendar[self::FRI], $calendar[self::SAT]);
        self::assertSame($calendar[self::FRI], $calendar[self::SUN], 'The whole block, one person.');
        self::assertNull($calendar[self::TUE]);
    }

    public function testANewPolicyOnlyAppliesFromTheNextGeneration(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->forceHolders($client, $s, self::DR_A_EVERYWHERE);
        $this->launch($client, $s);

        // Dr A now triggers every day — without generating again.
        $this->setPolicy($client, $s, [['admin@example.com', self::ALL_DAYS], ['alice@example.com', self::ALL_DAYS], ['bob@example.com', ['FRIDAY', 'SATURDAY', 'SUNDAY']]]);

        self::assertSame([self::TUE => 'NOT_REQUIRED_UNASSIGNED', self::FRI => 'NOT_REQUIRED_UNASSIGNED', self::SAT => 'NOT_REQUIRED_UNASSIGNED', self::SUN => 'NOT_REQUIRED_UNASSIGNED'], $this->renfortStates($client, $s), 'The calendar keeps the policy its generation was built with.');
        self::assertSame('coverage_not_required', $this->candidatesFor($client, $s, $this->dutyOn($s['planningId'], self::TUE, 1))['notAssignableReason']);
        $outcome = $this->completePlanning($client, $s);
        self::assertSame('nothing_to_complete', array_values(array_filter($outcome['lines'], fn (array $l): bool => $l['lineStableId'] === $s['renfortLineId']))[0]['status']);

        // The next generation applies it.
        $this->launch($client, $s);
        self::assertSame(2, $this->snapshotOf($this->currentGeneration($s, 1))->getDemandPolicy()->getPolicyVersion());
        self::assertSame([self::TUE => 'REQUIRED_ASSIGNED', self::FRI => 'REQUIRED_ASSIGNED', self::SAT => 'REQUIRED_ASSIGNED', self::SUN => 'REQUIRED_ASSIGNED'], $this->renfortStates($client, $s));
    }

    // --- fairness of a completion ---------------------------------------------------------------------------

    public function testCompletionFairnessUsesTheLiveDemandAndCountsASuperfluousReinforcementAsLoad(): void
    {
        $client = static::createClient();
        // Renfort: Tuesday and Friday, each alone.
        $s = $this->generatedScenario(
            $client,
            [self::TUE => 'alice@example.com', self::FRI => 'admin@example.com', self::SAT => 'admin@example.com', self::SUN => 'admin@example.com'],
            self::WEEK,
            ['blocks' => [], 'solo' => ['MAR', 'VEN'], 'soloFamily' => '', 'excluded' => ['LUN', 'MER', 'JEU', 'SAM', 'DIM']],
        );
        $first = $this->renfortCalendar($s)[self::TUE];
        self::assertNotNull($first);
        $second = 'carol@example.com' === $first ? 'dave@example.com' : 'carol@example.com';

        // Tuesday's reinforcement becomes superfluous (kept), Friday's becomes required.
        $this->replaceSource($client, $s, self::TUE, 'admin@example.com');
        $this->replaceSource($client, $s, self::FRI, 'alice@example.com');
        FaultInjectingPlanningSolver::$solvedProblems = [];

        $this->completePlanning($client, $s);

        // The problem: demand, exposure and targets on the live demand (Friday only)…
        $problem = $this->renfortProblem($s);
        self::assertCount(1, $problem->getRequiredDutyUnits());
        self::assertSame(self::FRI, $problem->getRequiredDutyUnits()[0]->getDuties()[0]->getLocalDate()->format('Y-m-d'));
        self::assertSame(1.0, $problem->getRequiredDemand()->get(FairnessDimensionKey::totalDuties()));
        self::assertSame(0.5, $problem->getFairnessTarget($this->userId($first))->get(FairnessDimensionKey::totalDuties()));
        // …and Tuesday, still held, as a load-only unit fixed to its holder: never demand, always load.
        self::assertCount(1, $problem->getOptionalDutyUnits());
        $loadOnly = $problem->getOptionalDutyUnits()[0];
        self::assertSame(self::TUE, $loadOnly->getDuties()[0]->getLocalDate()->format('Y-m-d'));
        self::assertFalse($loadOnly->isRequired());
        self::assertSame($this->memberStableIdIn($s['planningId'], $first, 1), $problem->getFixedAssignee($loadOnly->getStableKey()));

        // So the new need goes to the other person.
        $calendar = $this->renfortCalendar($s);
        self::assertSame($first, $calendar[self::TUE], 'The superfluous reinforcement is never rewritten.');
        self::assertSame($second, $calendar[self::FRI]);
    }
}
