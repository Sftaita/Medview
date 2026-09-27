<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DutyAssignmentSource;
use App\Entity\PlanningPeriodStatus;
use App\Repository\DutyAssignmentEventRepository;
use App\Repository\DutyAssignmentRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningRepository;
use App\Tests\CalendarWorkflowTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * "Compléter automatiquement" (docs/decisions.md D145): a real OR-Tools
 * solve over the current calendar where every existing assignment is a
 * fixedAssignment — only the holes can change.
 */
final class PlanningCompletionControllerTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;

    private const STANDALONE = [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07'], ['2027-01-07', '2027-01-08'], ['2027-01-08', '2027-01-09']];
    private const BLOCK = ['2027-01-09', '2027-01-10', '2027-01-11'];

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    public function testCompletionKeepsEveryExistingAssignmentAndFillsOnlyTheHoles(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE, self::BLOCK);
        $this->generate($client, $s);
        $before = $this->currentCalendar($s['planningId']);
        self::assertNotContains(null, $before, 'Precondition: the generation covered everything.');

        // Wednesday 6 and the Saturday–Sunday block become holes.
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'));
        self::assertResponseIsSuccessful();
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-10'));
        self::assertResponseIsSuccessful();
        $holes = $this->currentCalendar($s['planningId']);
        self::assertSame(3, \count(array_filter($holes, static fn (?string $who): bool => null === $who)), 'One standalone hole + the two days of the block.');

        $response = $this->completePlanning($client, $s);
        self::assertResponseIsSuccessful();
        self::assertSame('completed', $response['lines'][0]['status']);
        self::assertSame(2, $response['lines'][0]['holeCount'], 'Two units: the standalone duty and the block (one unit).');
        self::assertSame(2, $response['lines'][0]['filledUnitCount']);
        self::assertSame(0, $response['lines'][0]['remainingUncoveredRequiredUnitCount']);

        $after = $this->currentCalendar($s['planningId']);
        self::assertNotContains(null, $after, 'Every hole was filled.');
        foreach ($before as $key => $who) {
            if (!str_contains($key, '2027-01-06') && !str_contains($key, '2027-01-09') && !str_contains($key, '2027-01-10')) {
                self::assertSame($who, $after[$key], "{$key} was not a hole: it must be exactly as before.");
            }
        }
        $blockHolders = array_values(array_filter($after, static fn (string $key): bool => str_contains($key, '2027-01-09') || str_contains($key, '2027-01-10'), \ARRAY_FILTER_USE_KEY));
        self::assertCount(2, $blockHolders);
        self::assertSame($blockHolders[0], $blockHolders[1], 'The block was filled atomically, by one person.');
    }

    public function testFilledHolesAreAutoAssignmentsWithAnEventAuthoredByTheManager(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $wednesday = $this->dutyEntityOn($s['planningId'], '2027-01-06');
        $this->unassignDuty($client, $s, (string) $wednesday->getStableId());

        $this->completePlanning($client, $s, $s['admin']);
        self::assertResponseIsSuccessful();

        $container = static::getContainer();
        $duty = $this->dutyEntityOn($s['planningId'], '2027-01-06');
        $generation = $container->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($duty->getPlanningPeriod());
        $assignment = $container->get(DutyAssignmentRepository::class)->findCurrentByGenerationAndDuty($generation, $duty);
        self::assertNotNull($assignment);
        self::assertSame(DutyAssignmentSource::AUTO, $assignment->getSource());

        $events = $container->get(DutyAssignmentEventRepository::class)->findByDuty($duty);
        self::assertCount(2, $events, 'The removal, then the completion.');
        self::assertNull($events[1]->getPreviousAssignment());
        self::assertSame($assignment->getId(), $events[1]->getNewAssignment()?->getId());
        self::assertSame('admin@example.com', $events[1]->getAuthor()->getEmail());
    }

    public function testAManualAssignmentIsNeverUndoneByTheCompletion(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);

        $monday = $this->dutyOn($s['planningId'], '2027-01-05');
        $mondayHolder = $this->currentCalendar($s['planningId'])['0|2027-01-05|ONCALL'];
        $manualPick = 'alice@example.com' === $mondayHolder ? 'bob@example.com' : 'alice@example.com';
        $this->reassignTo($client, $s, $monday, $manualPick);
        self::assertResponseIsSuccessful();
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-07'));

        $this->completePlanning($client, $s);
        self::assertResponseIsSuccessful();

        $after = $this->currentCalendar($s['planningId']);
        self::assertSame($manualPick, $after['0|2027-01-05|ONCALL']);
        $duty = $this->dutyEntityOn($s['planningId'], '2027-01-05');
        $generation = static::getContainer()->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($duty->getPlanningPeriod());
        self::assertSame(DutyAssignmentSource::MANUAL, static::getContainer()->get(DutyAssignmentRepository::class)->findCurrentByGenerationAndDuty($generation, $duty)->getSource());
        self::assertNotNull($after['0|2027-01-07|ONCALL']);
    }

    public function testAHoleIsOnlyGivenToSomeoneLiveEligible(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-06', '2027-01-07']]);
        $this->generate($client, $s);
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'));

        // Declared *after* the generation: the frozen snapshot knows nothing about it, the completion must.
        $this->declareRange($client, $s['alice'], '2027-01-06', '2027-01-07');
        $this->declareRange($client, $s['bob'], '2027-01-06', '2027-01-07');

        $this->completePlanning($client, $s);
        self::assertResponseIsSuccessful();
        self::assertSame('admin@example.com', $this->currentCalendar($s['planningId'])['0|2027-01-06|ONCALL']);
    }

    public function testAHoleNobodyCanTakeStaysUncoveredAndTheRestIsKept(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $before = $this->currentCalendar($s['planningId']);
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'));
        foreach (['admin', 'alice', 'bob'] as $who) {
            $this->declareRange($client, $s[$who], '2027-01-06', '2027-01-07');
        }

        $response = $this->completePlanning($client, $s);
        self::assertResponseIsSuccessful();
        self::assertSame('completed', $response['lines'][0]['status']);
        self::assertSame(0, $response['lines'][0]['filledUnitCount']);
        self::assertSame(1, $response['lines'][0]['remainingUncoveredRequiredUnitCount']);

        $after = $this->currentCalendar($s['planningId']);
        self::assertNull($after['0|2027-01-06|ONCALL']);
        unset($before['0|2027-01-06|ONCALL'], $after['0|2027-01-06|ONCALL']);
        self::assertSame($before, $after);
    }

    public function testNothingToCompleteChangesNothing(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $before = $this->currentCalendar($s['planningId']);

        $response = $this->completePlanning($client, $s);
        self::assertResponseIsSuccessful();
        self::assertSame('nothing_to_complete', $response['lines'][0]['status']);
        self::assertSame($before, $this->currentCalendar($s['planningId']));
    }

    public function testANeverGeneratedLineIsReportedAsSuch(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $response = $this->completePlanning($client, $s);
        self::assertResponseIsSuccessful();
        self::assertSame('not_generated', $response['lines'][0]['status']);
    }

    public function testCompletionWorksOnAPublishedPlanningWhichStaysPublished(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();

        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'));
        $this->completePlanning($client, $s);
        self::assertResponseIsSuccessful();

        self::assertNotNull($this->currentCalendar($s['planningId'])['0|2027-01-06|ONCALL']);
        self::assertSame(PlanningPeriodStatus::PUBLISHED, $this->periodStatusOf($s['planningId']));
    }

    public function testOnlyAManagerCanComplete(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);

        $this->completePlanning($client, $s, $s['alice']);
        self::assertResponseStatusCodeSame(403);
        $this->completePlanning($client, $s, $s['outsider']);
        self::assertResponseStatusCodeSame(403);
        $this->completePlanning($client, $s, $s['admin']);
        self::assertResponseIsSuccessful();

        self::assertNotNull(static::getContainer()->get(PlanningRepository::class)->findOneByStableId($s['planningId']));
    }
}
