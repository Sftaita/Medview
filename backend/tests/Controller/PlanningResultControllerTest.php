<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PlanningGeneration;
use App\Repository\PlanningGenerationRepository;
use App\Repository\UserAvailabilityPeriodRepository;
use App\Tests\PlanningPilotTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * "Consultation du planning généré" (docs/decisions.md D130): the
 * per-line D125 rule applied to a full read of the period — every REQUIRED
 * Duty whether covered or not, and the real, persisted reasons for an
 * uncovered one. A real OR-Tools solve runs, as in PlanningLaunchControllerTest.
 */
final class PlanningResultControllerTest extends WebTestCase
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
    private function readResult(KernelBrowser $client, array $s, ?string $token = null): array
    {
        $result = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/result", token: $token ?? $s['creator']);
        self::assertResponseIsSuccessful();

        return $result;
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function launch(KernelBrowser $client, array $s, ?string $token = null): array
    {
        return $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/generations", [], $token ?? $s['creator']);
    }

    /**
     * @param array<string, mixed> $line a `lines[]` entry of a /result response
     *
     * @return array<string, mixed>
     */
    private function dutyOn(array $line, string $date): array
    {
        foreach ($line['duties'] as $duty) {
            if ($duty['date'] === $date) {
                return $duty;
            }
        }

        self::fail("No duty on {$date}.");
    }

    // --- source of truth: D125, no generation yet ---------------------------------

    public function testNoGenerationYetIsExplicitNeverAFakeZeroOverZero(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        // No prepareGeneration/launch at all: only a rule-set-less, duty-less line exists.

        $result = $this->readResult($client, $s);

        self::assertCount(1, $result['lines']);
        $line = $result['lines'][0];
        self::assertNull($line['generationStableId']);
        self::assertNull($line['generatedAt']);
        self::assertNull($line['coverageStatus']);
        self::assertSame(0, $line['requiredDutyCount']);
        self::assertSame(0, $line['coveredRequiredDutyCount']);
        self::assertSame(0, $line['uncoveredRequiredDutyCount']);
        self::assertSame([], $line['duties']);
    }

    public function testTheMostRecentCompletedGenerationIsUsedAndTheOlderOneIsIgnored(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);

        $first = $this->launch($client, $s);
        self::assertResponseStatusCodeSame(201);
        $firstGenerationId = $first['lines'][0]['generationStableId'];

        // A second, real generation is created for the very same line.
        $second = $this->launch($client, $s);
        self::assertResponseStatusCodeSame(201);
        $secondGenerationId = $second['lines'][0]['generationStableId'];
        self::assertNotSame($firstGenerationId, $secondGenerationId);

        $result = $this->readResult($client, $s);
        self::assertSame($secondGenerationId, $result['lines'][0]['generationStableId'], 'D125: the most recent COMPLETED generation is shown, never an older one.');
    }

    public function testAnOlderGenerationsDiagnosticsStayUnchangedAfterANewerOneIsCreated(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        // Make the only duty unassignable: everyone declares UNAVAILABLE over it.
        foreach (['admin', 'alice', 'bob'] as $who) {
            $this->declareRange($client, $s[$who], '2027-01-05', '2027-01-06');
        }

        $first = $this->launch($client, $s);
        self::assertResponseStatusCodeSame(201);
        $firstGenerationId = $first['lines'][0]['generationStableId'];
        self::assertSame('INCOMPLETE', $first['lines'][0]['coverageStatus']);

        $container = static::getContainer();
        /** @var PlanningGeneration $firstGeneration */
        $firstGeneration = $container->get(PlanningGenerationRepository::class)->findOneByStableId($firstGenerationId);
        $diagnosticsBefore = $firstGeneration->getDiagnostics();
        self::assertNotNull($diagnosticsBefore, 'A real INCOMPLETE outcome persists its diagnostics (D130).');

        // Frees the duty, then generates again — a second, independent, COMPLETE generation.
        foreach (['admin', 'alice', 'bob'] as $who) {
            $client->request('DELETE', '/api/me/calendar/'.$this->onlyAvailabilityStableIdOf($who), server: $this->bearer($s[$who]));
        }
        $second = $this->launch($client, $s);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('COMPLETE', $second['lines'][0]['coverageStatus']);

        // Re-resolved from a fresh container reference: the requests above rebooted the kernel,
        // so the entity/EntityManager fetched before them no longer belong to the current one.
        $firstGenerationAfter = static::getContainer()->get(PlanningGenerationRepository::class)->findOneByStableId($firstGenerationId);
        self::assertSame($diagnosticsBefore, $firstGenerationAfter->getDiagnostics(), 'History is never rewritten: the older generation keeps its own diagnostics untouched.');
    }

    /** @return string the stableId of the one UserAvailabilityPeriod declared for $who in this test class's scenarios */
    private function onlyAvailabilityStableIdOf(string $who): string
    {
        $email = match ($who) {
            'admin' => 'admin@example.com',
            'alice' => 'alice@example.com',
            'bob' => 'bob@example.com',
        };
        $periods = static::getContainer()->get(UserAvailabilityPeriodRepository::class)->findByUser($this->userOf($email));

        return (string) $periods[0]->getStableId();
    }

    // --- coverage ------------------------------------------------------------------

    public function testCompleteCoverageAndTheRightAssigneeAreShown(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07']]);

        $this->launch($client, $s);
        $result = $this->readResult($client, $s);

        $line = $result['lines'][0];
        self::assertSame('COMPLETE', $line['coverageStatus']);
        self::assertSame(2, $line['requiredDutyCount']);
        self::assertSame(2, $line['coveredRequiredDutyCount']);
        self::assertSame(0, $line['uncoveredRequiredDutyCount']);
        self::assertCount(2, $line['duties']);

        $duty = $this->dutyOn($line, '2027-01-05');
        self::assertTrue($duty['covered']);
        self::assertTrue($duty['required']);
        self::assertNotNull($duty['assignment']);
        self::assertSame('AUTO', $duty['assignment']['source']);
        self::assertNotSame('', $duty['assignment']['user']['firstName']);
        self::assertSame([], $duty['reasons']);
    }

    public function testIncompleteCoverageShowsTheUncoveredDutyWithItsRealReasons(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07']]);
        // Every candidate (admin, alice, bob) is unavailable on the 5th only: that duty cannot be covered.
        foreach (['admin', 'alice', 'bob'] as $who) {
            $this->declareRange($client, $s[$who], '2027-01-05', '2027-01-06');
        }

        $launch = $this->launch($client, $s);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('INCOMPLETE', $launch['lines'][0]['coverageStatus']);

        $result = $this->readResult($client, $s);
        $line = $result['lines'][0];
        self::assertSame('INCOMPLETE', $line['coverageStatus']);
        self::assertSame(2, $line['requiredDutyCount']);
        self::assertSame(1, $line['coveredRequiredDutyCount']);
        self::assertSame(1, $line['uncoveredRequiredDutyCount']);

        $uncovered = $this->dutyOn($line, '2027-01-05');
        self::assertFalse($uncovered['covered']);
        self::assertNull($uncovered['assignment']);
        self::assertTrue($uncovered['required']);
        self::assertCount(3, $uncovered['reasons'], 'All three real candidates are listed, never fewer, never more.');
        foreach ($uncovered['reasons'] as $reason) {
            self::assertSame(['indisponible'], $reason['reasons']);
            self::assertNotSame('', $reason['candidateFirstName']);
        }

        $covered = $this->dutyOn($line, '2027-01-06');
        self::assertTrue($covered['covered']);
        self::assertSame([], $covered['reasons'], 'A covered duty never carries exclusion reasons.');
    }

    public function testNeverInventsAnExclusionForACandidateWhoWasSimplyNotChosen(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']]);
        // Only admin and alice are unavailable — bob remains a real, eligible candidate.
        foreach (['admin', 'alice'] as $who) {
            $this->declareRange($client, $s[$who], '2027-01-05', '2027-01-06');
        }

        $this->launch($client, $s);
        $result = $this->readResult($client, $s);
        $duty = $this->dutyOn($result['lines'][0], '2027-01-05');

        // Bob was eligible and got the duty: it is covered, so no reasons are attached at all —
        // the non-selection of an eligible candidate is never fabricated as an exclusion (D095).
        self::assertTrue($duty['covered']);
        self::assertSame([], $duty['reasons']);
        self::assertSame((string) $this->userOf('bob@example.com')->getStableId(), $duty['assignment']['user']['stableId']);
    }

    // --- several lines ---------------------------------------------------------------

    public function testSeveralLinesAreReportedIndependently(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $secondaryLine = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/lines", ['name' => 'Renfort'], $s['creator']);
        self::assertResponseStatusCodeSame(201);

        // Both active lines must be launchable at once (PlanningGenerationLauncher checks every
        // active line): the primary gets its real members and a coverable duty; the freshly
        // created "Renfort" team has a rule set and a duty of its own but deliberately no member
        // at all — a real, independent LINE_WITHOUT_MEMBERS/INCOMPLETE outcome, never a blocker.
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: 0);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: 1);

        $launch = $this->launch($client, $s);
        self::assertResponseStatusCodeSame(201);
        self::assertCount(2, $launch['lines']);

        $result = $this->readResult($client, $s);
        self::assertCount(2, $result['lines']);

        $primary = array_values(array_filter($result['lines'], static fn (array $l): bool => 'Seniors' === $l['lineName']))[0];
        $secondary = array_values(array_filter($result['lines'], static fn (array $l): bool => $l['lineStableId'] === $secondaryLine['stableId']))[0];

        self::assertSame('COMPLETE', $primary['coverageStatus']);
        self::assertSame(1, $primary['requiredDutyCount']);
        self::assertNotSame($primary['generationStableId'], $secondary['generationStableId'], 'Each line follows D125 independently, on its own generation.');
        self::assertSame('INCOMPLETE', $secondary['coverageStatus'], 'A line without any member cannot cover its duty — a real outcome, never a blocker.');
        self::assertSame(1, $secondary['requiredDutyCount']);
        self::assertSame(0, $secondary['coveredRequiredDutyCount']);
        self::assertFalse($this->dutyOn($secondary, '2027-01-05')['covered']);
    }

    // --- authorization -----------------------------------------------------------

    public function testAnyoneWhoCanViewThePlanningCanReadTheResult(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $this->launch($client, $s);

        foreach (['creator', 'admin', 'alice', 'bob'] as $who) {
            $this->readResult($client, $s, $s[$who]);
        }
    }

    public function testAnOutsiderAndAnUnauthenticatedCallerAreRefused(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId']);
        $this->launch($client, $s);

        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/result", token: $s['outsider']);
        self::assertResponseStatusCodeSame(403);
        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/result");
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnUnknownPlanningReturns404(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $this->api($client, 'GET', '/api/plannings/00000000-0000-7000-8000-000000000000/result', token: $s['creator']);
        self::assertResponseStatusCodeSame(404);
    }

    // --- window filtering never changes the whole-period counts --------------------

    public function testFromToFiltersTheListedDutiesButNeverTheCoverageCounts(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareGeneration($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-02-05', '2027-02-06']]);

        $this->launch($client, $s);

        $full = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/result", token: $s['creator']);
        $january = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/result?from=2027-01-01&to=2027-02-01", token: $s['creator']);

        self::assertCount(2, $full['lines'][0]['duties']);
        self::assertCount(1, $january['lines'][0]['duties']);
        self::assertSame($full['lines'][0]['coverageStatus'], $january['lines'][0]['coverageStatus']);
        self::assertSame($full['lines'][0]['requiredDutyCount'], $january['lines'][0]['requiredDutyCount']);
        self::assertSame($full['lines'][0]['coveredRequiredDutyCount'], $january['lines'][0]['coveredRequiredDutyCount']);
    }
}
