<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningPublicationRepository;
use App\Repository\UserRepository;
use App\Service\PlanningExportData;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\PlanningCycleTestHelpers;
use App\Tests\PlanningExportTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * "Exporter" on a planning with several periods, lot L4f
 * (docs/decisions.md D200): a range may cross periods, each date carries
 * its period, only published line periods are exported — the others read
 * "Non publiée" and are listed, never mixed silently — and what a published
 * one shows depends on who asks: a manager gets the current calendar,
 * labelled when it differs from the last diffusion; a plain member gets
 * exactly what was diffused.
 *
 * Fixture (PlanningCycleMemberReadsTest's): period 1 2027-01-01 → 05-01,
 * period 2 → 08-01, period 3 → 11-01; T1 Tue 27 Apr bob, N1 Fri 30 Apr
 * (night) alice, S2 Sun 2 May alice, C2 Tue 4 May admin, P3 Tue 3 Aug alice.
 */
final class PlanningCycleExportTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;
    use PlanningCycleTestHelpers;
    use PlanningExportTestHelpers;

    private const REST = ['legalMinRestEnabled' => true, 'legalMinRestHours' => 11];

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    /** @var array<string, string> */
    private array $d = [];

    protected function setUp(): void
    {
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
        // Distinct names: the documents show people by name only.
        foreach (['admin@example.com' => ['Adam', 'Leroy'], 'alice@example.com' => ['Alice', 'Martin'], 'bob@example.com' => ['Bob', 'Durand']] as $email => [$first, $last]) {
            $user = static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
            $user->setFirstName($first);
            $user->setLastName($last);
        }
        $this->em()->flush();
        self::assertNotSame($this->fullName('alice@example.com'), $this->fullName('bob@example.com'));
    }

    /** @param list<int> $published */
    private function scenario(array $published): void
    {
        $this->createPeriod('2027-08-01');
        $this->createPeriod('2027-11-01');
        $this->addDuties(1, [['2027-04-27 08:00', '2027-04-27 20:00'], ['2027-04-30 20:00', '2027-05-01 08:00']]);
        $this->addDuties(2, [['2027-05-02 10:00', '2027-05-02 18:00'], ['2027-05-04 08:00', '2027-05-04 20:00']]);
        $this->addDuties(3, [['2027-08-03 08:00', '2027-08-03 20:00']]);
        foreach ([1, 2, 3] as $number) {
            $this->generatePeriod($number, self::REST);
        }
        $this->d = [
            'T1' => $this->dutyIn(1, '2027-04-27'),
            'N1' => $this->dutyIn(1, '2027-04-30'),
            'S2' => $this->dutyIn(2, '2027-05-02'),
            'C2' => $this->dutyIn(2, '2027-05-04'),
            'P3' => $this->dutyIn(3, '2027-08-03'),
        ];
        $this->forceHolders([
            $this->d['T1'] => 'bob@example.com',
            $this->d['N1'] => 'alice@example.com',
            $this->d['S2'] => 'alice@example.com',
            $this->d['C2'] => 'admin@example.com',
            $this->d['P3'] => 'alice@example.com',
        ]);
        foreach ($published as $number) {
            $this->publishPeriod($number);
        }
    }

    private function fullName(string $email): string
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail($email);

        return $user->getFirstName().' '.$user->getLastName();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function body(array $extra = []): array
    {
        $line = $this->api($this->client, 'GET', "/api/plannings/{$this->s['planningId']}", token: $this->s['creator'])['lines'][0];

        return $extra + ['format' => 'xlsx', 'title' => 'Gardes', 'lines' => [['stableId' => $line['stableId'], 'label' => 'Seniors']]];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, list<list<mixed>>>
     */
    private function workbook(array $body, string $who): array
    {
        $this->client->request('POST', "/api/plannings/{$this->s['planningId']}/export", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->s[$who]], content: (string) json_encode($body));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->readXlsx((string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, mixed> */
    private function refused(array $body, string $who): array
    {
        return $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/export", $body, $this->s[$who]);
    }

    /**
     * @param list<list<mixed>> $sheet
     *
     * @return array<string, list<mixed>> date → row
     */
    private function byDate(array $sheet): array
    {
        $rows = [];
        foreach (\array_slice($sheet, 1) as $row) {
            $rows[$row[0]->format('Y-m-d')] = $row;
        }

        return $rows;
    }

    /** @param list<list<mixed>> $info */
    private function info(array $info, string $name): ?string
    {
        foreach ($info as $row) {
            if ($row[0] === $name) {
                return $row[1];
            }
        }

        return null;
    }

    // --- several periods ----------------------------------------------------------------------------

    public function testARangeAcrossTwoPublishedPeriodsIdentifiesThePeriodOfEachDate(): void
    {
        $this->scenario([1, 2]);

        $sheets = $this->workbook($this->body(['from' => '2027-04-26', 'to' => '2027-05-06']), 'admin');
        self::assertSame(['Date', 'Jour', 'Période', 'Seniors'], $sheets['Planning'][0]);
        $rows = $this->byDate($sheets['Planning']);
        self::assertCount(10, $rows, 'every date of [from, to[, once — no gap nor repeat at the boundary');
        self::assertSame(['Période 1', $this->fullName('bob@example.com')], \array_slice($rows['2027-04-27'], 2));
        self::assertSame(['Période 1', $this->fullName('alice@example.com')], \array_slice($rows['2027-04-30'], 2), 'the night belongs to its start date');
        self::assertSame('Période 1', $rows['2027-04-30'][2]);
        self::assertSame('Période 2', $rows['2027-05-01'][2], 'the boundary');
        self::assertSame(['Période 2', $this->fullName('alice@example.com')], \array_slice($rows['2027-05-02'], 2));
        self::assertSame(['Période 2', $this->fullName('admin@example.com')], \array_slice($rows['2027-05-04'], 2));

        self::assertSame(['Personne', 'Date', 'Jour', 'Ligne', 'Période'], $sheets['Par personne'][0]);
        self::assertContains('Période 2', array_column(\array_slice($sheets['Par personne'], 1), 4));
        self::assertSame(PlanningExportData::OPERATIONAL_LABEL, $this->info($sheets['Informations'], 'Nature'));
        self::assertStringContainsString('Période 1 (du 26 avril 2027 au 30 avril 2027) · Période 2 (du 1er mai 2027 au 5 mai 2027)', (string) $this->info($sheets['Informations'], 'Périodes'));
        self::assertNull($this->info($sheets['Informations'], 'Non inclus car non publiés'));
    }

    public function testThePeriodShortcutExportsThatPeriodOnlyAndIsNeverCombinedWithDates(): void
    {
        $this->scenario([1, 2]);

        $rows = $this->byDate($this->workbook($this->body(['period' => $this->periodId(2)]), 'admin')['Planning']);
        self::assertSame('2027-05-01', array_key_first($rows));
        self::assertSame('2027-07-31', array_key_last($rows));
        self::assertSame(['Période 2'], array_values(array_unique(array_column($rows, 2))));

        $response = $this->refused($this->body(['period' => $this->periodId(2), 'from' => '2027-05-01']), 'admin');
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('period', $response['violations']);
        $response = $this->refused($this->body(['period' => '00000000-0000-0000-0000-000000000000']), 'admin');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Not a period of this planning.', $response['violations']['period']);
    }

    public function testAnUnpublishedPeriodIsNeverExportedNorMixedSilently(): void
    {
        $this->scenario([1]);

        foreach (['admin', 'alice'] as $who) {
            $sheets = $this->workbook($this->body(['from' => '2027-04-26', 'to' => '2027-05-06']), $who);
            $rows = $this->byDate($sheets['Planning']);
            self::assertSame($this->fullName('bob@example.com'), $rows['2027-04-27'][3], $who);
            foreach (['2027-05-01', '2027-05-02', '2027-05-04'] as $date) {
                self::assertSame('Non publiée', $rows[$date][3], "{$who}: period 2 is a draft, never its holders");
            }
            self::assertNotContains('Période 2', array_column(\array_slice($sheets['Par personne'], 1), 4), $who);
            self::assertSame('Période 2 — Seniors', $this->info($sheets['Informations'], 'Non inclus car non publiés'), $who);
        }

        // Nothing published over the range at all: refused, never a document of "Non publiée" days.
        foreach (['admin', 'alice'] as $who) {
            $response = $this->refused($this->body(['period' => $this->periodId(2)]), $who);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('not_published_in_range', $response['error']);
        }
    }

    // --- who sees what --------------------------------------------------------------------------------

    public function testAManagerSeesTheCurrentCalendarLabelledAndAMemberWhatWasDiffused(): void
    {
        $this->scenario([1, 2]);
        $this->reassignTo($this->client, $this->s, $this->d['S2'], 'bob@example.com');
        self::assertResponseIsSuccessful();
        $range = ['from' => '2027-04-26', 'to' => '2027-05-06'];

        $manager = $this->workbook($this->body($range), 'admin');
        self::assertSame($this->fullName('bob@example.com'), $this->byDate($manager['Planning'])['2027-05-02'][3], 'the change in force');
        self::assertSame(PlanningExportData::OPERATIONAL_CHANGED_LABEL, $this->info($manager['Informations'], 'Nature'));
        $this->client->request('POST', "/api/plannings/{$this->s['planningId']}/export", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->s['admin']], content: (string) json_encode($this->body($range)));
        self::assertStringContainsString('Calendrier opérationnel — modifications non publiées possibles', $this->xlsxPart((string) $this->client->getResponse()->getContent(), 'xl/worksheets/sheet1.xml'), 'in the printed header too');

        foreach (['alice', 'bob'] as $member) {
            $sheets = $this->workbook($this->body($range), $member);
            self::assertSame($this->fullName('alice@example.com'), $this->byDate($sheets['Planning'])['2027-05-02'][3], "{$member}: what was diffused, never the change");
            self::assertStringStartsWith('Planning diffusé — dernière diffusion : période 1 le ', (string) $this->info($sheets['Informations'], 'Nature'));
            self::assertStringContainsString(', période 2 le ', (string) $this->info($sheets['Informations'], 'Nature'));
        }

        // Republished: the member's export follows the new diffusion; the manager's is no longer labelled.
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/republish", ['period' => $this->periodId(2)], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame($this->fullName('bob@example.com'), $this->byDate($this->workbook($this->body($range), 'alice')['Planning'])['2027-05-02'][3]);
        self::assertSame(PlanningExportData::OPERATIONAL_LABEL, $this->info($this->workbook($this->body($range), 'admin')['Informations'], 'Nature'));
    }

    public function testAToBToAIsNoUnpublishedChange(): void
    {
        $this->scenario([1, 2]);
        $this->reassignTo($this->client, $this->s, $this->d['S2'], 'bob@example.com');
        $this->reassignTo($this->client, $this->s, $this->d['S2'], 'alice@example.com');
        self::assertResponseIsSuccessful();

        self::assertSame(PlanningExportData::OPERATIONAL_LABEL, $this->info($this->workbook($this->body(['period' => $this->periodId(2)]), 'admin')['Informations'], 'Nature'));
    }

    public function testExportingRecordsNothingAndLeavesEveryPublicationAsItWas(): void
    {
        $this->scenario([1, 2]);
        $this->reassignTo($this->client, $this->s, $this->d['S2'], 'bob@example.com');
        $publications = $this->publicationFingerprint();
        $period2 = $this->fingerprint(2);

        $this->workbook($this->body(), 'admin');
        $this->workbook($this->body(), 'alice');
        $this->client->request('POST', "/api/plannings/{$this->s['planningId']}/export", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->s['admin']], content: (string) json_encode($this->body(['format' => 'pdf'])));
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());

        self::assertSame($publications, $this->publicationFingerprint(), 'never a publication, never a stored PDF replaced');
        self::assertSame($period2, $this->fingerprint(2));
        self::assertCount(2, static::getContainer()->get(PlanningPublicationRepository::class)->findAll());
        self::assertEmailCount(0);
    }

    public function testAnOutsiderIsRefusedWhateverTheRange(): void
    {
        $this->scenario([1, 2]);
        $this->refused($this->body(['period' => $this->periodId(2)]), 'outsider');
        self::assertResponseStatusCodeSame(403);
    }
}
