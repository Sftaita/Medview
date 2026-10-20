<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use App\Repository\WeeklyDutyReminderRepository;
use App\Tests\CalendarWorkflowTestHelpers;
use App\Tests\PlanningCycleTestHelpers;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * Saturday's reminder across two periods and the changes not republished
 * yet (docs/decisions.md D199): each period's duties are compared with that
 * period's own latest publication — never another's — and an accepted swap,
 * effective at once, is announced as not republished yet.
 *
 * Fixture (PlanningCycleMemberReadsTest's): period 1 2027-01-01 → 05-01,
 * period 2 → 08-01, period 3 → 11-01. Next week seen from Saturday 24 April
 * 2027 is Monday 26 April → Monday 3 May:
 *
 *   period 1  T1 Tue 27 Apr 08-20                    bob
 *             N1 Fri 30 Apr 20:00 → Sat 1 May 08:00  alice
 *   period 2  S2 Sun 2 May 10-18                     alice
 *             C2 Tue 4 May (outside the week)        admin
 */
final class PlanningCycleReminderChangesTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;
    use PlanningCycleTestHelpers;

    private const REST = ['legalMinRestEnabled' => true, 'legalMinRestHours' => 11];
    private const SATURDAY = '2027-04-24 08:00:00 Europe/Brussels';
    private const MARK = '(changement pas encore republié)';
    private const NOTICE = 'Ce rappel tient compte de changements faits depuis la dernière diffusion du planning';

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    /** @var array<string, string> name → duty stableId */
    private array $d = [];

    protected function setUp(): void
    {
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
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
        ];
        $this->forceHolders([
            $this->d['T1'] => 'bob@example.com',
            $this->d['N1'] => 'alice@example.com',
            $this->d['S2'] => 'alice@example.com',
            $this->d['C2'] => 'admin@example.com',
        ]);
        foreach ($published as $number) {
            $this->publishPeriod($number);
        }
    }

    public function testEachPeriodOfTheWeekIsComparedWithItsOwnLatestPublication(): void
    {
        $this->scenario([1, 2]);
        $this->reassignTo($this->client, $this->s, $this->d['S2'], 'bob@example.com');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $this->runCommand();
        $reminders = $this->reminders();

        $bob = $this->text($reminders['bob@example.com']);
        self::assertStringContainsString('Mardi 27 avril — Seniors', $bob);
        self::assertStringNotContainsString('Mardi 27 avril — Seniors '.self::MARK, $bob, 'period 1 matches its publication');
        self::assertStringContainsString('Dimanche 2 mai — Seniors '.self::MARK, $bob, 'period 2 does not');
        self::assertStringContainsString(self::NOTICE, $bob);

        $alice = $this->text($reminders['alice@example.com']);
        self::assertStringContainsString('Vendredi 30 avril — Seniors', $alice);
        self::assertStringNotContainsString('2 mai', $alice, 'no longer hers');
        self::assertStringNotContainsString(self::MARK, $alice);
        self::assertStringContainsString(self::NOTICE, $alice, 'she lost a diffused duty of that week');
        self::assertArrayNotHasKey('admin@example.com', $reminders);
    }

    public function testARepublicationOfOnePeriodClearsOnlyThatPeriodsChanges(): void
    {
        $this->scenario([1, 2]);
        $this->reassignTo($this->client, $this->s, $this->d['T1'], 'admin@example.com');
        $this->reassignTo($this->client, $this->s, $this->d['S2'], 'bob@example.com');
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/republish", ['period' => $this->periodId(2)], $this->s['creator']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $this->runCommand();
        $reminders = $this->reminders();
        self::assertStringContainsString('Mardi 27 avril — Seniors '.self::MARK, $this->text($reminders['admin@example.com']), 'period 1 is still not republished');
        $bob = $this->text($reminders['bob@example.com']);
        self::assertStringContainsString('Dimanche 2 mai — Seniors', $bob);
        self::assertStringNotContainsString(self::MARK, $bob, 'period 2 was republished');
        self::assertStringContainsString(self::NOTICE, $bob, 'bob lost period 1\'s Tuesday, not republished');
    }

    public function testAnAcceptedSwapIsAnnouncedAsNotRepublishedYet(): void
    {
        $this->scenario([1, 2]);
        $request = $this->api($this->client, 'POST', '/api/duty-swap-requests', [
            'dutyStableId' => $this->d['N1'],
            'kind' => 'AGREED',
            'recipientUserStableIds' => [(string) static::getContainer()->get(UserRepository::class)->findOneByEmail('bob@example.com')->getStableId()],
            'counterpartDutyStableId' => $this->d['T1'],
            'acknowledgedResponsibility' => true,
        ], $this->s['alice']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $this->api($this->client, 'POST', "/api/duty-swap-proposals/{$request['proposals'][0]['stableId']}/accept", [], $this->s['bob']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('alice@example.com', $this->holderOf($this->d['T1']));

        $this->runCommand();
        $reminders = $this->reminders();
        $alice = $this->text($reminders['alice@example.com']);
        self::assertStringContainsString('Mardi 27 avril — Seniors '.self::MARK, $alice, 'the duties actually in force');
        self::assertStringContainsString('Dimanche 2 mai — Seniors', $alice);
        self::assertStringNotContainsString('Dimanche 2 mai — Seniors '.self::MARK, $alice);
        self::assertStringNotContainsString('Vendredi 30 avril', $alice);
        self::assertStringContainsString(self::NOTICE, $alice);
        $bob = $this->text($reminders['bob@example.com']);
        self::assertStringContainsString('Vendredi 30 avril — Seniors '.self::MARK, $bob);
        self::assertStringNotContainsString('Mardi 27 avril', $bob);
    }

    public function testAPeriodPublishedAfterTheReminderGetsItsPublicationEmailNotASecondReminder(): void
    {
        $this->scenario([1]);
        $this->runCommand();
        self::assertStringNotContainsString('2 mai', $this->text($this->reminders()['alice@example.com']), 'period 2 still a draft');
        $sent = \count(static::getContainer()->get(WeeklyDutyReminderRepository::class)->findAll());

        $this->publishPeriod(2);
        $published = array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email && 'alice@example.com' === $m->getTo()[0]->getAddress() && !str_starts_with((string) $m->getSubject(), 'Vos gardes'));
        self::assertNotEmpty($published, 'period 2\'s first publication tells its participants');

        $output = $this->runCommand();
        self::assertStringContainsString('0 reminder(s) sent', $output, 'idempotent per person, planning and week (D146, kept)');
        self::assertCount($sent, static::getContainer()->get(WeeklyDutyReminderRepository::class)->findAll());
    }

    // --- helpers ---------------------------------------------------------------------------------

    private function runCommand(): string
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:duty-reminders:weekly'));
        $tester->execute(['--now' => self::SATURDAY]);
        $tester->assertCommandIsSuccessful();

        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /** @return array<string, Email> */
    private function reminders(): array
    {
        $byRecipient = [];
        foreach (self::getMailerMessages() as $message) {
            \assert($message instanceof Email);
            if (str_starts_with((string) $message->getSubject(), 'Vos gardes de la semaine prochaine')) {
                self::assertArrayNotHasKey($message->getTo()[0]->getAddress(), $byRecipient, 'one reminder per person');
                $byRecipient[$message->getTo()[0]->getAddress()] = $message;
            }
        }

        return $byRecipient;
    }

    private function text(Email $message): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) $message->getTextBody());
    }
}
