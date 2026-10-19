<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningRepository;
use App\Repository\UserRepository;
use App\Service\PlanningAbsenceExportDataBuilder;
use App\Service\PlanningAbsenceExportPdfRenderer;
use App\Service\PlanningAbsenceExportService;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\PlanningCycleTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * "Exporter les absences (PDF)" over one period or a range, lot L4f
 * (docs/decisions.md D200): the rights stay MANAGE_AVAILABILITY and are
 * checked before the range is even read; the range must lie inside the
 * planning; the days, participants and memberships are those of the range.
 *
 * Fixture: pilotScenario, period 1 2027-01-01 → 05-01, period 2 → 08-01,
 * period 3 → 11-01; alice unavailable from Wednesday 28 April to Monday 3
 * May (across the boundary of periods 1 and 2).
 */
final class PlanningCycleAbsenceExportTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;
    use PlanningCycleTestHelpers;

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
        $this->createPeriod('2027-08-01');
        $this->createPeriod('2027-11-01');
        $this->declareRange($this->client, $this->s['alice'], '2027-04-28', '2027-05-04');
    }

    /** @param array<string, string> $query */
    private function download(array $query, string $who): void
    {
        $this->client->request('GET', "/api/plannings/{$this->s['planningId']}/availability-export.pdf?".http_build_query($query), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->s[$who], 'REMOTE_ADDR' => $this->randomTestIp()]);
    }

    private function disposition(): string
    {
        return (string) $this->client->getResponse()->headers->get('Content-Disposition');
    }

    /** @return list<string> alice's absence days in the export of [$first, $end[ */
    private function aliceDays(string $first, string $end): array
    {
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $alice = (string) static::getContainer()->get(UserRepository::class)->findOneByEmail('alice@example.com')->getStableId();
        foreach (static::getContainer()->get(PlanningAbsenceExportDataBuilder::class)->build($planning, $first, $end)->members as $member) {
            if ($alice === $member->userStableId) {
                return $member->days;
            }
        }

        self::fail('alice is a participant');
    }

    public function testWithoutARangeTheWholePlanningIsExportedAsBefore(): void
    {
        $this->download([], 'creator');
        self::assertResponseIsSuccessful();
        self::assertStringEndsWith('_2027-01-01_2027-10-31.pdf', $this->disposition());
    }

    public function testOnePeriodOnly(): void
    {
        foreach (['creator', 'admin'] as $who) {
            $this->download(['period' => $this->periodId(2)], $who);
            self::assertResponseIsSuccessful($who);
            self::assertStringEndsWith('_2027-05-01_2027-07-31.pdf', $this->disposition());
            self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
        }

        self::assertSame(['2027-05-01', '2027-05-02', '2027-05-03'], $this->aliceDays('2027-05-01', '2027-08-01'), 'only the days of period 2');
        self::assertSame(['2027-04-28', '2027-04-29', '2027-04-30'], $this->aliceDays('2027-01-01', '2027-05-01'), 'only the days of period 1');
    }

    public function testAnExplicitRangeAcrossTwoPeriodsNamesThem(): void
    {
        $this->download(['from' => '2027-04-26', 'to' => '2027-05-10'], 'creator');
        self::assertResponseIsSuccessful();
        self::assertStringEndsWith('_2027-04-26_2027-05-09.pdf', $this->disposition(), 'to is exclusive');
        self::assertSame(['2027-04-28', '2027-04-29', '2027-04-30', '2027-05-01', '2027-05-02', '2027-05-03'], $this->aliceDays('2027-04-26', '2027-05-10'));

        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $renderer = static::getContainer()->get(PlanningAbsenceExportPdfRenderer::class);
        $builder = static::getContainer()->get(PlanningAbsenceExportDataBuilder::class);
        self::assertSame('Périodes 1 à 2', PlanningAbsenceExportService::scopeLabel($planning, '2027-04-26', '2027-05-10'));
        self::assertSame('Période 2', PlanningAbsenceExportService::scopeLabel($planning, '2027-05-01', '2027-08-01'));
        self::assertSame('Périodes 1 à 3', PlanningAbsenceExportService::scopeLabel($planning, '2027-01-01', '2027-11-01'));
        self::assertStringEndsWith(' · Périodes 1 à 2', $renderer->view($builder->build($planning, '2027-04-26', '2027-05-10', 'Périodes 1 à 2'))['periodLabel']);
    }

    public function testAnInvalidRangeIsRefusedWithAViolation(): void
    {
        $cases = [
            [['period' => '00000000-0000-0000-0000-000000000000'], 'period'],
            [['period' => $this->periodId(2), 'from' => '2027-05-01'], 'period'],
            [['from' => '2026-12-31'], 'from'],
            [['to' => '2027-11-02'], 'to'],
            [['from' => '2027-05-10', 'to' => '2027-05-10'], 'to'],
            [['from' => '10/05/2027'], 'from'],
        ];
        foreach ($cases as [$query, $field]) {
            $this->download($query, 'creator');
            self::assertResponseStatusCodeSame(422, json_encode($query));
            $body = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertSame('validation_failed', $body['error']);
            self::assertArrayHasKey($field, $body['violations'], json_encode($query));
        }
    }

    public function testThePlainMemberAndTheOutsiderStayRefusedWhateverTheRange(): void
    {
        foreach (['alice', 'outsider'] as $who) {
            foreach ([['period' => $this->periodId(2)], ['from' => 'nonsense'], []] as $query) {
                $this->download($query, $who);
                self::assertResponseStatusCodeSame(403, $who.' '.json_encode($query).': the rights first, never the range');
            }
        }
    }
}
