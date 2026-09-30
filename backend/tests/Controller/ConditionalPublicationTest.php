<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PlanningPublication;
use App\Repository\PlanningPublicationEntryRepository;
use App\Repository\PlanningPublicationRepository;
use App\Repository\PlanningRepository;
use App\Repository\UserRepository;
use App\Service\CurrentCalendarReader;
use App\Service\PlanningExportDataBuilder;
use App\Service\PlanningExportFormat;
use App\Service\PlanningExportLineChoice;
use App\Service\PlanningExportPdfRenderer;
use App\Service\PlanningExportRequest;
use App\Service\PlanningPdfRenderer;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\ConditionalLineTestHelpers;
use App\Tests\FaultInjectingPlanningSolver;
use App\Tests\PlanningExportTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;

/**
 * Publication, statistics and outputs of a planning with a conditional line
 * (docs/decisions.md D166), end to end: one live decision (CalendarCell /
 * LiveDemandView) behind the preflight, the publication record and its PDF,
 * the republication email, the statistics and the PDF/XLSX export.
 *
 * Same scenario as ConditionalCalendarTest: Dr A = admin (no trigger), Dr B
 * = alice (every day), Dr C = bob (Friday to Sunday); Renfort: Tuesday
 * alone + a Friday–Sunday block, carol and dave.
 */
