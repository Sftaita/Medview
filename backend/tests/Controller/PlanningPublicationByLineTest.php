<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningLineRepository;
use App\Repository\PlanningPublicationRepository;
use App\Repository\PlanningRepository;
use App\Service\DutyMaterializationService;
use App\Service\PlanningPdfRenderer;
use App\Service\PlanningRuleSetService;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Lot L4c (docs/decisions.md D196): publication by period and by line,
 * through the real endpoints, real OR-Tools generations.
 *
 * Fixture (pilotScenario): planning 2027-01-01 → 2027-05-01, primary line
 * "Seniors" (admin/alice/bob). "Juniors" is a secondary line added by the
 * test (carol, plus alice when a test shares her).
 */
final class PlanningPublicationByLineTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;

    private const SCOPE = ['scope' => 'unlocked_lines'];

    private const STRUCTURE = [
        'blocks' => [['name' => 'Ven · Sam · Dim', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => 'Week End']],
        'solo' => ['LUN', 'MAR', 'MER', 'JEU'],
        'soloFamily' => 'Jours de semaine',
        'excluded' => [],
    ];

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
    }

    // --- helpers ---------------------------------------------------------------------------------

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function lineId(int $index): string
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][$index]['stableId'];
    }

    /** Juniors: carol (and whoever else), its rule set and its own duties when given. */
    private function juniors(array $days = [], array $extraMembers = []): void
    {
        $team = $this->addSecondaryLine($this->client, $this->s, ['carol@example.com']);
        foreach ($extraMembers as $email) {
            $this->addMemberAs($this->client, $this->s['creator'], $this->s['planningId'], $team, $email, 'MEMBER');
        }
        if ([] !== $days) {
            $this->prepareLine($this->s['planningId'], $days, lineIndex: 1);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function preflight(?string $period = null): array
    {
        $preflight = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-preflight".(null !== $period ? "?period={$period}" : ''), token: $this->s['creator']);
        self::assertResponseIsSuccessful();

        return $preflight;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function publish(array $body = [], int $status = 200): array
    {
        $response = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", $body, $this->s['creator']);
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function republish(array $body = [], int $status = 200): array
    {
        $response = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/republish", $body, $this->s['creator']);
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());

        return $response;
    }

    private function periodStatusOf(int $lineIndex, int $cycle = 1): string
    {
        return (string) $this->db()->fetchOne('SELECT pp.status FROM planning_periods pp JOIN planning_lines l ON l.id = pp.planning_line_id JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE l.stable_id = ? AND c.number = ?', [$this->lineId($lineIndex), $cycle]);
    }

    /**
     * @return list<array{line: string, kind: string, duties: int, changed: int}> what the publication recorded per line
     */
    private function publicationLines(string $publicationStableId): array
    {
        return array_map(static fn (array $row): array => ['line' => $row['line_name'], 'kind' => $row['kind'], 'duties' => (int) $row['duty_count'], 'changed' => (int) $row['changed_duty_count']], $this->db()->fetchAllAssociative(
            'SELECT l.line_name, l.kind, l.duty_count, l.changed_duty_count FROM planning_publication_lines l JOIN planning_publications p ON p.id = l.publication_id WHERE p.stable_id = ? ORDER BY l.id',
            [$publicationStableId],
        ));
    }

    /**
     * @return array<string, array{firstLines: ?list<string>, changes: int}> email → what their email says
     */
    private function notifications(string $publicationStableId): array
    {
        $result = [];
        foreach ($this->db()->fetchAllAssociative('SELECT u.email, n.first_lines::text AS first_lines, n.changes::text AS changes FROM planning_publication_notifications n JOIN users u ON u.id = n.user_id JOIN planning_publications p ON p.id = n.publication_id WHERE p.stable_id = ? ORDER BY u.email', [$publicationStableId]) as $row) {
            $result[$row['email']] = [
                'firstLines' => null !== $row['first_lines'] ? json_decode($row['first_lines'], true) : null,
                'changes' => null !== $row['changes'] ? \count(json_decode($row['changes'], true)) : 0,
            ];
        }

        return $result;
    }

    private function publicationCount(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_publications');
    }

    /** Every published record and every assignment of a period: what no later operation may touch. */
    private function cycleFingerprint(int $cycle): string
    {
        return (string) $this->db()->fetchOne(<<<'SQL'
            SELECT md5(string_agg(x, '|' ORDER BY x)) FROM (
              SELECT 'p' || ROW(p.*)::text AS x FROM planning_publications p JOIN planning_cycles c ON c.id = p.planning_cycle_id WHERE c.number = :n
              UNION ALL SELECT 'l' || ROW(l.*)::text FROM planning_publication_lines l JOIN planning_cycles c ON c.id = l.planning_cycle_id WHERE c.number = :n
              UNION ALL SELECT 'e' || ROW(e.*)::text FROM planning_publication_entries e JOIN planning_publications p ON p.id = e.publication_id JOIN planning_cycles c ON c.id = p.planning_cycle_id WHERE c.number = :n
              UNION ALL SELECT 'd' || md5(ROW(dd.*)::text) FROM planning_publication_documents dd JOIN planning_publications p ON p.id = dd.publication_id JOIN planning_cycles c ON c.id = p.planning_cycle_id WHERE c.number = :n
              UNION ALL SELECT 's' || ROW(pp.id, pp.status)::text FROM planning_periods pp JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE c.number = :n
              UNION ALL SELECT 'a' || ROW(a.*)::text FROM duty_assignments a JOIN planning_generations g ON g.id = a.generation_id JOIN planning_periods pp ON pp.id = g.planning_period_id JOIN planning_cycles c ON c.id = pp.planning_cycle_id WHERE c.number = :n
            ) s
        SQL, ['n' => $cycle]);
    }

    /** Seniors and Juniors (carol + alice) each with their own duties, both generated, nothing published. */
    private function twoGeneratedLines(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']]);
        $this->juniors([['2027-01-06', '2027-01-07']], ['alice@example.com']);
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'])['status']);
    }

    /** Seniors published alone (partially published period), Juniors (carol + alice) with a real demand, not generated. */
    private function seniorsPublishedJuniorsDraft(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']]);
        $this->juniors([], ['alice@example.com']);
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'])['status']);
        $this->publish();
        $this->prepareLine($this->s['planningId'], [['2027-01-06', '2027-01-07']], lineIndex: 1);
    }

    private function generateJuniors(): void
    {
        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], self::SCOPE);
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        self::assertSame(['Juniors'], array_column($job['outcome']['lines'], 'lineName'));
    }

    /** Moves Seniors' 5 January duty to someone else: a pending change on a published line. */
    private function changeSeniors(): void
    {
        $duty = $this->dutyOn($this->s['planningId'], '2027-01-05');
        $current = $this->candidatesFor($this->client, $this->s, $duty)['currentTeamMemberStableId'] ?? null;
        foreach (['admin@example.com', 'bob@example.com', 'alice@example.com'] as $email) {
            if ($this->memberStableIdIn($this->s['planningId'], $email) !== $current) {
                $this->reassignTo($this->client, $this->s, $duty, $email);
                self::assertResponseIsSuccessful();

                return;
            }
        }
    }

    // --- 1. the first publication of a period ------------------------------------------------------

    public function testTheFirstPublicationOfAPeriodRecordsEachLineAsFirstForLine(): void
    {
        $this->twoGeneratedLines();

        $outcome = $this->publish();

        self::assertSame('FIRST', $outcome['publication']['kind']);
        self::assertTrue($outcome['publication']['cycleComplete']);
        self::assertSame(1, $outcome['period']['number']);
        self::assertSame([['line' => 'Seniors', 'kind' => 'FIRST_FOR_LINE', 'duties' => 2, 'changed' => 0], ['line' => 'Juniors', 'kind' => 'FIRST_FOR_LINE', 'duties' => 1, 'changed' => 0]], $this->publicationLines($outcome['publication']['stableId']));
        self::assertSame(['FIRST_FOR_LINE', 'FIRST_FOR_LINE'], array_column($outcome['lines'], 'kind'));
        // One email each: alice, on both lines, is told once, of both.
        $notifications = $this->notifications($outcome['publication']['stableId']);
        self::assertSame(['firstLines' => ['Seniors', 'Juniors'], 'changes' => 0], $notifications['alice@example.com']);
        self::assertSame(['firstLines' => ['Juniors'], 'changes' => 0], $notifications['carol@example.com']);
        self::assertSame(['firstLines' => ['Seniors'], 'changes' => 0], $notifications['bob@example.com']);
        self::assertCount(\count($notifications), array_unique(array_keys($notifications)));
    }

    public function testWithoutAListItIsAllOrNothing(): void
    {
        // Seniors generated while Juniors had nothing demanded; Juniors then gets a real demand, still to generate.
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->juniors();
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'])['status']);
        $this->prepareLine($this->s['planningId'], [['2027-01-20', '2027-01-21']], lineIndex: 1);

        $preflight = $this->preflight();
        self::assertFalse($preflight['publishable']);
        self::assertSame([true, false], array_column($preflight['lines'], 'firstPublishable'));
        $refused = $this->publish([], 409);

        self::assertSame('not_publishable', $refused['error']);
        self::assertSame(['NOT_GENERATED'], $refused['preflight']['lines'][1]['blockers'], 'a precise diagnosis');
        self::assertSame(0, $this->publicationCount());
        self::assertSame('GENERATED', $this->periodStatusOf(0));
    }

    // --- 2. a partially published period -----------------------------------------------------------

    public function testAPartialPublicationPublishesOnlyTheChosenLines(): void
    {
        $this->seniorsPublishedJuniorsDraft();
        $publication = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-state", token: $this->s['creator'])['history'][0];

        self::assertSame('FIRST', $publication['kind']);
        self::assertSame([['lineStableId' => $this->lineId(0), 'lineName' => 'Seniors', 'kind' => 'FIRST_FOR_LINE', 'dutyCount' => 2, 'changedDutyCount' => 0, 'reconstructed' => false]], $publication['lines']);
        self::assertTrue($publication['cycleComplete'], 'Juniors had nothing demanded then: it did not count');
        self::assertSame('DRAFT', $this->periodStatusOf(1));
        // Juniors now counts: the period is partially published, and its completeness as recorded is never rewritten.
        $period = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'][0];
        self::assertSame('partially_published', $period['status']);
        self::assertTrue($this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-state", token: $this->s['creator'])['history'][0]['cycleComplete']);
    }

    public function testAnExplicitListPublishesThoseLinesOnlyAndTheCycleIsIncomplete(): void
    {
        $this->twoGeneratedLines();

        $outcome = $this->publish(['lines' => [$this->lineId(0)]]);

        self::assertSame('FIRST', $outcome['publication']['kind']);
        self::assertFalse($outcome['publication']['cycleComplete']);
        self::assertSame([['line' => 'Seniors', 'kind' => 'FIRST_FOR_LINE', 'duties' => 2, 'changed' => 0]], $this->publicationLines($outcome['publication']['stableId']));
        self::assertSame('PUBLISHED', $this->periodStatusOf(0));
        self::assertSame('GENERATED', $this->periodStatusOf(1), 'a line not chosen is left exactly as it is');
        self::assertArrayNotHasKey('carol@example.com', $this->notifications($outcome['publication']['stableId']), 'nobody is told of a line not published');
        // The PDF shows exactly the published line.
        $publication = static::getContainer()->get(PlanningPublicationRepository::class)->findOneByStableId($outcome['publication']['stableId']);
        $view = static::getContainer()->get(PlanningPdfRenderer::class)->view($publication);
        self::assertSame([['name' => 'Seniors']], $view['lines']);
        self::assertSame('Période 1 · du 1er janvier 2027 au 30 avril 2027', $view['periodLabel']);
    }

    public function testANewLineIsLaterPublishedAsFirstForLineWithoutAnyArtificialUpdated(): void
    {
        $this->seniorsPublishedJuniorsDraft();
        $this->generateJuniors();
        $state = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-state", token: $this->s['creator']);
        self::assertFalse($state['hasUnpublishedChanges'], 'a generated line never published is no change of the published ones');
        self::assertSame([$this->lineId(1)], array_column($state['linesAwaitingPublication'], 'lineStableId'));
        $first = static::getContainer()->get(PlanningPublicationRepository::class)->findOneByStableId($state['history'][0]['stableId']);
        $firstPdf = static::getContainer()->get(PlanningPdfRenderer::class)->pdfOf($first)->content;

        $outcome = $this->publish(['lines' => [$this->lineId(1)]]);

        self::assertSame('UPDATE', $outcome['publication']['kind'], 'the period already had its FIRST');
        self::assertTrue($outcome['publication']['cycleComplete']);
        self::assertSame([['line' => 'Juniors', 'kind' => 'FIRST_FOR_LINE', 'duties' => 1, 'changed' => 0]], $this->publicationLines($outcome['publication']['stableId']), 'Seniors unchanged: never recorded as UPDATED');
        self::assertSame(['alice@example.com', 'carol@example.com'], array_keys($this->notifications($outcome['publication']['stableId'])), 'only the new line\'s members');
        // The new PDF shows both lines; the first one is served as it was, byte for byte.
        $latest = static::getContainer()->get(PlanningPublicationRepository::class)->findOneByStableId($outcome['publication']['stableId']);
        self::assertSame([['name' => 'Seniors'], ['name' => 'Juniors']], static::getContainer()->get(PlanningPdfRenderer::class)->view($latest)['lines']);
        $this->client->request('GET', "/api/plannings/{$this->s['planningId']}/publications/{$state['history'][0]['stableId']}/pdf", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->s['alice']]);
        self::assertResponseIsSuccessful();
        self::assertSame($firstPdf, $this->client->getResponse()->getContent());
        self::assertStringContainsString('planning-gardes-seniors-periode-1.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    // --- 3. republication and new lines --------------------------------------------------------

    public function testARepublicationNeverPublishesALineNobodyChose(): void
    {
        $this->seniorsPublishedJuniorsDraft();
        $this->generateJuniors();
        $this->changeSeniors();

        $outcome = $this->republish();

        self::assertSame('UPDATE', $outcome['publication']['kind']);
        self::assertSame([['line' => 'Seniors', 'kind' => 'UPDATED', 'duties' => 2, 'changed' => 1]], $this->publicationLines($outcome['publication']['stableId']));
        self::assertSame('GENERATED', $this->periodStatusOf(1), 'Juniors was not chosen: never published');
        self::assertFalse($outcome['publication']['cycleComplete']);
        self::assertSame([$this->lineId(1)], array_column($this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-state", token: $this->s['creator'])['linesAwaitingPublication'], 'lineStableId'));
    }

    public function testNewLinesAloneAreRefusedWhileThePublishedOnesHaveChangesThenPublishedWithTheRepublication(): void
    {
        $this->seniorsPublishedJuniorsDraft();
        $this->generateJuniors();
        $this->changeSeniors();
        $count = $this->publicationCount();

        $refused = $this->publish(['lines' => [$this->lineId(1)]], 409);
        self::assertSame('republish_required', $refused['error']);
        self::assertSame($count, $this->publicationCount());

        $outcome = $this->republish(['newLines' => [$this->lineId(1)]]);

        self::assertSame([['line' => 'Seniors', 'kind' => 'UPDATED', 'duties' => 2, 'changed' => 1], ['line' => 'Juniors', 'kind' => 'FIRST_FOR_LINE', 'duties' => 1, 'changed' => 0]], $this->publicationLines($outcome['publication']['stableId']));
        self::assertSame(1, $outcome['publication']['changedDutyCount']);
        self::assertTrue($outcome['publication']['cycleComplete']);
        self::assertSame('PUBLISHED', $this->periodStatusOf(1));
        $notifications = $this->notifications($outcome['publication']['stableId']);
        self::assertSame(['Juniors'], $notifications['carol@example.com']['firstLines']);
        // Whoever lost or gained the Seniors duty is told of their change, once — alice also of the new line if she is concerned.
        self::assertSame(2, array_sum(array_map(static fn (array $n): int => $n['changes'], $notifications)));
        self::assertSame(['Juniors'], $notifications['alice@example.com']['firstLines']);
    }

    /** One person, one email: alice, on the new line and concerned by a change of the published one, gets both in one. */
    public function testOnePersonOneEmailWithTheirNewLinesAndTheirChanges(): void
    {
        $this->seniorsPublishedJuniorsDraft();
        $this->generateJuniors();
        $duty = $this->dutyOn($this->s['planningId'], '2027-01-05');
        $alice = $this->memberStableIdIn($this->s['planningId'], 'alice@example.com');
        $this->reassignTo($this->client, $this->s, $duty, $alice === ($this->candidatesFor($this->client, $this->s, $duty)['currentTeamMemberStableId'] ?? null) ? 'bob@example.com' : 'alice@example.com');
        self::assertResponseIsSuccessful();
        $before = \count(self::getMailerMessages());

        $this->republish(['newLines' => [$this->lineId(1)]]);

        $toAlice = array_values(array_filter(\array_slice(self::getMailerMessages(), $before), static fn ($message): bool => 'alice@example.com' === $message->getTo()[0]->getAddress()));
        self::assertCount(1, $toAlice);
        $text = (string) $toAlice[0]->getTextBody();
        self::assertStringStartsWith('Planning de garde disponible — Gardes Seniors — Période 1', (string) $toAlice[0]->getSubject());
        self::assertStringContainsString('Ligne publiée : Juniors.', $text);
        self::assertStringContainsString('SUR UNE AUTRE LIGNE', $text);
        self::assertStringContainsString('Seniors', $text);
        self::assertSame('planning-gardes-seniors-periode-1.pdf', $toAlice[0]->getAttachments()[0]->getFilename());
    }

    public function testTheCombinedOperationIsAtomic(): void
    {
        $this->seniorsPublishedJuniorsDraft();
        // Carol, Juniors' only possible holder that day besides alice, and alice both away: Juniors' duty uncovered.
        $this->declareRange($this->client, $this->loginUser($this->client, 'carol@example.com', 'correct-horse-battery'), '2027-01-06', '2027-01-07');
        $this->declareRange($this->client, $this->s['alice'], '2027-01-06', '2027-01-07');
        $this->generateJuniors();
        $this->changeSeniors();
        $before = $this->cycleFingerprint(1);

        $refused = $this->republish(['newLines' => [$this->lineId(1)]], 409);

        self::assertSame('line_not_publishable', $refused['error']);
        self::assertSame([['lineStableId' => $this->lineId(1), 'lineName' => 'Juniors', 'blockers' => ['UNCOVERED_DUTIES']]], $refused['details']);
        self::assertSame($before, $this->cycleFingerprint(1), 'nothing written: not the Seniors changes either');
        self::assertTrue($this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-state", token: $this->s['creator'])['hasUnpublishedChanges']);
    }

    // --- 4. choosing lines -------------------------------------------------------------------------

    public function testSelectionErrors(): void
    {
        // Seniors generated while Juniors had nothing demanded; Juniors then gets a ponctual duty and no rule set:
        // a real demand, an incomplete configuration (D194).
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->juniors();
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'])['status']);
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $line = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[1];
        $this->createDuty($container->get(DutyMaterializationService::class), $line->requireSingleCyclePeriod(), $this->createDutyType($container->get(EntityManagerInterface::class), $line->getPlanningTeam()), '2027-01-20', '2027-01-21');

        self::assertSame('lines_required', $this->publish(['lines' => []], 422)['error']);
        self::assertSame('invalid_lines', $this->publish(['lines' => 'all'], 422)['error']);
        self::assertSame('invalid_lines', $this->publish(['lines' => [42]], 422)['error']);
        self::assertSame('unknown_line', $this->publish(['lines' => ['01a00000-0000-7000-8000-000000000000']], 422)['error']);
        $incomplete = $this->publish(['lines' => [$this->lineId(1)]], 409);
        self::assertSame('line_not_publishable', $incomplete['error']);
        self::assertSame(['INCOMPLETE', 'NOT_GENERATED'], $incomplete['details'][0]['blockers']);
        self::assertSame(0, $this->publicationCount());

        $this->publish(['lines' => [$this->lineId(0)]]);
        self::assertSame('line_already_published', $this->publish(['lines' => [$this->lineId(0)]], 409)['error']);
        self::assertSame('already_published', $this->publish([], 409)['error'], 'all or nothing on a period already published');
        self::assertSame(1, $this->publicationCount());
    }

    public function testALineWithNothingDemandedIsNeverPublishedNorItsMembersTold(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->juniors();
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);

        $outcome = $this->publish();

        self::assertTrue($outcome['publication']['cycleComplete']);
        self::assertSame(['Seniors'], array_column($this->publicationLines($outcome['publication']['stableId']), 'line'));
        self::assertSame('DRAFT', $this->periodStatusOf(1));
        self::assertArrayNotHasKey('carol@example.com', $this->notifications($outcome['publication']['stableId']));
        self::assertSame('unknown_line', $this->publish(['lines' => [$this->lineId(1)]], 422)['error'], 'a neutral line cannot be chosen');
    }

    // --- 5. periods ----------------------------------------------------------------------------------

    public function testPeriodTwoMayBePublishedBeforePeriodOneWithAWarningAndNeverTouchesIt(): void
    {
        $period1 = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'][0];
        $this->db()->executeStatement("UPDATE availability_collections SET ends_at = '2027-03-01' WHERE stable_id = ?", [$this->s['collectionId']]);
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period1['stableId']}", ['endsAt' => '2027-03-01', 'expectedEndsAt' => $period1['endsAt']], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        $period2 = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", ['endsAt' => '2027-05-01', 'previousPeriodStableId' => $period1['stableId']], $this->s['creator'])['stableId'];
        self::assertResponseStatusCodeSame(201);
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $seniors = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
        $cycle2 = $planning->getCycles()[1];
        $this->createDuty($container->get(DutyMaterializationService::class), $seniors->periodIn($cycle2), $this->createDutyType($container->get(EntityManagerInterface::class), $seniors->getPlanningTeam(), 'P2'), '2027-03-10', '2027-03-11');
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], ['period' => $period1['stableId']]);
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], ['period' => $period2]);
        $period1Before = $this->cycleFingerprint(1);

        $preflight = $this->preflight($period2);
        self::assertTrue($preflight['publishable']);
        self::assertSame([['code' => 'EARLIER_PERIOD_UNPUBLISHED', 'periodNumbers' => [1]]], $preflight['warnings'], 'said, never blocking');
        $outcome = $this->publish(['period' => $period2]);

        self::assertSame('FIRST', $outcome['publication']['kind']);
        self::assertSame(2, $outcome['period']['number']);
        self::assertSame($period1Before, $this->cycleFingerprint(1), 'period 1 untouched');
        self::assertSame('GENERATED', $this->periodStatusOf(0, 1));
        // Period 1 then gets its own FIRST — one per period, never a mix.
        self::assertSame([], $this->preflight($period1['stableId'])['warnings']);
        self::assertSame('FIRST', $this->publish(['period' => $period1['stableId']])['publication']['kind']);
        self::assertSame(1, $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-state?period={$period1['stableId']}", token: $this->s['creator'])['period']['number']);
        self::assertCount(1, $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-state?period={$period2}", token: $this->s['creator'])['history']);
        self::assertSame('period_required', $this->publish([], 422)['error'], 'never a guessed period');
    }

    /**
     * A boundary rest broken by the current calendar (D161/L3: the checks read every period): the night of
     * 30 April (period 1) and the day of 1 May (period 2) held by the same person under an 11-hour rest — the
     * first publication of period 2 is refused with the line's own diagnosis.
     */
    public function testARestBrokenAcrossTheBoundaryBlocksThePublication(): void
    {
        $period1 = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'][0];
        $period2 = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", ['endsAt' => '2027-08-01', 'previousPeriodStableId' => $period1['stableId']], $this->s['creator'])['stableId'];
        self::assertResponseStatusCodeSame(201);
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $seniors = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $seniors->getPlanningTeam());
        $type = $this->createDutyType($em, $seniors->getPlanningTeam());
        $materializer = $container->get(DutyMaterializationService::class);
        $materializer->createStandaloneDuty($seniors->periodIn($planning->getCycles()[0]), $type, new \DateTimeImmutable('2027-04-30 20:00'), new \DateTimeImmutable('2027-05-01 08:00'));
        $materializer->createStandaloneDuty($seniors->periodIn($planning->getCycles()[1]), $type, new \DateTimeImmutable('2027-05-01 08:00'), new \DateTimeImmutable('2027-05-01 20:00'));
        $rest = ['legalMinRestEnabled' => true, 'legalMinRestHours' => 11];
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], ['period' => $period1['stableId']] + $rest);
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], ['period' => $period2] + $rest);
        // The night's holder made to hold the 1 May day too, behind the calendar's back.
        $this->db()->executeStatement(<<<'SQL'
            UPDATE duty_assignments SET team_member_id = (
              SELECT a.team_member_id FROM duty_assignments a JOIN duties d ON d.id = a.duty_id WHERE a.current AND d.local_date = '2027-04-30'
            ) WHERE current AND duty_id = (SELECT id FROM duties WHERE local_date = '2027-05-01')
        SQL);

        $preflight = $this->preflight($period2);
        self::assertFalse($preflight['publishable']);
        self::assertContains('CONFLICTS', $preflight['lines'][0]['blockers']);
        // A commitment of another period is read like one of another line (PersonCommitmentReader, lot L3).
        self::assertSame('CROSS_LINE_LEGAL_MIN_REST', $preflight['conflicts'][0]['reasonCode']);
        self::assertSame('not_publishable', $this->publish(['period' => $period2], 409)['error']);
        self::assertSame(0, $this->publicationCount());
    }

    // --- 6. concurrency and history ----------------------------------------------------------------

    public function testTheDatabaseKeepsEveryPublicationLineHonest(): void
    {
        $this->twoGeneratedLines();
        $outcome = $this->publish(['lines' => [$this->lineId(0)]]);
        $row = $this->db()->fetchAssociative('SELECT l.* FROM planning_publication_lines l JOIN planning_publications p ON p.id = l.publication_id WHERE p.stable_id = ?', [$outcome['publication']['stableId']]);
        self::assertIsArray($row);
        $insert = 'INSERT INTO planning_publication_lines (publication_id, planning_cycle_id, planning_period_id, line_stable_id, line_name, kind, duty_count, changed_duty_count, reconstructed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, FALSE)';
        $otherPeriod = (int) $this->db()->fetchOne('SELECT pp.id FROM planning_periods pp JOIN planning_lines l ON l.id = pp.planning_line_id WHERE l.stable_id = ?', [$this->lineId(1)]);

        foreach ([
            'a second first publication of the same line in the period' => [$row['publication_id'], $row['planning_cycle_id'], $row['planning_period_id'], $row['line_stable_id'], 'Seniors', 'FIRST_FOR_LINE', 2, 0],
            'an artificial UPDATED' => [$row['publication_id'], $row['planning_cycle_id'], $otherPeriod, $row['line_stable_id'], 'Juniors', 'UPDATED', 1, 0],
            'a period of another cycle' => [$row['publication_id'], $row['planning_cycle_id'] + 1000, $otherPeriod, $row['line_stable_id'], 'Juniors', 'FIRST_FOR_LINE', 1, 0],
            'an anonymous line' => [$row['publication_id'], $row['planning_cycle_id'], $otherPeriod, null, null, 'FIRST_FOR_LINE', 1, 0],
        ] as $case => $values) {
            $this->db()->executeStatement('SAVEPOINT l4c');
            try {
                $this->db()->executeStatement($insert, $values);
                self::fail("Accepted: {$case}");
            } catch (DriverException) {
                $this->db()->executeStatement('ROLLBACK TO SAVEPOINT l4c');
            }
        }
        foreach (['UPDATE planning_publication_lines SET duty_count = 0', 'DELETE FROM planning_publication_lines'] as $statement) {
            $this->db()->executeStatement('SAVEPOINT l4c');
            try {
                $this->db()->executeStatement($statement);
                self::fail("Accepted: {$statement}");
            } catch (DriverException $exception) {
                self::assertStringContainsString('append-only', $exception->getMessage());
                $this->db()->executeStatement('ROLLBACK TO SAVEPOINT l4c');
            }
        }
    }

    public function testAPublicationAlreadyRunningIsRefusedAndNothingIsWrittenTwice(): void
    {
        $this->twoGeneratedLines();
        $planningId = (int) $this->db()->fetchOne('SELECT id FROM plannings WHERE stable_id = ?', [$this->s['planningId']]);
        // Another request holds the publication lock of this planning (a separate session).
        $other = \Doctrine\DBAL\DriverManager::getConnection($this->db()->getParams());
        $other->fetchOne('SELECT pg_advisory_lock(7353, ?)', [$planningId]);
        try {
            self::assertSame('publication_in_progress', $this->publish([], 409)['error']);
        } finally {
            $other->fetchOne('SELECT pg_advisory_unlock(7353, ?)', [$planningId]);
            $other->close();
        }
        self::assertSame(0, $this->publicationCount());

        $this->publish();
        self::assertSame('already_published', $this->publish([], 409)['error']);
        self::assertSame('no_changes', $this->republish([], 409)['error']);
        self::assertSame(1, $this->publicationCount());
    }

    /**
     * The preflight that decides is the one read under the CalendarWriteLock (D144, D196): the lock is taken before
     * the publication reads a single assignment. The test's own transaction already holds that lock (dama), so no
     * concurrent session can witness it — the order of the SQL sent does.
     */
    public function testThePublicationReadsTheCalendarOnlyUnderTheCalendarWriteLock(): void
    {
        $this->twoGeneratedLines();
        \App\Tests\SqlRecorder::start();
        try {
            $this->publish();
        } finally {
            $statements = \App\Tests\SqlRecorder::stop();
        }

        $lock = null;
        $firstRead = null;
        foreach ($statements as $index => $sql) {
            if (null === $lock && str_contains($sql, 'pg_advisory_xact_lock')) {
                $lock = $index;
            }
            if (null === $firstRead && preg_match('/\bduty_assignments\b/', $sql)) {
                $firstRead = $index;
            }
        }
        self::assertNotNull($lock, 'The publication takes the CalendarWriteLock.');
        self::assertNotNull($firstRead, 'The publication reads the calendar.');
        self::assertLessThan($firstRead, $lock, 'The calendar is read only once the lock is held.');
        self::assertSame(1, $this->publicationCount());
    }

    // --- 7. Trauma Delta, end to end ---------------------------------------------------------------

    /**
     * Trauma Delta (D192, D194): period 1 ends on Saturday 30 January, its primary line is published, its
     * secondary line active, DRAFT, without structure. Republication of the primary; period 2 from Sunday
     * 31 January with the cut weekend's remainder; its generation and first publication; then the secondary gets
     * a real demand, is generated and first published in period 2 — recorded apart, period 1 strictly unchanged.
     */
    public function testTraumaDelta(): void
    {
        $period = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/periods", token: $this->s['creator'])['periods'][0];
        $this->db()->executeStatement("UPDATE availability_collections SET ends_at = '2027-01-31' WHERE stable_id = ?", [$this->s['collectionId']]);
        $this->api($this->client, 'PATCH', "/api/plannings/{$this->s['planningId']}/periods/{$period['stableId']}", ['endsAt' => '2027-01-31', 'expectedEndsAt' => $period['endsAt']], $this->s['creator']);
        self::assertResponseIsSuccessful();
        $this->api($this->client, 'PUT', "/api/planning-lines/{$this->lineId(0)}/week-structure", self::STRUCTURE, $this->s['creator']);
        self::assertResponseIsSuccessful();
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $container->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningTeam());
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'])['status']);
        $this->juniors([], ['alice@example.com']);

        // Period 1: the primary published, the empty secondary left DRAFT; then a republication of the primary.
        $first = $this->publish();
        self::assertSame([['line' => 'Seniors', 'kind' => 'FIRST_FOR_LINE']], array_map(static fn (array $l): array => ['line' => $l['line'], 'kind' => $l['kind']], $this->publicationLines($first['publication']['stableId'])));
        self::assertSame('DRAFT', $this->periodStatusOf(1));
        $this->unassignDuty($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-01-22'));
        self::assertResponseIsSuccessful();
        $update = $this->republish();
        // The Friday of a Friday → Sunday block: its three days move together.
        self::assertSame([['line' => 'Seniors', 'kind' => 'UPDATED', 'changed' => 3]], array_map(static fn (array $l): array => ['line' => $l['line'], 'kind' => $l['kind'], 'changed' => $l['changed']], $this->publicationLines($update['publication']['stableId'])));
        self::assertFalse($update['publication']['cycleComplete'], 'a duty published uncovered');
        $period1 = $this->cycleFingerprint(1);

        // Period 2, from Sunday 31 January, with the remainder of the cut weekend (D192).
        $p2 = $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/periods", [
            'endsAt' => '2027-04-30', 'previousPeriodStableId' => $period['stableId'], 'acceptHistoricalBlockCuts' => true,
        ], $this->s['creator'])['stableId'];
        self::assertResponseStatusCodeSame(201);
        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], ['period' => $p2]);
        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        self::assertSame(['Seniors'], array_column($job['outcome']['lines'], 'lineName'));
        $p2First = $this->publish(['period' => $p2]);
        self::assertSame('FIRST', $p2First['publication']['kind']);
        self::assertSame(2, $p2First['period']['number']);
        self::assertSame(['Seniors'], array_column($this->publicationLines($p2First['publication']['stableId']), 'line'));
        self::assertSame('2027-01-31', (string) $this->db()->fetchOne(<<<'SQL'
            SELECT MIN(d.local_date)::text FROM planning_publication_entries e JOIN duties d ON d.id = e.duty_id
            JOIN planning_publications p ON p.id = e.publication_id WHERE p.stable_id = ?
        SQL, [$p2First['publication']['stableId']]), 'the remainder of the cut weekend is published in period 2');
        $document = static::getContainer()->get(PlanningPdfRenderer::class)->pdfOf(static::getContainer()->get(PlanningPublicationRepository::class)->findOneByStableId($p2First['publication']['stableId']));
        self::assertSame('planning-gardes-seniors-periode-2.pdf', $document->filename);
        self::assertSame(['2027-01-31', '2027-04-29'], [$document->firstDay->format('Y-m-d'), $document->lastDay->format('Y-m-d')]);

        // The secondary gets a real demand in period 2: generated, then first published there, recorded apart.
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $juniors = $container->get(PlanningLineRepository::class)->findByPlanning($planning)[1];
        $this->activateRuleSet($container->get(PlanningRuleSetService::class), $juniors->getPlanningTeam());
        $this->createDuty($container->get(DutyMaterializationService::class), $juniors->periodIn($planning->getCycles()[1]), $this->createDutyType($container->get(EntityManagerInterface::class), $juniors->getPlanningTeam()), '2027-02-10', '2027-02-11');
        $job = $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'], ['period' => $p2] + self::SCOPE);
        self::assertSame(['Juniors'], array_column($job['outcome']['lines'], 'lineName'));
        $juniorsFirst = $this->publish(['period' => $p2, 'lines' => [$this->lineId(1)]]);

        self::assertSame('UPDATE', $juniorsFirst['publication']['kind']);
        self::assertSame([['line' => 'Juniors', 'kind' => 'FIRST_FOR_LINE']], array_map(static fn (array $l): array => ['line' => $l['line'], 'kind' => $l['kind']], $this->publicationLines($juniorsFirst['publication']['stableId'])));
        self::assertTrue($juniorsFirst['publication']['cycleComplete']);
        self::assertSame('DRAFT', $this->periodStatusOf(1, 1), 'still DRAFT in period 1, where nothing is demanded of it');
        self::assertSame($period1, $this->cycleFingerprint(1), 'period 1: publications, documents, statuses and assignments unchanged');
        self::assertSame(['FIRST', 'UPDATE'], array_column($this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-state?period={$p2}", token: $this->s['creator'])['history'], 'kind'), 'period 2 has its own history');
    }
}
