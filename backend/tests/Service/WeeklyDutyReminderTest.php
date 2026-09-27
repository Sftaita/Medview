<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Planning;
use App\Entity\User;
use App\Repository\WeeklyDutyReminderRepository;
use App\Service\WeeklyDutyReminderService;
use App\Tests\CalendarWorkflowTestHelpers;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * Saturday's "Vos gardes de la semaine prochaine" (docs/decisions.md D146):
 * only people with at least one duty next week, only their own duties, read
 * from the current calendar at send time, week computed in the planning's
 * timezone, idempotent.
 *
 * Fixture: planning 2027-01-01 → 2027-05-01 (Europe/Brussels); next week
 * seen from Saturday 2 January 2027 is Monday 4 → Sunday 10 January. Two
 * units that week (Tuesday 5, block Saturday 9 + Sunday 10) for three
 * members, so at least one member has nothing; one more duty on Tuesday 12,
 * outside the week.
 */
final class WeeklyDutyReminderTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;

    private const SATURDAY = '2027-01-02 08:00:00 Europe/Brussels';

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    public function testEachPersonWithDutiesNextWeekGetsOnlyTheirOwnAndNobodyElseGetsAnything(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $calendar = $this->currentCalendar($s['planningId']);
        $tuesdayHolder = $calendar['0|2027-01-05|ONCALL'];
        $blockHolder = $this->blockHolderOf($calendar);

        $output = $this->runCommand(self::SATURDAY);
        self::assertStringContainsString('1 planning(s) examined', $output);

        $byRecipient = $this->messagesByRecipient();
        $expected = array_values(array_unique([$tuesdayHolder, $blockHolder]));
        sort($expected);
        self::assertSame($expected, array_keys($byRecipient), 'Only people with a duty next week.');
        $nobody = array_values(array_diff(['admin@example.com', 'alice@example.com', 'bob@example.com'], $expected));
        self::assertNotEmpty($nobody, 'Fixture: at least one member has no duty that week…');
        foreach ($nobody as $email) {
            self::assertArrayNotHasKey($email, $byRecipient, '…and receives nothing at all — never a "no duty" email.');
        }

        $tuesdayMail = (string) $byRecipient[$tuesdayHolder]->getTextBody();
        self::assertStringContainsString('Mardi 5 janvier — Seniors', $tuesdayMail);
        self::assertStringNotContainsString('12 janvier', $tuesdayMail, 'Outside next week.');
        $blockMail = (string) $byRecipient[$blockHolder]->getTextBody();
        self::assertStringContainsString('Samedi 9 + dimanche 10 janvier — Bloc Test group — Seniors', $blockMail);
        if ($tuesdayHolder !== $blockHolder) {
            self::assertStringNotContainsString('Mardi 5 janvier', $blockMail, 'Only their own duties.');
        }
        self::assertStringContainsString('Vos gardes de la semaine prochaine', (string) $byRecipient[$blockHolder]->getSubject());
    }

    public function testALateChangeIsReflectedBecauseTheCurrentCalendarIsReadAtSendTime(): void
    {
        $client = static::createClient();
        $s = $this->publishedScenario($client);
        $calendar = $this->currentCalendar($s['planningId']);
        $nobody = array_values(array_diff(['admin@example.com', 'alice@example.com', 'bob@example.com'], [$calendar['0|2027-01-05|ONCALL'], $this->blockHolderOf($calendar)]))[0];

        // Reassigned after publication, never republished: the reminder still follows the real calendar.
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-05'), $nobody);
        self::assertResponseIsSuccessful();

        $this->runCommand(self::SATURDAY);
        $byRecipient = $this->messagesByRecipient();
        self::assertArrayHasKey($nobody, $byRecipient);
        self::assertStringContainsString('Mardi 5 janvier', (string) $byRecipient[$nobody]->getTextBody());
        if ($calendar['0|2027-01-05|ONCALL'] !== $this->blockHolderOf($calendar)) {
            self::assertArrayNotHasKey($calendar['0|2027-01-05|ONCALL'], $byRecipient, 'The former holder has nothing left that week.');
        }
    }

    public function testASecondRunTheSameSaturdaySendsNothingTwice(): void
    {
        $client = static::createClient();
        $this->publishedScenario($client);

        $this->runCommand(self::SATURDAY);
        $first = \count($this->messagesByRecipient());
        self::assertGreaterThan(0, $first);
        self::assertCount($first, static::getContainer()->get(WeeklyDutyReminderRepository::class)->findAll());

        $output = $this->runCommand(self::SATURDAY);
        self::assertStringContainsString('0 reminder(s) sent, '.$first.' already sent', $output);
        self::assertCount($first, static::getContainer()->get(WeeklyDutyReminderRepository::class)->findAll());
    }

    public function testAnUnpublishedPlanningRemindsNobody(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->generate($client, $s);

        $output = $this->runCommand(self::SATURDAY);
        self::assertStringContainsString('0 planning(s) examined', $output);
        self::assertSame([], $this->messagesByRecipient());
    }

    public function testNextWeekIsComputedInThePlanningTimezoneNotTheServers(): void
    {
        $service = static::getContainer()->get(WeeklyDutyReminderService::class);
        $creator = new User('tz@example.com', 'Tz', 'Test', 'hash');
        // Sunday 3 January 2027, 12:00 UTC — already Monday 01:00 in Auckland (UTC+13).
        $instant = new \DateTimeImmutable('2027-01-03 12:00:00 UTC');

        $brussels = new Planning('B', $creator, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'), 'Europe/Brussels');
        $auckland = new Planning('A', $creator, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'), 'Pacific/Auckland');

        self::assertSame('2027-01-04', $service->nextWeekStart($brussels, $instant)->format('Y-m-d'), 'Still Sunday in Brussels: next week starts tomorrow.');
        self::assertSame('2027-01-11', $service->nextWeekStart($auckland, $instant)->format('Y-m-d'), 'Already Monday in Auckland: next week is the one after.');
        self::assertSame('2027-01-04', $service->nextWeekStart($brussels, new \DateTimeImmutable(self::SATURDAY))->format('Y-m-d'));
    }

    // --- helpers -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function publishedScenario(KernelBrowser $client): array
    {
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']], ['2027-01-09', '2027-01-10', '2027-01-11']);
        $this->generate($client, $s);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();

        return $s;
    }

    private function runCommand(string $now): string
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('app:duty-reminders:weekly'));
        $tester->execute(['--now' => $now]);
        $tester->assertCommandIsSuccessful();

        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /**
     * Weekly-reminder emails only (the first-publication emails of the fixture are ignored).
     *
     * @return array<string, Email> recipient → message, sorted by recipient
     */
    private function messagesByRecipient(): array
    {
        $byRecipient = [];
        foreach (self::getMailerMessages() as $message) {
            \assert($message instanceof Email);
            if (str_starts_with((string) $message->getSubject(), 'Vos gardes de la semaine prochaine')) {
                $byRecipient[$message->getTo()[0]->getAddress()] = $message;
            }
        }
        ksort($byRecipient);

        return $byRecipient;
    }

    /**
     * @param array<string, string|null> $calendar
     */
    private function blockHolderOf(array $calendar): string
    {
        foreach ($calendar as $key => $who) {
            if (str_starts_with($key, '0|2027-01-09|')) {
                return (string) $who;
            }
        }

        self::fail('No block on 2027-01-09.');
    }
}
