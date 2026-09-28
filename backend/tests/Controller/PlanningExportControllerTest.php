<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningLineRepository;
use App\Repository\PlanningPublicationRepository;
use App\Repository\PlanningRepository;
use App\Service\PlanningExportPdfRenderer;
use App\Service\PlanningPdfRenderer;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\PlanningExportTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * POST /api/plannings/{id}/export (docs/planning-export.md, D150): the
 * *current* calendar of a published planning — a later reassignment shows
 * at once — as a PDF or an .xlsx, for the chosen lines, in the chosen
 * order, under the chosen names, over the whole planning or a sub-period.
 * Driven through the real API with a real OR-Tools generation.
 */
final class PlanningExportControllerTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;
    use PlanningExportTestHelpers;

    private const STANDALONE = [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07'], ['2027-01-07', '2027-01-08']];

    private const NAMES = [
        'admin@example.com' => ['Marie-Élisabeth', 'de La Rochefoucauld-Montmorency'],
        'alice@example.com' => ['Élodie', 'Dupré-Lefèvre'],
        'bob@example.com' => ['=Bob', 'Formule'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    // --- formats -----------------------------------------------------------------------

    public function testThePdfIsTheWholePublishedPlanningOneMonthPerPageForAnyViewer(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);

        foreach (['creator', 'admin', 'alice'] as $who) {
            $bytes = $this->export($client, $s, $this->body($s), $s[$who]);
            self::assertResponseIsSuccessful("{$who} can read the planning, so can export it.");
            self::assertResponseHeaderSame('Content-Type', 'application/pdf');
            self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=Gardes-Seniors_2027-01_2027-04.pdf');
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringStartsWith('%PDF-', $bytes);
            self::assertGreaterThanOrEqual(4, $this->pdfPageCount($bytes), 'January to April, each month starting its own page.');
        }
    }

    public function testTheWorkbookHoldsTheCurrentCalendarDayByDayAndPersonByPerson(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $holders = $this->holderNames($s['planningId']);

        $bytes = $this->export($client, $s, $this->body($s, ['format' => 'xlsx']), $s['alice']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=Gardes-Seniors_2027-01_2027-04.xlsx');

        $sheets = $this->readXlsx($bytes);
        self::assertSame(['Planning', 'Par personne'], array_keys($sheets));

        $planning = $sheets['Planning'];
        self::assertSame(['Date', 'Jour', 'Seniors'], $planning[0]);
        self::assertCount(1 + 120, $planning, 'Every date from the first to the last day of the planning.');
        self::assertSame('2027-01-01', $planning[1][0]->format('Y-m-d'), 'First day of the planning.');
        self::assertSame(['Vendredi', ''], [$planning[1][1], $planning[1][2] ?? ''], 'A day without duty: an empty cell.');
        self::assertSame('2027-04-30', $planning[120][0]->format('Y-m-d'), 'Last day (endsAt is exclusive).');
        foreach (['2027-01-05' => 5, '2027-01-06' => 6, '2027-01-07' => 7] as $date => $row) {
            self::assertSame($date, $planning[$row][0]->format('Y-m-d'));
            self::assertSame($this->safe($holders[$date]), $planning[$row][2], "Holder of {$date}, as the calendar shows it.");
        }

        $expected = [];
        foreach ($holders as $date => $name) {
            $expected[] = $this->safe($name).'|'.$date.'|Seniors';
        }
        $collator = new \Collator('fr_FR');
        usort($expected, static fn (string $a, string $b): int => [$collator->compare(explode('|', $a)[0], explode('|', $b)[0]), $a] <=> [0, $b]);
        $people = array_map(static fn (array $row): string => $row[0].'|'.$row[1]->format('Y-m-d').'|'.$row[3], \array_slice($sheets['Par personne'], 1));
        self::assertSame($expected, $people);

        self::assertStringContainsString('Généré le 10 décembre 2026 à 09:00', $this->xlsxPart($bytes, 'xl/worksheets/sheet1.xml'), 'The generation date/time, in the planning timezone.');
        foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'] as $part) {
            self::assertStringNotContainsString('<f>', $this->xlsxPart($bytes, $part), '"=Bob" is text, never a formula.');
        }
    }

    // --- source of truth ---------------------------------------------------------------

    public function testAReassignmentAfterPublicationShowsInTheNextExportWithoutPublishingAnything(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $before = $this->holderNames($s['planningId']);

        $holderEmail = $this->currentCalendar($s['planningId'])['0|2027-01-06|ONCALL'];
        $replacement = 'alice@example.com' === $holderEmail ? 'bob@example.com' : 'alice@example.com';
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'), $replacement);
        self::assertResponseIsSuccessful();
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-07'));
        self::assertResponseIsSuccessful();

        $planning = $this->readXlsx($this->export($client, $s, $this->body($s, ['format' => 'xlsx']), $s['alice']))['Planning'];
        self::assertResponseIsSuccessful();
        self::assertSame($this->safe($before['2027-01-05']), $planning[5][2], 'Untouched.');
        self::assertSame($this->safe(implode(' ', self::NAMES[$replacement])), $planning[6][2], 'The new holder, not the published one.');
        self::assertSame('Non attribué', $planning[7][2], 'Removed after publication: uncovered now.');

        $publications = static::getContainer()->get(PlanningPublicationRepository::class)->findByPlanning($this->planning($s['planningId']));
        self::assertCount(1, $publications, 'An export is never a publication.');
        self::assertTrue($this->api($client, 'GET', "/api/plannings/{$s['planningId']}/publication-state", token: $s['creator'])['hasUnpublishedChanges']);
        self::assertEmailCount(0);
    }

    // --- lines -------------------------------------------------------------------------

    public function testLinesAreExportedAsChosenInTheChosenOrderUnderTheirExportNamesOnly(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->addSecondaryLine($client, $s, ['junior@example.com']);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->prepareLine($s['planningId'], [['2027-01-06', '2027-01-07']], lineIndex: 1);
        $this->generate($client, $s);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();
        [$seniors, $juniors] = $this->lineIds($s);

        $both = $this->readXlsx($this->export($client, $s, $this->body($s, [
            'format' => 'xlsx',
            'title' => 'Orthopédie & co',
            'lines' => [['stableId' => $juniors, 'label' => 'Membres supérieurs'], ['stableId' => $seniors, 'label' => '=Ortho']],
        ])));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=Gardes_Orthopedie-co_2027-01_2027-04.xlsx');
        self::assertSame(['Date', 'Jour', 'Membres supérieurs', "'=Ortho"], $both['Planning'][0]);
        self::assertSame(['', 'Test User'], [$both['Planning'][5][2] ?? '', $both['Planning'][5][3]], '5 Jan: only the Seniors line has a duty.');
        self::assertSame(['Test User', ''], [$both['Planning'][6][2], $both['Planning'][6][3] ?? ''], '6 Jan: only the Juniors line has a duty.');

        $juniorsOnly = $this->readXlsx($this->export($client, $s, $this->body($s, [
            'format' => 'xlsx',
            'lines' => [['stableId' => $juniors, 'label' => 'Juniors']],
        ])));
        self::assertSame(['Date', 'Jour', 'Juniors'], $juniorsOnly['Planning'][0], 'An unchecked line is simply absent.');
        self::assertSame([['Test User', '2027-01-06', 'Juniors']], array_map(static fn (array $row): array => [$row[0], $row[1]->format('Y-m-d'), $row[3]], \array_slice($juniorsOnly['Par personne'], 1)));

        $detail = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator']);
        self::assertSame(['Seniors', 'Juniors'], array_column($detail['lines'], 'name'), 'Export names never rename a line.');
        self::assertSame('Gardes Seniors', $detail['name'], 'The export title never renames the planning.');
    }

    // --- period ------------------------------------------------------------------------

    public function testACustomPeriodIsHalfOpenAndMustLieInsideThePlanning(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);

        $rows = $this->readXlsx($this->export($client, $s, $this->body($s, ['format' => 'xlsx', 'from' => '2027-01-05', 'to' => '2027-01-07'])))['Planning'];
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=Gardes-Seniors_2027-01.xlsx');
        self::assertSame(['2027-01-05', '2027-01-06'], array_map(static fn (array $row): string => $row[0]->format('Y-m-d'), \array_slice($rows, 1)), '"to" is exclusive.');

        $rows = $this->readXlsx($this->export($client, $s, $this->body($s, ['format' => 'xlsx', 'from' => '2027-01-01', 'to' => '2027-05-01'])))['Planning'];
        self::assertResponseIsSuccessful('The planning\'s own bounds are accepted.');
        self::assertCount(121, $rows);

        $rows = $this->readXlsx($this->export($client, $s, $this->body($s, ['format' => 'xlsx', 'from' => '2027-04-30'])))['Planning'];
        self::assertSame(['2027-04-30'], array_map(static fn (array $row): string => $row[0]->format('Y-m-d'), \array_slice($rows, 1)), 'Only from: up to the end of the planning.');

        foreach ([
            [['from' => '2026-12-31'], 'from'],
            [['to' => '2027-05-02'], 'to'],
            [['from' => '2027-01-10', 'to' => '2027-01-10'], 'to'],
            [['from' => '2027-01-10', 'to' => '2027-01-09'], 'to'],
            [['from' => '2027-02-30'], 'from'],
            [['to' => '01/02/2027'], 'to'],
        ] as [$period, $field]) {
            $response = $this->exportJson($client, $s, $this->body($s, $period));
            self::assertResponseStatusCodeSame(422, json_encode($period));
            self::assertSame('validation_failed', $response['error']);
            self::assertArrayHasKey($field, $response['violations'], json_encode($period));
        }
    }

    public function testOneDayAMonthEndAFullMonthAndTheDstChangeHaveNeitherGapNorRepeat(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $holders = $this->holderNames($s['planningId']);

        $dates = fn (string $from, string $to): array => array_map(
            static fn (array $row): string => $row[0]->format('Y-m-d'),
            \array_slice($this->readXlsx($this->export($client, $s, $this->body($s, ['format' => 'xlsx', 'from' => $from, 'to' => $to])))['Planning'], 1),
        );

        $oneDay = $this->readXlsx($this->export($client, $s, $this->body($s, ['format' => 'xlsx', 'from' => '2027-01-06', 'to' => '2027-01-07'])))['Planning'];
        self::assertCount(2, $oneDay, 'Header + a single day.');
        self::assertSame(['2027-01-06', 'Mercredi', $this->safe($holders['2027-01-06'])], [$oneDay[1][0]->format('Y-m-d'), $oneDay[1][1], $oneDay[1][2]]);

        self::assertSame(['2027-01-30', '2027-01-31', '2027-02-01', '2027-02-02'], $dates('2027-01-30', '2027-02-03'));
        self::assertCount(28, $dates('2027-02-01', '2027-03-01'), 'February 2027, whole.');
        self::assertSame(['2027-03-27', '2027-03-28', '2027-03-29'], $dates('2027-03-27', '2027-03-30'), 'Summer time starts on 28 March 2027 in Europe/Brussels.');

        $pdf = $this->export($client, $s, $this->body($s, ['from' => '2027-01-30', 'to' => '2027-02-03']));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=Gardes-Seniors_2027-01_2027-02.pdf');
        self::assertSame(2, $this->pdfPageCount($pdf), 'Four days over two months: two pages.');
    }

    public function testATooLargePdfIsRefusedUpFrontWhileExcelStaysAvailable(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $planning = $this->planning($s['planningId']);
        $planning->extendTo($planning->getStartsAt(), new \DateTimeImmutable('2044-01-01'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertGreaterThan(PlanningExportPdfRenderer::MAX_ROWS, PlanningExportPdfRenderer::rowCount(new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2043-12-31'), 1));
        $response = $this->exportJson($client, $s, $this->body($s));
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('size', $response['violations'], 'One line over 17 years: far too many rows for one PDF.');

        self::assertLessThanOrEqual(PlanningExportPdfRenderer::MAX_ROWS, PlanningExportPdfRenderer::rowCount(new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2030-12-31'), 1));
        $pdf = $this->export($client, $s, $this->body($s, ['to' => '2031-01-01']));
        self::assertResponseIsSuccessful('Four years of one line: accepted, and rendered for real.');
        self::assertSame(48, $this->pdfPageCount($pdf), 'One page per month, no parasitic page.');

        $rows = $this->readXlsx($this->export($client, $s, $this->body($s, ['format' => 'xlsx'])))['Planning'];
        self::assertResponseIsSuccessful();
        self::assertCount(1 + 6209, $rows, 'Excel streams: no size limit.');
    }

    // --- publication PDF vs current export ---------------------------------------------

    public function testTheExportFollowsTheCalendarWhileThePublicationPdfStaysFrozenUntilRepublished(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $published = $this->holderNames($s['planningId']);
        $exported = fn (): array => array_map(
            static fn (array $row): string => $row[2] ?? '',
            \array_slice($this->readXlsx($this->export($client, $s, $this->body($s, ['format' => 'xlsx', 'from' => '2027-01-05', 'to' => '2027-01-08'])))['Planning'], 1),
        );
        $publicationPdf = function () use ($s): array {
            $view = static::getContainer()->get(PlanningPdfRenderer::class)->view($this->latestPublication($s['planningId']));
            $days = array_merge(...array_column($view['weeks'], 'days'));

            return [$days[4]['cells'][0][0]['name'], $days[5]['cells'][0][0]['name'], $days[6]['cells'][0][0]['name']];
        };

        // Publication A → export: the published holders.
        $asPublished = array_map($this->safe(...), array_values($published));
        self::assertSame($asPublished, $exported());
        self::assertSame(array_values($published), $publicationPdf());

        // Reassignment → export: the new holder at once; the publication PDF does not move.
        $holderEmail = $this->currentCalendar($s['planningId'])['0|2027-01-06|ONCALL'];
        $replacement = 'alice@example.com' === $holderEmail ? 'bob@example.com' : 'alice@example.com';
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'), $replacement);
        self::assertResponseIsSuccessful();
        $newName = implode(' ', self::NAMES[$replacement]);
        self::assertSame([$asPublished[0], $this->safe($newName), $asPublished[2]], $exported());
        self::assertSame(array_values($published), $publicationPdf(), 'Frozen: nothing was republished.');

        // Removal → export: uncovered at once; the publication PDF still does not move.
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-07'));
        self::assertResponseIsSuccessful();
        self::assertSame([$asPublished[0], $this->safe($newName), 'Non attribué'], $exported());
        self::assertSame(array_values($published), $publicationPdf());

        // Republication B → the publication PDF is B; the export is still the current calendar.
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/republish", [], $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame([$published['2027-01-05'], $newName, null], $publicationPdf(), 'B, with the removal as "Non attribué".');
        self::assertSame([$asPublished[0], $this->safe($newName), 'Non attribué'], $exported());
        self::assertCount(2, static::getContainer()->get(PlanningPublicationRepository::class)->findByPlanning($this->planning($s['planningId'])), 'A and B — the exports recorded nothing.');
    }

    // --- validation --------------------------------------------------------------------

    public function testInvalidRequestsAreRefusedWithAClearViolation(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        [$seniors] = $this->lineIds($s);

        [$otherPlanning] = $this->createPlanningWithTeam($client, $s['creator'], 'Autre équipe');
        $foreignLine = $this->api($client, 'GET', "/api/plannings/{$otherPlanning}", token: $s['creator'])['lines'][0]['stableId'];

        foreach ([
            'no line selected' => [['lines' => []], 'lines'],
            'lines not a list' => [['lines' => ['a' => 'b']], 'lines'],
            'unknown line' => [['lines' => [['stableId' => '00000000-0000-0000-0000-000000000000', 'label' => 'X']]], 'lines[0].stableId'],
            'line of another planning' => [['lines' => [['stableId' => $foreignLine, 'label' => 'X']]], 'lines[0].stableId'],
            'line twice' => [['lines' => [['stableId' => $seniors, 'label' => 'A'], ['stableId' => $seniors, 'label' => 'B']]], 'lines[1].stableId'],
            'empty label' => [['lines' => [['stableId' => $seniors, 'label' => "  \t "]]], 'lines[0].label'],
            'label too long' => [['lines' => [['stableId' => $seniors, 'label' => str_repeat('x', 81)]]], 'lines[0].label'],
            'redundant position' => [['lines' => [['stableId' => $seniors, 'label' => 'A', 'position' => 0]]], 'lines[0].position'],
            'unknown format' => [['format' => 'csv'], 'format'],
            'no title' => [['title' => '   '], 'title'],
            'title too long' => [['title' => str_repeat('é', 121)], 'title'],
            'unknown field' => [['planningName' => 'X'], 'planningName'],
        ] as $case => [$override, $field]) {
            $response = $this->exportJson($client, $s, $this->body($s, $override));
            self::assertResponseStatusCodeSame(422, $case);
            self::assertArrayHasKey($field, $response['violations'], $case);
        }

        $client->request('POST', "/api/plannings/{$s['planningId']}/export", server: $this->bearer($s['creator']), content: '{not json');
        self::assertResponseStatusCodeSame(400);

        $detail = $this->api($client, 'GET', "/api/plannings/{$otherPlanning}", token: $s['creator']);
        self::assertSame('Autre équipe', $detail['lines'][0]['name'], 'The foreign line was only looked at, never touched.');
    }

    public function testAnInactiveLineCannotBeExported(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $this->addSecondaryLine($client, $s, []);
        [, $juniors] = $this->lineIds($s);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $line = static::getContainer()->get(PlanningLineRepository::class)->findByPlanning($this->planning($s['planningId']))[1];
        $line->setActive(false);
        $em->flush();

        $response = $this->exportJson($client, $s, $this->body($s, ['lines' => [['stableId' => $juniors, 'label' => 'Juniors']]]));
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('lines[0].stableId', $response['violations']);
    }

    // --- access and status -------------------------------------------------------------

    public function testOnlyPeopleWhoCanReadThePlanningCanExportIt(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);

        $this->exportJson($client, $s, $this->body($s), $s['outsider']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', "/api/plannings/{$s['planningId']}/export", server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($this->body($s)));
        self::assertResponseStatusCodeSame(401);

        $client->request('POST', '/api/plannings/00000000-0000-0000-0000-000000000000/export', server: $this->bearer($s['creator']), content: (string) json_encode($this->body($s)));
        self::assertResponseStatusCodeSame(404);
    }

    public function testANeverPublishedPlanningCannotBeExported(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);

        $response = $this->exportJson($client, $s, $this->body($s));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_yet_published', $response['error']);
    }

    // --- helpers -----------------------------------------------------------------------

    /**
     * The pilot scenario with distinctive names (accents, a long name, a
     * formula-like one), three duties generated and published.
     *
     * @return array<string, mixed>
     */
    private function publishedScenario(KernelBrowser $client): array
    {
        $s = $this->pilotScenario($client);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach (self::NAMES as $email => [$first, $last]) {
            $user = $this->userOf($email);
            $user->setFirstName($first);
            $user->setLastName($last);
        }
        $em->flush();

        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();

        return $s;
    }

    /**
     * @param array<string, mixed> $s
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function body(array $s, array $override = []): array
    {
        return $override + [
            'format' => 'pdf',
            'title' => 'Gardes Seniors',
            'lines' => [['stableId' => $this->lineIds($s)[0], 'label' => 'Seniors']],
        ];
    }

    /**
     * @param array<string, mixed> $s
     * @param array<string, mixed> $body
     */
    private function export(KernelBrowser $client, array $s, array $body, ?string $token = null): string
    {
        $client->request('POST', "/api/plannings/{$s['planningId']}/export", server: $this->bearer($token ?? $s['creator']), content: (string) json_encode($body));

        return (string) $client->getResponse()->getContent();
    }

    /**
     * @param array<string, mixed> $s
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function exportJson(KernelBrowser $client, array $s, array $body, ?string $token = null): array
    {
        return json_decode($this->export($client, $s, $body, $token), true) ?? [];
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return list<string> line stable ids, by position
     */
    private function lineIds(array $s): array
    {
        return array_map(
            static fn ($line): string => (string) $line->getStableId(),
            static::getContainer()->get(PlanningLineRepository::class)->findByPlanning($this->planning($s['planningId'])),
        );
    }

    /**
     * Current holder's display name of each duty of the primary line, by date.
     *
     * @return array<string, string>
     */
    private function holderNames(string $planningStableId): array
    {
        $names = [];
        foreach ($this->currentCalendar($planningStableId) as $key => $email) {
            [$line, $date] = explode('|', $key);
            if ('0' === $line && null !== $email) {
                $names[$date] = implode(' ', self::NAMES[$email]);
            }
        }

        return $names;
    }

    /** What a spreadsheet cell holds for $text (formula triggers neutralised). */
    private function safe(string $text): string
    {
        return str_starts_with($text, '=') ? "'".$text : $text;
    }

    private function latestPublication(string $planningStableId): \App\Entity\PlanningPublication
    {
        $publication = static::getContainer()->get(PlanningPublicationRepository::class)->findLatestForPlanning($this->planning($planningStableId));
        self::assertNotNull($publication);

        return $publication;
    }

    private function planning(string $stableId): \App\Entity\Planning
    {
        return static::getContainer()->get(PlanningRepository::class)->findOneByStableId($stableId);
    }
}
