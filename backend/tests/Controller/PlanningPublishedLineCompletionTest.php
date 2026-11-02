<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Service\PlanningCompletionService;
use App\Tests\FaultInjectingPlanningSolver;
use App\Tests\LineFillTestHelpers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D223 — the legacy "Compléter automatiquement" (POST
 * /complete, PlanningCompletionService) never completes a published,
 * validated or archived line: refused by the API when every generated line
 * of the period is one, reported as left alone otherwise, and caught under
 * the calendar lock when the line is published during the computation.
 * Manual changes after publication stay possible, with their history and
 * the explicit republication. Real OR-Tools solves.
 */
final class PlanningPublishedLineCompletionTest extends WebTestCase
{
    use LineFillTestHelpers;

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
    }

    protected function tearDown(): void
    {
        FaultInjectingPlanningSolver::reset();
        parent::tearDown();
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /**
     * Seniors generated and published, then one of its duties removed (an unpublished change); the Juniors exist
     * (generated or not, as asked).
     */
    private function publishedSeniorsWithAHole(bool $generateJuniors): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 4));
        $this->addSecondaryLine($this->client, $this->s, ['junior1@example.com', 'junior2@example.com']);
        $this->prepareLine($this->s['planningId'], [['2027-01-04 21:00', '2027-01-05 07:00'], ['2027-01-05 21:00', '2027-01-06 07:00']], lineIndex: 1);
        [$seniors, $juniors] = $this->lineIds($this->client, $this->s);
        self::assertSame('done', $this->fillLines($this->fillNow($this->client, $this->s, $generateJuniors ? [$seniors, $juniors] : [$seniors]))['Seniors']['status']);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", ['lines' => [$seniors]], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame('PUBLISHED', $this->lineEntity(0)->requireSingleCyclePeriod()->getStatus()->value);
        $this->unassignDuty($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-01-05'));
        self::assertResponseIsSuccessful();
    }

    private function lineEntity(int $index): \App\Entity\PlanningLine
    {
        $container = static::getContainer();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);

        return $container->get(PlanningLineRepository::class)->findByPlanning($planning)[$index];
    }

    /** @param array<string, array<int, string>> $before */
    private function assertNothingPublishedSentOrWritten(array $before): void
    {
        $this->assertNoExistingRowChanged($before, 'A refused completion changes nothing.');
        foreach (['duty_assignments', 'duty_assignment_events', 'planning_generations', 'planning_publications', 'planning_publication_documents', 'planning_publication_notifications', 'planning_publication_deliveries'] as $table) {
            self::assertSame(0, $this->newRowCount($before, $table), "Nothing new in {$table}.");
        }
    }

    /** @return array<string, mixed> */
    private function publicationState(): array
    {
        return $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-state", token: $this->s['creator']);
    }

    /**
     * The only generated line is published: the API refuses before queuing
     * anything, says which line and why, and nothing — assignment, history,
     * publication, stored PDF, notification — is touched. The stored PDF of
     * the diffusion still serves the very same bytes.
     */
    public function testTheApiRefusesToCompleteWhenEveryGeneratedLineIsPublished(): void
    {
        $this->publishedSeniorsWithAHole(generateJuniors: false);
        $pdf = $this->storedPdf();
        $jobs = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_jobs');
        $messages = $this->queuedPlanningJobMessageCount();
        $before = $this->protectedRows();

        $refusal = $this->completePlanning($this->client, $this->s);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('published_lines_not_completed', $refusal['error']);
        self::assertSame(['Seniors'], array_column($refusal['lines'], 'lineName'));
        self::assertSame($jobs, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM planning_jobs'), 'No job queued.');
        self::assertSame($messages, $this->queuedPlanningJobMessageCount(), 'No message sent to the worker.');
        self::assertEmailCount(0);
        self::assertNull($this->currentCalendar($this->s['planningId'])['0|2027-01-05|ONCALL'], 'The hole is still a hole.');
        $this->assertNothingPublishedSentOrWritten($before);
        self::assertSame($pdf, $this->storedPdf(), 'The stored PDF serves the same bytes.');

        // An ADMIN of the team — the other role allowed to manage the calendar — is refused the same way.
        $this->completePlanning($this->client, $this->s, $this->s['admin']);
        self::assertResponseStatusCodeSame(409);
        $this->assertNothingPublishedSentOrWritten($before);
    }

    /**
     * The manual workflow after publication is unchanged: the hole is given
     * to someone by hand, the change is recorded (event marked published) and
     * shown as an unpublished change, to be republished explicitly — nothing
     * is republished nor sent by itself.
     */
    public function testAManualReassignmentOfAPublishedLineStaysPossibleAndIsRecorded(): void
    {
        $this->publishedSeniorsWithAHole(generateJuniors: false);
        $this->completePlanning($this->client, $this->s);
        self::assertResponseStatusCodeSame(409);
        $before = $this->protectedRows();

        $this->reassignTo($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-01-05'), 'alice@example.com');

        self::assertResponseIsSuccessful();
        self::assertSame('alice@example.com', $this->holderEmailOn('2027-01-05'));
        $this->assertNoExistingRowChanged($before, 'A manual reassignment of a hole only adds rows.');
        self::assertSame(1, $this->newRowCount($before, 'duty_assignments'));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM duty_assignment_events WHERE id > ? AND was_published', [max(array_keys($before['duty_assignment_events']))]), 'One recorded change, marked as made after publication.');
        self::assertTrue($this->publicationState()['hasUnpublishedChanges'], 'Shown as an unpublished change.');
        foreach (['planning_publications', 'planning_publication_documents', 'planning_publication_notifications', 'planning_publication_deliveries'] as $table) {
            self::assertSame(0, $this->newRowCount($before, $table), "Nothing republished by itself: nothing new in {$table}.");
        }

        // And republished explicitly, as before.
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/republish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->publicationState()['hasUnpublishedChanges']);
    }

    /**
     * A published line next to a draft one: the legacy completion runs, fills
     * the draft line's hole, reports the published line as left alone and
     * never touches it — the published line blocks nothing.
     */
    public function testTheLegacyCompletionFillsTheDraftLineAndLeavesThePublishedOneAlone(): void
    {
        $this->publishedSeniorsWithAHole(generateJuniors: true);
        $this->unassignDuty($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-01-05', 1));
        self::assertResponseIsSuccessful();
        $before = $this->protectedRows();

        $outcome = $this->completePlanning($this->client, $this->s);

        self::assertSame('SUCCEEDED', $outcome['job']['status']);
        $lines = array_column($outcome['lines'], null, 'lineName');
        self::assertSame(['published', 0, 0], [$lines['Seniors']['status'], $lines['Seniors']['holeCount'], $lines['Seniors']['filledUnitCount']]);
        self::assertSame(['completed', 1], [$lines['Juniors']['status'], $lines['Juniors']['filledUnitCount']]);
        self::assertNull($this->currentCalendar($this->s['planningId'])['0|2027-01-05|ONCALL'], 'The published line was not completed.');
        $this->assertNoExistingRowChanged($before);
        self::assertSame(1, $this->newRowCount($before, 'duty_assignments'), 'Only the draft line\'s hole was filled.');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM duty_assignment_events WHERE id > ? AND was_published', [max(array_keys($before['duty_assignment_events']))]));
        foreach (['planning_publications', 'planning_publication_documents', 'planning_publication_notifications', 'planning_publication_deliveries'] as $table) {
            self::assertSame(0, $this->newRowCount($before, $table), "Nothing new in {$table}.");
        }
    }

    /**
     * The draft line is published while its completion is being computed:
     * the status is read again under the calendar lock, the completion is
     * stale, nothing is written.
     */
    public function testALinePublishedDuringTheComputationIsNeverCompleted(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 3));
        $this->generateNow($this->client, $this->s['planningId'], $this->s['creator']);
        $this->unassignDuty($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-01-05'));
        $periodId = (int) $this->lineEntity(0)->requireSingleCyclePeriod()->getId();
        FaultInjectingPlanningSolver::$duringSolve = function () use ($periodId): void {
            // Stands for a publication committed by another request meanwhile (the status is all the check reads).
            $this->db()->executeStatement("UPDATE planning_periods SET status = 'PUBLISHED' WHERE id = ?", [$periodId]);
        };
        $before = $this->protectedRows();

        $outcome = $this->completePlanning($this->client, $this->s);

        self::assertSame(['FAILED', 'calendar_changed'], [$outcome['job']['status'], $outcome['job']['failureCode']]);
        self::assertNull($this->currentCalendar($this->s['planningId'])['0|2027-01-05|ONCALL']);
        self::assertSame(0, $this->newRowCount($before, 'duty_assignments'));
        self::assertSame(0, $this->newRowCount($before, 'duty_assignment_events'));
    }

    /** Every locked status, one by one, as the service itself sees it. */
    public function testEveryLockedStatusIsNeverCompletedAndTheOthersAre(): void
    {
        $this->prepareLine($this->s['planningId'], self::days('2027-01-04', 2));
        $line = $this->lineEntity(0);
        $cycle = $line->requireSingleCyclePeriod()->getCycle();
        $expected = ['DRAFT' => null, 'GENERATED' => null, 'VALIDATED' => 'published', 'PUBLISHED' => 'published', 'ARCHIVED' => 'archived'];
        foreach ($expected as $status => $reason) {
            $this->db()->executeStatement('UPDATE planning_periods SET status = ? WHERE id = ?', [$status, $line->requireSingleCyclePeriod()->getId()]);
            static::getContainer()->get('doctrine')->getManager()->refresh($line->requireSingleCyclePeriod());
            self::assertSame($reason, PlanningCompletionService::lockedReason($line, $cycle), $status);
        }
    }

    private function storedPdf(): string
    {
        $this->client->request('GET', "/api/plannings/{$this->s['planningId']}/publication.pdf", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->s['creator']]);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getInternalResponse()->getContent();
    }

    private function holderEmailOn(string $date, int $lineIndex = 0): ?string
    {
        $holder = $this->db()->fetchOne(
            'SELECT u.email FROM duty_assignments a JOIN duties d ON d.id = a.duty_id JOIN planning_team_members tm ON tm.id = a.team_member_id JOIN users u ON u.id = tm.user_id WHERE a.current AND d.stable_id = ?',
            [$this->dutyOn($this->s['planningId'], $date, $lineIndex)],
        );

        return false === $holder ? null : (string) $holder;
    }
}
