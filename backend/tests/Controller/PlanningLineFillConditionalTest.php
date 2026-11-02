<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\ConditionalLineTestHelpers;
use App\Tests\LineFillTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/decisions.md D222 — a reinforcement line and its source line, both
 * never generated, given their first generation in the same run of
 * "Générer les postes non pourvus". The source is generated first; the
 * reinforcement's demand is then read on the holders just written — never
 * guessed — and nobody is put on two lines at once. Real OR-Tools solves.
 *
 * The pilot main line (admin, alice, bob) and "Renfort", whose members are
 * carol and alice: alice may hold the source duty AND be a reinforcement
 * candidate the same day — the case where a double assignment could slip in.
 * Triggers: alice every day, bob Friday to Sunday.
 */
final class PlanningLineFillConditionalTest extends WebTestCase
{
    use LineFillTestHelpers;
    use ConditionalLineTestHelpers;

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->s = $this->scenario($this->client, renfortPeople: ['carol@example.com', 'alice@example.com']);
    }

    /** @return array<string, ?string> main-line holder by date */
    private function primaryCalendar(): array
    {
        $calendar = [];
        foreach ($this->currentCalendar($this->s['planningId']) as $key => $holder) {
            [$lineIndex, $date] = explode('|', $key);
            if ('0' === $lineIndex) {
                $calendar[$date] = $holder;
            }
        }

        return $calendar;
    }

    /**
     * The invariants of a run that generated both lines, whatever the
     * solver chose: one holder per duty, nobody on both lines the same day,
     * no reinforcement held where none is needed, the demand exactly what
     * the main line's holders trigger, and nothing the publication check
     * would reject.
     */
    private function assertCoherentAcrossLines(): void
    {
        self::assertSame(0, $this->duplicateCurrentAssignments(), 'One current holder per duty.');
        $primary = $this->primaryCalendar();
        $states = $this->renfortStates($this->client, $this->s);
        foreach ($this->renfortCalendar($this->s) as $date => $holder) {
            if (null !== $holder) {
                self::assertNotSame($primary[$date] ?? null, $holder, "{$date}: nobody holds the source duty and its reinforcement.");
            }
            $source = $primary[$date] ?? null;
            $day = new \DateTimeImmutable($date);
            $weekday = (int) $day->format('N');
            // Tuesday stands alone; the Friday–Sunday block is required as a whole when any of its days triggers it.
            $blockDays = $weekday >= 5 ? array_map(static fn (int $i): string => $day->modify(\sprintf('%+d days', 5 - $weekday + $i))->format('Y-m-d'), [0, 1, 2]) : [$date];
            $triggered = false;
            foreach ($blockDays as $blockDay) {
                $dayHolder = $primary[$blockDay] ?? null;
                $triggered = $triggered || 'alice@example.com' === $dayHolder || ('bob@example.com' === $dayHolder && $weekday >= 5);
            }
            $state = $states[$date] ?? null;
            if (null !== $state) {
                self::assertSame($triggered, str_starts_with($state, 'REQUIRED_'), "{$date}: required exactly when the main line's holder triggers it ({$source}, {$state}).");
                self::assertNotSame('NOT_REQUIRED_ASSIGNED', $state, "{$date}: no reinforcement held where none is needed.");
            }
        }
        $preflight = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}/publication-preflight", token: $this->s['creator']);
        self::assertSame([], $preflight['invalidAssignments'], 'Nothing the publication check would reject.');
    }

    public function testTheSourceAndItsReinforcementGeneratedInOneRunNeverDoubleBookAnybody(): void
    {
        $preview = array_column($this->fillPreview($this->client, $this->s)['lines'], null, 'lineName');
        self::assertSame(['GENERATE', true], [$preview['Seniors']['action'], $preview['Seniors']['selectedByDefault']]);
        self::assertSame(['GENERATE', true, true], [$preview['Renfort']['action'], $preview['Renfort']['selectedByDefault'], $preview['Renfort']['afterSource']]);
        $before = $this->protectedRows();

        $job = $this->fillNow($this->client, $this->s, [$this->s['primaryLineId'], $this->s['renfortLineId']]);

        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        $lines = $this->fillLines($job);
        self::assertSame(['done', 'done'], [$lines['Seniors']['status'], $lines['Renfort']['status']]);
        self::assertSame(0, $job['outcome']['modifiedExistingCount']);
        $this->assertNoExistingRowChanged($before);
        self::assertNotSame([], array_filter($this->renfortCalendar($this->s)), 'Reinforcements were generated in the same run.');
        $this->assertCoherentAcrossLines();

        // Run again: nothing left to do, nothing changes.
        $after = $this->protectedRows();
        $again = $this->fillNow($this->client, $this->s, [$this->s['primaryLineId'], $this->s['renfortLineId']]);
        if (isset($again['httpStatus'])) {
            self::assertSame([409, 'nothing_to_fill'], [$again['httpStatus'], $again['error']]);
        } else {
            self::assertSame(0, $again['outcome']['addedCount'] + $again['outcome']['modifiedExistingCount']);
        }
        $this->assertNoExistingRowChanged($after);
    }

    /**
     * Forced: alice holds the Tuesday source duty and carol is away that day —
     * the only reinforcement candidate left is alice herself. The Tuesday
     * reinforcement stays uncovered (said, counted) rather than given to the
     * person it reinforces; the weekend block, triggered by bob, goes to carol.
     */
    public function testWhenOnlyTheSourceHolderCouldReinforceTheReinforcementStaysUncovered(): void
    {
        $this->forceHolders($this->client, $this->s, ['2027-01-05' => 'alice@example.com', '2027-01-08' => 'bob@example.com', '2027-01-09' => 'bob@example.com', '2027-01-10' => 'bob@example.com']);
        $this->declareRange($this->client, $this->s['carol'], '2027-01-05', '2027-01-06');

        $job = $this->fillNow($this->client, $this->s, [$this->s['primaryLineId'], $this->s['renfortLineId']]);

        self::assertSame('SUCCEEDED', $job['status'], json_encode($job, \JSON_THROW_ON_ERROR));
        $primary = $this->primaryCalendar();
        self::assertSame(['alice@example.com', 'bob@example.com', 'bob@example.com', 'bob@example.com'], [$primary['2027-01-05'], $primary['2027-01-08'], $primary['2027-01-09'], $primary['2027-01-10']]);
        $renfort = $this->renfortCalendar($this->s);
        $states = $this->renfortStates($this->client, $this->s);
        self::assertNull($renfort['2027-01-05'], 'Never alice reinforcing herself.');
        self::assertSame('REQUIRED_UNASSIGNED', $states['2027-01-05']);
        self::assertSame(['carol@example.com', 'carol@example.com', 'carol@example.com'], [$renfort['2027-01-08'], $renfort['2027-01-09'], $renfort['2027-01-10']]);
        self::assertGreaterThanOrEqual(1, $this->fillLines($job)['Renfort']['remainingUncoveredCount'], 'The missing reinforcement is counted, not hidden.');
        $this->assertCoherentAcrossLines();
    }
}
