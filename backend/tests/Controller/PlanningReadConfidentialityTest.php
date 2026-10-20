<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\PlanningCycleTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Who reads which lines of the calendar (lot L7, docs/decisions.md D204),
 * through the real endpoints `/result` and `/assignments`: a manager every
 * line, a plain member the lines published in the period shown — never a
 * draft, not even its existence — and an outsider nothing.
 *
 * Fixture (pilotScenario): planning 2027-01-01 → 2027-05-01, primary line
 * "Seniors" with admin (ADMIN — manages the calendar), alice and bob
 * (MEMBER); "Juniors" with carol and alice when added.
 */
final class PlanningReadConfidentialityTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use PlanningCycleTestHelpers;

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path, string $who): array
    {
        $body = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/{$path}", token: $this->s[$who]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $body;
    }

    private function raw(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /** Seniors and Juniors generated, Seniors alone published: a partly published period. */
    private function seniorsPublishedJuniorsGenerated(): string
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']]);
        $team = $this->addSecondaryLine($this->client, $this->s, ['carol@example.com']);
        $this->addMemberAs($this->client, $this->s['creator'], $this->s['planningId'], $team, 'alice@example.com', 'MEMBER');
        $this->prepareLine($this->s['planningId'], [['2027-01-06', '2027-01-07'], ['2027-01-20', '2027-01-21']], lineIndex: 1);
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'])['status']);
        $lines = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'];
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", ['lines' => [$lines[0]['stableId']]], $this->s['creator']);
        self::assertResponseIsSuccessful($this->raw());

        return $lines[1]['stableId'];
    }

    public function testAPlainMemberReadsThePublishedLinesOfAPartlyPublishedPeriodNeverTheDraftOne(): void
    {
        $juniors = $this->seniorsPublishedJuniorsGenerated();

        $result = $this->read('result', 'alice');
        self::assertSame(['Seniors'], array_column($result['lines'], 'lineName'));
        self::assertStringNotContainsString($juniors, $this->raw(), 'nothing of the draft line, not even its identifier');
        self::assertStringNotContainsString('2027-01-20', $this->raw());

        $assignments = $this->read('assignments', 'alice');
        self::assertSame(['Seniors'], array_column($assignments['generations'], 'lineName'));
        self::assertSame(['Seniors'], array_values(array_unique(array_column($assignments['assignments'], 'lineName'))));
        self::assertStringNotContainsString($juniors, $this->raw());
        // Alice holds Juniors duties too: her own view does not show them before they are published.
        $own = $this->read("assignments?userStableId={$this->userStableId('alice@example.com')}", 'alice');
        self::assertNotContains('Juniors', array_column($own['assignments'], 'lineName'));

        // The manager prepares it: both lines.
        self::assertSame(['Seniors', 'Juniors'], array_column($this->read('result', 'admin')['lines'], 'lineName'));
        self::assertSame(['Seniors', 'Juniors'], array_column($this->read('assignments', 'admin')['generations'], 'lineName'));

        // Published, Juniors appears for the member too.
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/republish", ['newLines' => [$juniors]], $this->s['creator']);
        self::assertResponseIsSuccessful($this->raw());
        self::assertSame(['Seniors', 'Juniors'], array_column($this->read('result', 'alice')['lines'], 'lineName'));
    }

    public function testASinglePeriodPlanningShowsAMemberNothingBeforeItsPublicationAndItsLiveCalendarAfter(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']]);
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'])['status']);

        self::assertSame([], $this->read('result', 'bob')['lines']);
        self::assertSame([], $this->read('assignments', 'bob')['assignments']);
        self::assertCount(1, $this->read('result', 'creator')['lines']);

        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful($this->raw());
        $duty = $this->read('result', 'bob')['lines'][0]['duties'][0];
        self::assertNotNull($duty['assignment']);

        // A reassignment after publication is part of the operational calendar the member reads (D168).
        $candidates = $this->read("duties/{$duty['dutyStableId']}/reassignment-candidates", 'creator');
        $target = $candidates['candidates'][0];
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/duties/{$duty['dutyStableId']}/reassign", [
            'teamMemberStableId' => $target['teamMemberStableId'],
            'expectedCurrentTeamMemberStableId' => $candidates['currentTeamMemberStableId'],
        ], $this->s['creator']);
        self::assertResponseIsSuccessful($this->raw());
        $after = $this->read('result', 'bob')['lines'][0]['duties'][0];
        self::assertSame($target['firstName'], $after['assignment']['user']['firstName'], 'not republished yet, already read');
    }

    public function testAnEarlierPublishedPeriodStaysReadableAndALaterDraftPeriodShowsNothing(): void
    {
        $this->addDuties(1, [['2027-01-05 08:00', '2027-01-05 20:00']]);
        $this->createPeriod('2027-08-01');
        $this->addDuties(2, [['2027-05-04 08:00', '2027-05-04 20:00']]);
        $this->generatePeriod(1);
        $this->publishPeriod(1);
        $this->generatePeriod(2);

        $one = $this->read("result?period={$this->periodId(1)}", 'alice');
        self::assertSame([1, ['Seniors']], [$one['period']['number'], array_column($one['lines'], 'lineName')]);
        $two = $this->read("result?period={$this->periodId(2)}", 'alice');
        self::assertSame([2, []], [$two['period']['number'], $two['lines']]);
        self::assertSame([], $this->read("assignments?period={$this->periodId(2)}", 'alice')['assignments']);
        self::assertCount(1, $this->read("result?period={$this->periodId(2)}", 'admin')['lines']);
    }

    public function testAnUnpublishedDutyAndAnUnknownOneGetTheSameAnswer(): void
    {
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06']]);
        self::assertSame('SUCCEEDED', $this->generateNow($this->client, $this->s['planningId'], $this->s['creator'])['status']);
        $draft = $this->read('result', 'creator')['lines'][0]['duties'][0]['dutyStableId'];
        $holder = $this->read('result', 'creator')['lines'][0]['duties'][0]['assignment']['user']['stableId'];
        $who = 'alice';
        foreach (['alice', 'bob', 'admin'] as $key) {
            if ($this->userStableId("{$key}@example.com") === $holder) {
                $who = $key;
            }
        }

        $this->api($this->client, 'GET', "/api/duty-swaps/options?dutyStableId={$draft}", token: $this->s[$who]);
        self::assertResponseStatusCodeSame(404);
        $unpublished = $this->raw();
        $this->api($this->client, 'GET', '/api/duty-swaps/options?dutyStableId=01a00000-0000-7000-8000-000000000000', token: $this->s[$who]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($unpublished, $this->raw(), 'the holder of a draft duty cannot tell it from an unknown one');

        // Published: someone outside the line cannot tell a real duty from an unknown one either.
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful($this->raw());
        $this->api($this->client, 'GET', "/api/duty-swaps/options?dutyStableId={$draft}", token: $this->s['outsider']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($unpublished, $this->raw());
    }

    public function testAnOutsiderIsRefusedWithoutAnyData(): void
    {
        $this->seniorsPublishedJuniorsGenerated();

        foreach (['result', 'assignments', "result?period={$this->periodId(1)}"] as $path) {
            $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/{$path}", token: $this->s['outsider']);
            self::assertResponseStatusCodeSame(403);
            self::assertStringNotContainsString('Seniors', $this->raw());
            self::assertStringNotContainsString('2027-01', $this->raw());
        }
    }

    private function userStableId(string $email): string
    {
        return (string) $this->db()->fetchOne('SELECT stable_id FROM users WHERE email = ?', [$email]);
    }
}
