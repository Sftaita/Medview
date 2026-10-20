<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\PlanningPublicationRepository;
use App\Repository\PlanningRepository;
use App\Repository\WeeklyDutyReminderRepository;
use App\Tests\CalendarWorkflowTestHelpers;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * Saturday's reminder and the changes not republished yet
 * (docs/decisions.md D199): the current calendar stays the source — what
 * the person is actually expected to work — and the email says so, in
 * plain words, only when their week differs from what was last diffused
 * (compared with the publication's append-only entries, by person, so
 * A → B → A is no change). Single-period planning.
 *
 * Fixture (WeeklyDutyReminderTest's): seen from Saturday 2 January 2027,
 * next week holds Tuesday 5 (standalone) and the block Saturday 9 + Sunday
 * 10 January, for admin, alice and bob.
 */
final class WeeklyDutyReminderUnpublishedChangesTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;

    private const SATURDAY = '2027-01-02 08:00:00 Europe/Brussels';
    private const MARK = '(changement pas encore republié)';
    private const NOTICE = 'Ce rappel tient compte de changements faits depuis la dernière diffusion du planning';
    private const PEOPLE = ['admin@example.com', 'alice@example.com', 'bob@example.com'];

    private KernelBrowser $client;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        $this->client = static::createClient();
        $this->s = $this->pilotScenario($this->client);
        $this->prepareLine($this->s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-12', '2027-01-13']], ['2027-01-09', '2027-01-10', '2027-01-11']);
        $this->generate($this->client, $this->s);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/publish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
    }

    public function testAWeekMatchingTheLastDiffusionSaysNothingMore(): void
    {
        $this->runCommand();

        $reminders = $this->reminders();
        self::assertNotEmpty($reminders);
        foreach ($reminders as $email => $message) {
            self::assertStringNotContainsString(self::MARK, $this->text($message), $email);
            self::assertStringNotContainsString(self::NOTICE, $this->text($message), $email);
            self::assertStringNotContainsString('pas encore republié', (string) $message->getHtmlBody(), $email);
        }
    }

    public function testAManualReassignmentNotRepublishedIsMarkedAndExplained(): void
    {
        ['tuesday' => $former, 'block' => $blockHolder] = $this->holders();
        $newHolder = $this->someoneElseThan($former, $blockHolder);
        $this->reassignTo($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-01-05'), $newHolder);
        self::assertResponseIsSuccessful();

        $this->runCommand();
        $reminders = $this->reminders();

        // The duties actually in force: the new holder is reminded of it, marked, with the explanation.
        $text = $this->text($reminders[$newHolder]);
        self::assertStringContainsString('Mardi 5 janvier — Seniors '.self::MARK, $text);
        self::assertStringContainsString(self::NOTICE, $text);
        self::assertStringContainsString('ne figurent pas dans le dernier planning PDF', $text);
        $html = (string) $reminders[$newHolder]->getHtmlBody();
        self::assertStringContainsString('Seniors (changement pas encore republié)', $html);
        self::assertStringContainsString('dernière diffusion du planning', $html);

        // The former holder is never reminded of a duty no longer theirs; told only if they still have one that week.
        if ($former === $blockHolder) {
            $formerText = $this->text($reminders[$former]);
            self::assertStringNotContainsString('Mardi 5 janvier', $formerText);
            self::assertStringContainsString(self::NOTICE, $formerText, 'they lost a diffused duty of that week');
            self::assertStringNotContainsString(self::MARK, $formerText, 'the block itself is unchanged');
        } else {
            self::assertArrayNotHasKey($former, $reminders, 'no duty left that week: still no email (D199 limit)');
            self::assertStringNotContainsString(self::NOTICE, $this->text($reminders[$blockHolder]), 'nothing changed for the block holder');
        }
    }

    public function testACompletionNotRepublishedIsMarkedForTheNewHolder(): void
    {
        ['tuesday' => $former] = $this->holders();
        $tuesday = $this->dutyOn($this->s['planningId'], '2027-01-05');
        $this->unassignDuty($this->client, $this->s, $tuesday);
        self::assertResponseIsSuccessful();
        // Only one person left able to take it — never the former holder.
        $others = array_values(array_diff(self::PEOPLE, [$former]));
        $this->declareRange($this->client, $this->s[strstr($former, '@', true)], '2027-01-05', '2027-01-06');
        $this->declareRange($this->client, $this->s[strstr($others[1], '@', true)], '2027-01-05', '2027-01-06');
        $this->completePlanning($this->client, $this->s);
        self::assertResponseIsSuccessful();
        self::assertSame($others[0], $this->currentCalendar($this->s['planningId'])['0|2027-01-05|ONCALL']);

        $this->runCommand();
        $text = $this->text($this->reminders()[$others[0]]);
        self::assertStringContainsString('Mardi 5 janvier — Seniors '.self::MARK, $text);
        self::assertStringContainsString(self::NOTICE, $text);
    }

    public function testAToBToAReadsAsNoChangeAtAll(): void
    {
        ['tuesday' => $holder, 'block' => $blockHolder] = $this->holders();
        $tuesday = $this->dutyOn($this->s['planningId'], '2027-01-05');
        $this->reassignTo($this->client, $this->s, $tuesday, $this->someoneElseThan($holder, $blockHolder));
        self::assertResponseIsSuccessful();
        $this->reassignTo($this->client, $this->s, $tuesday, $holder);
        self::assertResponseIsSuccessful();

        $this->runCommand();
        foreach ($this->reminders() as $email => $message) {
            self::assertStringNotContainsString(self::MARK, $this->text($message), $email);
            self::assertStringNotContainsString(self::NOTICE, $this->text($message), $email);
        }
        self::assertStringContainsString('Mardi 5 janvier — Seniors', $this->text($this->reminders()[$holder]));
    }

    public function testARepublicationBeforeTheReminderLeavesNothingToExplain(): void
    {
        ['tuesday' => $former, 'block' => $blockHolder] = $this->holders();
        $newHolder = $this->someoneElseThan($former, $blockHolder);
        $this->reassignTo($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-01-05'), $newHolder);
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/republish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();

        $this->runCommand();
        $text = $this->text($this->reminders()[$newHolder]);
        self::assertStringContainsString('Mardi 5 janvier — Seniors', $text);
        self::assertStringNotContainsString(self::MARK, $text);
        self::assertStringNotContainsString(self::NOTICE, $text);
    }

    public function testARepublicationAfterTheReminderEmailsTheChangedPeopleButNeverASecondReminder(): void
    {
        ['tuesday' => $former, 'block' => $blockHolder] = $this->holders();
        $newHolder = $this->someoneElseThan($former, $blockHolder);
        $this->reassignTo($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-01-05'), $newHolder);
        $this->runCommand();
        $sent = \count($this->reminders());
        self::assertStringContainsString(self::NOTICE, $this->text($this->reminders()[$newHolder]));
        $publications = \count(static::getContainer()->get(PlanningPublicationRepository::class)->findAll());

        // Republication after the send: D172's personal emails, unchanged; the reminder is not sent again.
        $this->api($this->client, 'POST', "/api/plannings/{$this->s['planningId']}/republish", [], $this->s['creator']);
        self::assertResponseIsSuccessful();
        self::assertCount($publications + 1, static::getContainer()->get(PlanningPublicationRepository::class)->findAll());
        $output = $this->runCommand();
        self::assertStringContainsString('0 reminder(s) sent, '.$sent.' already sent', $output);
        self::assertCount($sent, static::getContainer()->get(WeeklyDutyReminderRepository::class)->findAll(), 'not one more reminder');
    }

    public function testTheReminderWritesNothingButItsIdempotenceRow(): void
    {
        $this->reassignTo($this->client, $this->s, $this->dutyOn($this->s['planningId'], '2027-01-05'), $this->someoneElseThan(...array_values($this->holders())));
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($this->s['planningId']);
        $publications = \count(static::getContainer()->get(PlanningPublicationRepository::class)->findByPlanning($planning));
        $calendar = $this->currentCalendar($this->s['planningId']);

        $this->runCommand();

        self::assertCount($publications, static::getContainer()->get(PlanningPublicationRepository::class)->findByPlanning($planning), 'never a publication');
        self::assertSame($calendar, $this->currentCalendar($this->s['planningId']), 'never a calendar change');
    }

    // --- helpers -------------------------------------------------------------------------

    /** @return array{tuesday: string, block: string} */
    private function holders(): array
    {
        $calendar = $this->currentCalendar($this->s['planningId']);
        foreach ($calendar as $key => $who) {
            if (str_starts_with($key, '0|2027-01-09|')) {
                return ['tuesday' => (string) $calendar['0|2027-01-05|ONCALL'], 'block' => (string) $who];
            }
        }

        self::fail('No block on 2027-01-09.');
    }

    private function someoneElseThan(string ...$emails): string
    {
        return array_values(array_diff(self::PEOPLE, $emails))[0];
    }

    private function runCommand(): string
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:duty-reminders:weekly'));
        $tester->execute(['--now' => self::SATURDAY]);
        $tester->assertCommandIsSuccessful();

        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /** @return array<string, Email> recipient → weekly reminder */
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