final class ConditionalPublicationTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;
    use ConditionalLineTestHelpers;
    use PlanningExportTestHelpers;

    private const TUE = '2027-01-05';
    private const FRI = '2027-01-08';
    private const SAT = '2027-01-09';
    private const SUN = '2027-01-10';
    private const DR_A_EVERYWHERE = [self::TUE => 'admin@example.com', self::FRI => 'admin@example.com', self::SAT => 'admin@example.com', self::SUN => 'admin@example.com'];
    /** Dr B on Tuesday (its reinforcement is required and covered), Dr A on the weekend (the block is not required). */
    private const DR_B_TUESDAY = [self::TUE => 'alice@example.com', self::FRI => 'admin@example.com', self::SAT => 'admin@example.com', self::SUN => 'admin@example.com'];

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
     * @param array<string, string> $holders
     *
     * @return array<string, mixed>
     */
    private function generatedScenario(KernelBrowser $client, array $holders): array
    {
        $s = $this->scenario($client);
        // Distinct names: every output below is checked by the name it shows.
        $container = static::getContainer();
        foreach (['admin' => ['Anne', 'Admin'], 'alice' => ['Alice', 'Bernard'], 'bob' => ['Bob', 'Claes'], 'carol' => ['Carol', 'Dubois'], 'dave' => ['Dave', 'Evrard']] as $who => [$first, $last]) {
            $user = $container->get(UserRepository::class)->findOneByEmail($who.'@example.com');
            $user->setFirstName($first);
            $user->setLastName($last);
        }
        $container->get(\Doctrine\ORM\EntityManagerInterface::class)->flush();
        $declared = $this->forceHolders($client, $s, $holders);
        self::assertSame('SUCCEEDED', $this->launch($client, $s)['status']);
        $this->liftUnavailabilities($client, $declared);

        return $s;
    }

    /**
     * Dr B's Tuesday reinforcement generated and covered, then Dr B replaced by Dr A: a superfluous reinforcement.
     *
     * @return array{0: array<string, mixed>, 1: string} the scenario and the reinforcement's holder
     */
    private function superfluousScenario(KernelBrowser $client): array
    {
        $s = $this->generatedScenario($client, self::DR_B_TUESDAY);
        $holder = $this->renfortCalendar($s)[self::TUE];
        self::assertNotNull($holder);
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], self::TUE), 'admin@example.com');
        self::assertResponseIsSuccessful();

        return [$s, $holder];
    }

    private function preflight(KernelBrowser $client, array $s): array
    {
        return $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/publication-preflight", token: $s['creator']);
    }

    private function publish(KernelBrowser $client, array $s): array
    {
        return $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
    }

    private function nameOf(string $email): string
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail($email);

        return $user->getFirstName().' '.$user->getLastName();
    }

    private function latestPublication(array $s): PlanningPublication
    {
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($s['planningId']);

        return static::getContainer()->get(PlanningPublicationRepository::class)->findLatestForPlanning($planning);
    }

    /**
     * What the publication PDF shows for one line, by date (PlanningPdfRenderer's own view model).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function publicationPdfColumn(array $s, int $lineIndex): array
    {
        $view = static::getContainer()->get(PlanningPdfRenderer::class)->view($this->latestPublication($s));
        $byDate = [];
        foreach ($view['weeks'] as $week) {
            foreach ($week['days'] as $day) {
                $byDate[$day['label']] = $day['cells'][$lineIndex];
            }
        }

        return $byDate;
    }

    // --- the preflight matrix ------------------------------------------------------------------------------

    public function testThePreflightMatrix(): void
    {
        $client = static::createClient();
        $s = $this->generatedScenario($client, self::DR_B_TUESDAY);
        self::assertTrue($this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['canManageLineStructure']);
        self::assertFalse($this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['carol'])['canManageLineStructure'], 'A plain member configures nothing.');

        // REQUIRED_ASSIGNED (Tuesday) and NOT_REQUIRED_UNASSIGNED (the block): nothing to say.
        $preflight = $this->preflight($client, $s);
        self::assertTrue($preflight['publishable']);
        self::assertSame([], $preflight['uncoveredDuties'], 'A reinforcement nobody needs is never a missing duty.');
        self::assertSame([], $preflight['superfluousCoverages']);
        self::assertSame([], $preflight['undeterminedDuties']);

        // REQUIRED_UNASSIGNED: Dr C takes the main line's Saturday — the whole block is now required and empty.
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], self::SAT), 'bob@example.com');
        $preflight = $this->preflight($client, $s);
        self::assertFalse($preflight['publishable']);
        self::assertSame([self::FRI, self::SAT, self::SUN], array_column($preflight['uncoveredDuties'], 'date'));
        foreach ($preflight['uncoveredDuties'] as $uncovered) {
            self::assertSame('Renfort', $uncovered['lineName'], 'A missing reinforcement is named as one (D167).');
            self::assertTrue($uncovered['conditional']);
        }
        $refused = $this->publish($client, $s);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_publishable', $refused['error']);
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], self::SAT), 'admin@example.com');

        // NOT_REQUIRED_ASSIGNED: Dr A replaces Dr B on Tuesday — a structured warning, never a blocker.
        $holder = $this->renfortCalendar($s)[self::TUE];
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], self::TUE), 'admin@example.com');
        $preflight = $this->preflight($client, $s);
        self::assertTrue($preflight['publishable']);
        self::assertSame([], $preflight['uncoveredDuties']);
        self::assertCount(1, $preflight['superfluousCoverages']);
        $warning = $preflight['superfluousCoverages'][0];
        self::assertSame('SUPERFLUOUS_CONDITIONAL_COVERAGE', $warning['code']);
        self::assertSame('Renfort', $warning['lineName']);
        self::assertSame([self::TUE], $warning['dates']);
        self::assertSame($this->memberStableIdIn($s['planningId'], $holder, 1), $warning['member']['teamMemberStableId']);
        self::assertSame(self::TUE, $warning['source']['date']);
        self::assertSame($this->userId('admin@example.com'), $warning['source']['holder']['userStableId']);
        self::assertSame('HOLDER_HAS_NO_TRIGGER', $warning['reason']);
        self::assertStringContainsString($this->nameOf($holder).' reste affecté', $warning['explanation']);
    }

    // --- publication, its record and its PDF ------------------------------------------------------------

    public function testASuperfluousReinforcementIsPublishedAsARealAssignmentAndANonRequiredOneNotAtAll(): void
    {
        $client = static::createClient();
        [$s, $holder] = $this->superfluousScenario($client);
        $history = $this->history($s);

        $this->publish($client, $s);
        self::assertResponseIsSuccessful('A superfluous reinforcement never prevents the publication.');

        // The record: Tuesday's reinforcement with its real holder; the block (not required, empty) is not published.
        $entries = static::getContainer()->get(PlanningPublicationEntryRepository::class)->findByPublication($this->latestPublication($s));
        $renfort = [];
        foreach ($entries as $entry) {
            if ('Renfort' === $this->lineName($entry->getDuty())) {
                $renfort[$entry->getDuty()->getLocalDate()->format('Y-m-d')] = $entry->getTeamMember()?->getUser()->getEmail();
            }
        }
        self::assertSame([self::TUE => $holder], $renfort);

        // The publication PDF: the real assignment shown, never a "Non attribué" for the block.
        $pdf = $this->publicationPdfColumn($s, 1);
        foreach ($pdf as $label => $items) {
            if (str_contains($label, '05/01')) {
                self::assertSame($this->nameOf($holder), $items[0]['name']);
            } else {
                self::assertSame([], $items, "Nothing on {$label} for Renfort — never a gap.");
            }
        }

        // The result the calendar reads says the same.
        $result = $this->renfortResult($client, $s);
        self::assertSame('NOT_REQUIRED_ASSIGNED', $result[self::TUE]['demand']['state']);
        self::assertTrue($result[self::TUE]['covered']);
        self::assertSame('NOT_REQUIRED_UNASSIGNED', $result[self::FRI]['demand']['state']);
        self::assertFalse($result[self::FRI]['required']);

        self::assertSame($history, $this->history($s), 'Publishing never touches what the generation froze.');
    }

    public function testTheRepublicationNeverPresentsANonRequiredReinforcementAsMissing(): void
    {
        $client = static::createClient();
        [$s, $holder] = $this->superfluousScenario($client);
        $this->publish($client, $s);

        // Retiring the superfluous reinforcement: a real change, announced as "no reinforcement" — never "Non attribué".
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], self::TUE, 1));
        $state = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/publication-state", token: $s['creator']);
        self::assertCount(1, $state['changes']);
        self::assertTrue($state['changes'][0]['beforeShown']);
        self::assertFalse($state['changes'][0]['afterShown']);
        self::assertNull($state['changes'][0]['after']);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/republish", [], $s['creator']);
        self::assertResponseIsSuccessful();
        // docs/decisions.md D172: only the former holder is told — a removal of their reinforcement.
        $bodies = $this->bodiesByRecipient();
        self::assertSame([$holder], array_keys($bodies));
        self::assertStringContainsString("GARDE RETIRÉE\n\n- Mardi 5 janvier 2027 — Renfort", $bodies[$holder]);
        self::assertStringNotContainsString('Non attribué', $bodies[$holder]);
    }

    /**
     * @return array<string, string> text body by recipient address
     */
    private function bodiesByRecipient(): array
    {
        $bodies = [];
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email) {
                $bodies[$message->getTo()[0]->getAddress()] = (string) $message->getTextBody();
            }
        }
        ksort($bodies);

        return $bodies;
    }

    public function testAnUnchangedNonRequiredReinforcementIsNotPartOfTheRepublicationDigest(): void
    {
        $client = static::createClient();
        $s = $this->generatedScenario($client, self::DR_A_EVERYWHERE);
        $this->publish($client, $s);
        self::assertResponseIsSuccessful();

        // Dr A → Dr C on Tuesday: Dr C does not trigger Tuesday, the reinforcement stays not required.
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], self::TUE), 'bob@example.com');
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/republish", [], $s['creator']);
        self::assertResponseIsSuccessful();

        // docs/decisions.md D172: Dr A loses Tuesday, Dr C gains it — the reinforcement nobody needs is nobody's change.
        $bodies = $this->bodiesByRecipient();
        self::assertSame(['admin@example.com', 'bob@example.com'], array_keys($bodies));
        self::assertStringContainsString("GARDE RETIRÉE\n\n- Mardi 5 janvier 2027 — Seniors", $bodies['admin@example.com']);
        self::assertStringContainsString("GARDE AJOUTÉE\n\n- Mardi 5 janvier 2027 — Seniors", $bodies['bob@example.com']);
        foreach ($bodies as $body) {
            self::assertStringNotContainsString('Renfort', $body, 'The reinforcement nobody needs is not part of anybody\'s changes.');
            self::assertStringNotContainsString('Non attribué', $body);
        }

        // Dr C → Dr B: the reinforcement becomes needed and missing — still nobody's own change: only C and B are told.
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], self::TUE), 'alice@example.com');
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/republish", [], $s['creator']);
        self::assertResponseIsSuccessful();
        $bodies = $this->bodiesByRecipient();
        self::assertSame(['alice@example.com', 'bob@example.com'], array_keys($bodies));
        foreach ($bodies as $body) {
            self::assertStringNotContainsString('Renfort', $body);
        }
    }

    public function testAnUndeterminedReinforcementBlocksTheRepublication(): void
    {
        $client = static::createClient();
        $s = $this->generatedScenario($client, self::DR_A_EVERYWHERE);
        $this->publish($client, $s);
        self::assertResponseIsSuccessful();

        // After publication, removing a main-line holder is allowed (a removal announced, D143)…
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], self::TUE));
        $preflight = $this->preflight($client, $s);
        // …but its reinforcement's demand is now unknown: that blocks.
        self::assertFalse($preflight['republishable']);
        self::assertCount(1, $preflight['undeterminedDuties']);
        self::assertSame('UNDETERMINED_CONDITIONAL_DEMAND', $preflight['undeterminedDuties'][0]['code']);
        self::assertStringContainsString('impossible de savoir', $preflight['undeterminedDuties'][0]['explanation']);
        $refused = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/republish", [], $s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_publishable', $refused['error']);

        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], self::TUE), 'admin@example.com');
        self::assertTrue($this->preflight($client, $s)['republishable']);
    }

    // --- statistics ------------------------------------------------------------------------------------------

    public function testStatisticsCountTheRealLoadWhileTheDemandLeavesNonRequiredReinforcementsOut(): void
    {
        $client = static::createClient();
        [$s, $holder] = $this->superfluousScenario($client);

        // Load: the superfluous reinforcement is a real assignment, in both tabs.
        $statistics = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/statistics", token: $s['creator']);
        foreach (['currentPeriod', 'cumulative'] as $tab) {
            $groups = array_column($statistics[$tab]['groups'], null, 'groupLabel');
            $rows = array_column($groups['Renfort']['members'], null, 'teamMemberStableId');
            $row = $rows[$this->memberStableIdIn($s['planningId'], $holder, 1)];
            self::assertSame(1, $row['total'], "{$tab}: still a real load.");
            self::assertSame(1, $row['countsByWeekday']['TUE']);
            $main = array_column($groups['Seniors']['members'], null, 'teamMemberStableId');
            self::assertSame(4, $main[$this->memberStableIdIn($s['planningId'], 'admin@example.com')]['total'], 'The independent line: unchanged.');
        }

        // Demand: never "to cover" nor "missing".
        $lines = array_column($this->api($client, 'GET', "/api/plannings/{$s['planningId']}/result", token: $s['creator'])['lines'], null, 'lineName');
        self::assertSame(0, $lines['Renfort']['requiredDutyCount']);
        self::assertSame(0, $lines['Renfort']['uncoveredRequiredDutyCount']);
        self::assertSame(4, $lines['Renfort']['notRequiredDutyCount']);
        self::assertSame(1, $lines['Renfort']['superfluousDutyCount']);
        self::assertSame(0, $lines['Renfort']['undeterminedDutyCount']);
        self::assertSame(4, $lines['Seniors']['requiredDutyCount'], 'The independent line: unchanged.');
        self::assertSame(0, $lines['Seniors']['notRequiredDutyCount']);
    }

    // --- export -------------------------------------------------------------------------------------------------

    public function testThePdfAndXlsxExportsShareOneDecision(): void
    {
        $client = static::createClient();
        [$s, $holder] = $this->superfluousScenario($client);
        $this->publish($client, $s);
        self::assertResponseIsSuccessful();
        // After publication, the main line's Saturday loses its holder: the weekend block becomes undetermined.
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], self::SAT));

        $body = [
            'format' => 'xlsx',
            'title' => 'Gardes',
            'from' => self::TUE,
            'to' => '2027-01-11',
            'lines' => [['stableId' => $s['primaryLineId'], 'label' => 'Seniors'], ['stableId' => $s['renfortLineId'], 'label' => 'Renfort']],
        ];
        $client->request('POST', "/api/plannings/{$s['planningId']}/export", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$s['creator'], 'CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));
        self::assertResponseIsSuccessful();
        $sheets = $this->readXlsx((string) $client->getResponse()->getContent());

        $byDate = [];
        foreach (\array_slice($sheets['Planning'], 1) as $row) {
            $byDate[$row[0]->format('Y-m-d')] = [$row[2] ?? '', $row[3] ?? ''];
        }
        self::assertSame([$this->nameOf('admin@example.com'), $this->nameOf($holder)], $byDate[self::TUE], 'The superfluous reinforcement: its real holder.');
        self::assertSame([$this->nameOf('admin@example.com'), 'Renfort non évalué'], $byDate[self::FRI], 'Undetermined: explicit, never "not required".');
        self::assertSame(['Non attribué', 'Renfort non évalué'], $byDate[self::SAT], 'The independent line keeps its "Non attribué".');
        self::assertSame(['', ''], $byDate['2027-01-06'], 'No duty that day.');
        $people = array_map(static fn (array $row): string => $row[0].'|'.$row[1]->format('Y-m-d').'|'.$row[3], \array_slice($sheets['Par personne'], 1));
        self::assertContains($this->nameOf($holder).'|'.self::TUE.'|Renfort', $people);

        // The PDF reads the very same items.
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($s['planningId']);
        $data = $container->get(PlanningExportDataBuilder::class)->build(new PlanningExportRequest(
            $planning,
            PlanningExportFormat::PDF,
            'Gardes',
            new \DateTimeImmutable(self::TUE),
            new \DateTimeImmutable('2027-01-11'),
            [new PlanningExportLineChoice($this->line($s['planningId'], 0), 'Seniors'), new PlanningExportLineChoice($this->line($s['planningId'], 1), 'Renfort')],
        ));
        $pdfItems = [];
        foreach ($container->get(PlanningExportPdfRenderer::class)->view($data)['months'] as $month) {
            foreach ($month['weeks'] as $week) {
                foreach ($week as $day) {
                    if (null !== $day['number'] && $day['inRange']) {
                        $pdfItems[] = array_map(static fn (array $items): array => array_map(static fn (array $i): string => $i['name'] ?? $i['gap'], $items), $day['cells']);
                    }
                }
            }
        }
        self::assertContains([[$this->nameOf('admin@example.com')], [$this->nameOf($holder)]], $pdfItems);
        self::assertContains([['Non attribué'], ['Renfort non évalué']], $pdfItems);
        self::assertNotContains([[$this->nameOf('admin@example.com')], ['Non attribué']], $pdfItems, 'A reinforcement is never a plain "Non attribué" here.');
    }

    public function testANonRequiredEmptyReinforcementIsNeverExported(): void
    {
        $client = static::createClient();
        $s = $this->generatedScenario($client, self::DR_A_EVERYWHERE);
        $this->publish($client, $s);
        self::assertResponseIsSuccessful();

        $cells = array_filter(
            static::getContainer()->get(CurrentCalendarReader::class)->read(static::getContainer()->get(PlanningRepository::class)->findOneByStableId($s['planningId'])),
            static fn ($cell): bool => 'Renfort' === $cell->line->getName(),
        );
        self::assertCount(4, $cells);
        foreach ($cells as $cell) {
            self::assertFalse($cell->isShown());
        }

        $client->request('POST', "/api/plannings/{$s['planningId']}/export", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$s['creator'], 'CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'format' => 'xlsx',
            'title' => 'Gardes',
            'lines' => [['stableId' => $s['renfortLineId'], 'label' => 'Renfort']],
        ]));
        self::assertResponseIsSuccessful();
        $sheets = $this->readXlsx((string) $client->getResponse()->getContent());
        foreach (\array_slice($sheets['Planning'], 1) as $row) {
            self::assertSame('', $row[2] ?? '', 'Never "Non attribué": nothing at all.');
        }
        self::assertCount(1, $sheets['Par personne'], 'Nobody holds anything on Renfort.');
    }

    // --- independent lines: unchanged -----------------------------------------------------------------------

    public function testAnIndependentLineKeepsEveryOutputAsBefore(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::WEEK);
        $this->generate($client, $s);
        $this->publish($client, $s);
        self::assertResponseIsSuccessful();

        $cells = static::getContainer()->get(CurrentCalendarReader::class)->read(static::getContainer()->get(PlanningRepository::class)->findOneByStableId($s['planningId']));
        foreach ($cells as $cell) {
            self::assertNull($cell->coverageState);
            self::assertTrue($cell->isShown());
        }

        // A removal after publication: announced as "Non attribué", exported as "Non attribué", as before.
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], self::TUE));
        $state = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/publication-state", token: $s['creator']);
        self::assertTrue($state['changes'][0]['afterShown']);
        $preflight = $this->preflight($client, $s);
        self::assertTrue($preflight['republishable']);
        self::assertSame([], $preflight['superfluousCoverages']);
        self::assertSame([], $preflight['undeterminedDuties']);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/republish", [], $s['creator']);
        // docs/decisions.md D172: the former holder is told of the removal, nobody else.
        $bodies = $this->bodiesByRecipient();
        self::assertCount(1, $bodies);
        self::assertStringContainsString("GARDE RETIRÉE\n\n- Mardi 5 janvier 2027 — Seniors", array_values($bodies)[0]);

        $client->request('POST', "/api/plannings/{$s['planningId']}/export", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$s['creator'], 'CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'format' => 'xlsx',
            'title' => 'Gardes',
            'from' => self::TUE,
            'to' => '2027-01-06',
            'lines' => [['stableId' => $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'][0]['stableId'], 'label' => 'Seniors']],
        ]));
        self::assertSame('Non attribué', $this->readXlsx((string) $client->getResponse()->getContent())['Planning'][1][2]);
    }

    private function lineName(\App\Entity\Duty $duty): string
    {
        return (string) static::getContainer()->get(\App\Repository\PlanningLineRepository::class)->findOneByPlanningPeriod($duty->getPlanningPeriod())?->getName();
    }
}
