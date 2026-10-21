<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PlanningPublication;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningPublicationRepository;
use App\Repository\PlanningRepository;
use App\Service\DutyMaterializationService;
use App\Service\FrenchDate;
use App\Service\PlanningPdfRenderer;
use App\Service\PublishedDocument;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D206: the PDF of a publication is always on the days of
 * its own period — a publication recorded before D173 (no stored PDF) is
 * rendered again from its frozen entries, and the planning's dates grow with
 * every following period; a stored PDF is served as it is.
 *
 * Fixture (pilotScenario): planning 2027-01-01 → 2027-05-01, primary line
 * "Seniors". Period 1 is shortened to 2027-01-01 → 2027-03-01 (last day
 * 28 February), period 2 is then created up to 2027-05-01 — the production
 * case of Trauma Delta, where period 2 made the planning longer.
 */
final class PlanningLegacyPublicationPdfTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    private string $period1;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
        $period = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'][0];
        $this->db()->executeStatement("UPDATE availability_collections SET ends_at = '2027-03-01' WHERE stable_id = ?", [$this->s['collectionId']]);
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period['stableId']}", ['endsAt' => '2027-03-01', 'expectedEndsAt' => $period['endsAt']], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $this->period1 = $period['stableId'];
    }

    // --- helpers ---------------------------------------------------------------------------------

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function renderer(): PlanningPdfRenderer
    {
        return static::getContainer()->get(PlanningPdfRenderer::class);
    }

    private function publication(string $stableId): PlanningPublication
    {
        return static::getContainer()->get(PlanningPublicationRepository::class)->findOneByStableId($stableId);
    }

    /** Seniors generated and published in period 1: its FIRST, stableId returned. */
    private function publishSeniorsInPeriodOne(): string
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-02-23', '2027-02-24']]);
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'])['status']);
        $outcome = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();

        return $outcome['publication']['stableId'];
    }

    /** What a publication recorded before D173 looks like: its entries, no stored PDF. */
    private function withoutStoredDocument(string $publicationStableId): void
    {
        $db = $this->db();
        $db->executeStatement('ALTER TABLE planning_publication_documents DISABLE TRIGGER trg_planning_publication_documents_append_only');
        self::assertSame(1, $db->executeStatement('DELETE FROM planning_publication_documents WHERE publication_id = (SELECT id FROM planning_publications WHERE stable_id = ?)', [$publicationStableId]));
        $db->executeStatement('ALTER TABLE planning_publication_documents ENABLE TRIGGER trg_planning_publication_documents_append_only');
    }

    /** Period 2, 2027-03-01 → 2027-05-01: the planning now ends two months later. */
    private function createPeriodTwo(): string
    {
        $period2 = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", ['endsAt' => '2027-05-01', 'previousPeriodStableId' => $this->period1], $this->s['creator'])['stableId'];
        self::assertResponseStatusCodeSame(201);
        self::assertSame('2027-05-01', $this->db()->fetchOne('SELECT ends_at::text FROM plannings WHERE stable_id = ?', [$this->s['planningId']]));

        return $period2;
    }

    /**
     * Every day a PDF view shows, in order.
     *
     * @param array<string, mixed> $view
     *
     * @return list<string>
     */
    private static function days(array $view): array
    {
        return array_merge(...array_map(static fn (array $week): array => array_column($week['days'], 'label'), $view['weeks']));
    }

    /**
     * The French short labels of every day from $first to $last (inclusive).
     *
     * @return list<string>
     */
    private static function labels(string $first, string $last): array
    {
        $labels = [];
        for ($day = new \DateTimeImmutable($first); $day <= new \DateTimeImmutable($last); $day = $day->modify('+1 day')) {
            $labels[] = FrenchDate::short($day);
        }

        return $labels;
    }

    /**
     * The people shown in a PDF view, day by day ("label: name, …").
     *
     * @param array<string, mixed> $view
     *
     * @return list<string>
     */
    private static function holders(array $view): array
    {
        $holders = [];
        foreach ($view['weeks'] as $week) {
            foreach ($week['days'] as $day) {
                foreach ($day['cells'] as $column => $items) {
                    foreach ($items as $item) {
                        $holders[] = $day['label'].' #'.$column.': '.($item['name'] ?? '—');
                    }
                }
            }
        }

        return $holders;
    }

    private static function assertDays(PublishedDocument $document, string $first, string $last): void
    {
        self::assertSame([$first, $last], [$document->firstDay->format('Y-m-d'), $document->lastDay->format('Y-m-d')]);
    }

    // --- 1. a publication without stored PDF, before and after the planning grows -----------------

    public function testALegacyPublicationStaysOnItsPeriodWhenAFollowingPeriodMakesThePlanningLonger(): void
    {
        $publication = $this->publishSeniorsInPeriodOne();
        $this->withoutStoredDocument($publication);
        $before = $this->renderer()->legacyView($this->publication($publication));
        $entries = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_publication_entries e JOIN planning_publications p ON p.id = e.publication_id WHERE p.stable_id = ?', [$publication]);
        self::assertSame(self::labels('2027-01-01', '2027-02-28'), self::days($before));
        self::assertCount($entries, self::holders($before), 'one name per frozen entry');

        $this->createPeriodTwo();
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $document = $this->renderer()->pdfOf($this->publication($publication));
        $after = $this->renderer()->legacyView($this->publication($publication));

        self::assertDays($document, '2027-01-01', '2027-02-28');
        self::assertSame(self::labels('2027-01-01', '2027-02-28'), self::days($after), 'never a day of period 2');
        self::assertSame('Du '.FrenchDate::long(new \DateTimeImmutable('2027-01-01')).' au '.FrenchDate::long(new \DateTimeImmutable('2027-02-28')), $after['periodLabel']);
        self::assertSame(self::holders($before), self::holders($after), 'the same people on the same days, read from the frozen entries');
        self::assertSame($before['lines'], $after['lines']);
        self::assertSame('planning-gardes-seniors.pdf', $document->filename, 'the name it always had');
        self::assertSame(1, $document->periodNumber);
        self::assertStringStartsWith('%PDF', $document->content);

        // The download serves that rendering, under the same dates.
        $this->client->request('GET', "/api/plannings/{$this->s['planningId']}/publications/{$publication}/pdf", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->s['alice']]);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('planning-gardes-seniors.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    // --- 2. a stored PDF is served as it is ------------------------------------------------------------

    public function testAStoredDocumentIsServedByteForByteAfterThePlanningGrows(): void
    {
        $publication = $this->publishSeniorsInPeriodOne();
        $stored = $this->renderer()->pdfOf($this->publication($publication));
        $row = $this->db()->fetchAssociative('SELECT sha256, filename, period_first_day::text AS first, period_last_day::text AS last FROM planning_publication_documents d JOIN planning_publications p ON p.id = d.publication_id WHERE p.stable_id = ?', [$publication]);

        $this->createPeriodTwo();
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $served = $this->renderer()->pdfOf($this->publication($publication));

        self::assertSame($stored->content, $served->content, 'the stored bytes, never rendered again');
        self::assertSame($row['sha256'], hash('sha256', $served->content));
        self::assertSame([$row['filename'], $row['first'], $row['last']], [$served->filename, $served->firstDay->format('Y-m-d'), $served->lastDay->format('Y-m-d')]);
        self::assertSame(['planning-gardes-seniors-periode-1.pdf', '2027-01-01', '2027-02-28'], [$served->filename, $row['first'], $row['last']]);
        $this->client->request('GET', "/api/plannings/{$this->s['planningId']}/publications/{$publication}/pdf", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->s['alice']]);
        self::assertResponseIsSuccessful();
        self::assertSame($stored->content, $this->client->getResponse()->getContent());
    }

    // --- 3. successive publications on two periods -----------------------------------------------------

    public function testSuccessivePublicationsOnTwoPeriodsEachStayOnTheirOwnDays(): void
    {
        $legacy = $this->publishSeniorsInPeriodOne();
        $this->withoutStoredDocument($legacy);
        $period2 = $this->createPeriodTwo();
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $seniors = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
        $this->createDuty($container->get(DutyMaterializationService::class), $seniors->periodIn($planning->getCycles()[1]), $this->createDutyType($container->get(EntityManagerInterface::class), $seniors->getPlanningTeam(), 'P2'), '2027-03-10', '2027-03-11');
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], ['period' => $period2])['status']);
        $second = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", ['period' => $period2], $this->s['creator'])['publication']['stableId'];
        self::assertResponseIsSuccessful();
        $container->get(EntityManagerInterface::class)->clear();

        $first = $this->renderer()->pdfOf($this->publication($legacy));
        self::assertDays($first, '2027-01-01', '2027-02-28');
        self::assertSame(self::labels('2027-01-01', '2027-02-28'), self::days($this->renderer()->legacyView($this->publication($legacy))));
        self::assertNotContains(FrenchDate::short(new \DateTimeImmutable('2027-03-10')), self::days($this->renderer()->legacyView($this->publication($legacy))), 'the duty of period 2 is never in the PDF of period 1');

        $next = $this->renderer()->pdfOf($this->publication($second));
        self::assertDays($next, '2027-03-01', '2027-04-30');
        self::assertSame('planning-gardes-seniors-periode-2.pdf', $next->filename);
        $view = $this->renderer()->view($this->publication($second));
        self::assertSame(self::labels('2027-03-01', '2027-04-30'), self::days($view));
        self::assertCount(1, self::holders($view), 'only the duty of period 2');
    }

    // --- 4. a partially published period ---------------------------------------------------------------

    public function testALegacyPublicationOfAPartiallyPublishedPeriodShowsOnlyItsLinesOnItsDays(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->addSecondaryLine($this->client, $this->s, ['carol@example.com']);
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'])['status']);
        $publication = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator'])['publication']['stableId'];
        self::assertResponseIsSuccessful();
        // Juniors gets a demand afterwards: the period is now only partly published.
        $this->prepareLine($this->s['planningId'], [['2027-01-07', '2027-01-08']], lineIndex: 1);
        $this->withoutStoredDocument($publication);
        $this->createPeriodTwo();
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $view = $this->renderer()->legacyView($this->publication($publication));

        self::assertSame([['name' => 'Seniors']], $view['lines'], 'the lines of its entries only');
        self::assertSame(self::labels('2027-01-01', '2027-02-28'), self::days($view));
        self::assertDays($this->renderer()->pdfOf($this->publication($publication)), '2027-01-01', '2027-02-28');
    }
}
