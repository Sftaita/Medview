<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningAvailabilityReminderRepository;
use App\Repository\PlanningRepository;
use App\Tests\PlanningPilotTestHelpers;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Availability reminders (docs/decisions.md D127): who may send one, what the
 * email says (and never says), the append-only audit, "last reminder" derived
 * from it, and the guards against accidental double sends.
 */
final class AvailabilityReminderControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use PlanningPilotTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 UTC');
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function remind(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, array $s, string $email, string $token): array
    {
        return $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/members/{$this->memberIdOf($client, $s, $email)}/reminders", [], $token);
    }

    public function testAPlainMemberCannotSendAReminder(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $this->remind($client, $s, 'bob@example.com', $s['alice']);
        self::assertResponseStatusCodeSame(403);
        $this->remind($client, $s, 'bob@example.com', $s['outsider']);
        self::assertResponseStatusCodeSame(403);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/reminders/pending", [], $s['alice']);
        self::assertResponseStatusCodeSame(403);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/members/{$this->memberIdOf($client, $s, 'bob@example.com')}/reminders");
        self::assertResponseStatusCodeSame(401);
    }

    public function testASentReminderCreatesAnAuditEntryAndARealEmail(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->declareRange($client, $s['alice'], '2027-02-10', '2027-02-12');
        $this->api($client, 'PATCH', "/api/plannings/{$s['planningId']}/settings", ['availabilityDeadline' => '2026-12-25'], $s['creator']);

        $result = $this->remind($client, $s, 'alice@example.com', $s['admin']);

        self::assertResponseStatusCodeSame(201);
        self::assertNotNull($result['stableId']);
        self::assertStringStartsWith('2026-12-10T09:00:00', $result['sentAt']);
        self::assertSame($result['sentAt'], $result['lastReminderAt']);

        // The real email: to alice only, with a link to the existing stable page, no unavailability detail.
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertEmailAddressContains($email, 'To', 'alice@example.com');
        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();
        self::assertStringContainsString('Gardes Seniors', $html);
        self::assertStringContainsString('1er janvier 2027 au 30 avril 2027', $html);
        self::assertStringContainsString('25 décembre 2026', $html, 'The (informative) deadline is mentioned.');
        self::assertStringContainsString('/my-availability?collection='.$s['collectionId'], $html);
        self::assertStringContainsString('/my-availability?collection='.$s['collectionId'], $text);
        foreach ([$html, $text] as $body) {
            self::assertStringNotContainsString('10 février', $body, 'No unavailability detail in the email.');
            self::assertStringNotContainsString('2027-02', $body);
            self::assertStringNotContainsString('bob@example.com', $body);
        }
        self::assertStringNotContainsString($s['planningId'], $html, 'Only the collection link, never a planning/technical id.');

        // The audit row.
        $reminders = static::getContainer()->get(PlanningAvailabilityReminderRepository::class)->findByRecipient(
            static::getContainer()->get(PlanningRepository::class)->findOneByStableId($s['planningId']),
            $this->userOf('alice@example.com'),
        );
        self::assertCount(1, $reminders);
        self::assertSame($this->userOf('admin@example.com')->getId(), $reminders[0]->getSentBy()->getId());
        self::assertSame('EMAIL', $reminders[0]->getChannel()->value);
        self::assertFalse($reminders[0]->isBulk());
        self::assertSame(1, $reminders[0]->getPendingCollectionCount());
    }

    public function testTheLastReminderIsTheLastEventReallySent(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $first = $this->remind($client, $s, 'alice@example.com', $s['creator']);
        self::assertResponseStatusCodeSame(201);

        self::mockTime('2026-12-10 10:14:00 UTC');
        $second = $this->remind($client, $s, 'alice@example.com', $s['admin']);
        self::assertResponseStatusCodeSame(201);
        self::assertNotSame($first['stableId'], $second['stableId']);

        $status = $this->collectionStatus($client, $s);
        self::assertStringStartsWith('2026-12-10T10:14:00', $this->rowOf($status, 'alice@example.com')['lastReminderAt']);
        self::assertNull($this->rowOf($status, 'bob@example.com')['lastReminderAt'], 'Only alice was reminded.');

        // Append-only history, newest first, both events kept.
        $detail = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/members/{$this->memberIdOf($client, $s, 'alice@example.com')}/availability-status", token: $s['creator']);
        self::assertCount(2, $detail['reminders']);
        self::assertSame($second['stableId'], $detail['reminders'][0]['stableId']);
        self::assertSame($first['stableId'], $detail['reminders'][1]['stableId']);
        self::assertStringStartsWith('2026-12-10T10:14:00', $detail['member']['lastReminderAt']);
    }

    public function testADoubleSubmitIsRefusedAndSendsOnlyOneEmail(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $this->remind($client, $s, 'alice@example.com', $s['creator']);
        self::assertResponseStatusCodeSame(201);

        // Same person, a few seconds later (double click, or two admins at once).
        self::mockTime('2026-12-10 09:00:20 UTC');
        $again = $this->remind($client, $s, 'alice@example.com', $s['admin']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('reminder_recently_sent', $again['error']);
        self::assertEmailCount(0);

        $reminders = static::getContainer()->get(PlanningAvailabilityReminderRepository::class)->findByRecipient(
            static::getContainer()->get(PlanningRepository::class)->findOneByStableId($s['planningId']),
            $this->userOf('alice@example.com'),
        );
        self::assertCount(1, $reminders);
    }

    public function testNobodyIsRemindedOnceTheyConfirmed(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", ['noUnavailability' => true], $s['alice']);

        $result = $this->remind($client, $s, 'alice@example.com', $s['creator']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('nothing_to_remind', $result['error']);
        self::assertEmailCount(0);
    }

    public function testAMemberOfAnotherPlanningCannotBeReminded(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $other = $this->userToken($client, 'othercreator@example.com');
        $this->userToken($client, 'othermember@example.com');
        [$otherPlanningId, $otherTeamId] = $this->createPlanningWithTeam($client, $other, 'Fellows');
        $this->addMemberAs($client, $other, $otherPlanningId, $otherTeamId, 'othermember@example.com', 'MEMBER');
        $otherMemberRowId = $this->memberIdOf($client, ['planningId' => $otherPlanningId, 'teamId' => $otherTeamId, 'creator' => $other], 'othermember@example.com');

        // Our manager targets their member through our planning: 404.
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/members/{$otherMemberRowId}/reminders", [], $s['creator']);
        self::assertResponseStatusCodeSame(404);
        // Their manager targets our member through our planning: 403.
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/members/{$this->memberIdOf($client, $s, 'alice@example.com')}/reminders", [], $other);
        self::assertResponseStatusCodeSame(403);
        self::assertEmailCount(0);
    }

    // --- "Relancer les membres en attente" ---------------------------------

    public function testRemindingPendingMembersTargetsOnlyThoseWhoDidNotConfirm(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", ['noUnavailability' => true], $s['alice']);

        $result = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/reminders/pending", [], $s['admin']);

        self::assertResponseIsSuccessful();
        // admin + bob are still pending; alice confirmed.
        self::assertSame(2, $result['targetedCount']);
        self::assertSame(2, $result['sentCount']);
        self::assertSame(0, $result['skippedRecentlyCount']);
        self::assertSame(0, $result['failedCount']);
        self::assertEmailCount(2);
        $recipients = array_map(static fn ($message): string => $message->getTo()[0]->getAddress(), [self::getMailerMessage(0), self::getMailerMessage(1)]);
        sort($recipients);
        self::assertSame(['admin@example.com', 'bob@example.com'], $recipients);

        $status = $this->collectionStatus($client, $s);
        self::assertNull($this->rowOf($status, 'alice@example.com')['lastReminderAt']);
        self::assertNotNull($this->rowOf($status, 'bob@example.com')['lastReminderAt']);
    }

    public function testRemindingPendingMembersTwiceInARowDoesNotDoubleSend(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $first = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/reminders/pending", [], $s['creator']);
        self::assertSame(3, $first['sentCount']);

        self::mockTime('2026-12-10 09:00:30 UTC');
        $second = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/reminders/pending", [], $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(3, $second['targetedCount']);
        self::assertSame(0, $second['sentCount']);
        self::assertSame(3, $second['skippedRecentlyCount']);
        self::assertEmailCount(0);

        // Later, they are reminded again — bulk rows are flagged as such.
        self::mockTime('2026-12-11 09:00:00 UTC');
        $third = $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/reminders/pending", [], $s['creator']);
        self::assertSame(3, $third['sentCount']);
        self::assertTrue($third['sent'][0]['bulk']);
    }

    // --- the audit is append-only ---------------------------------------------

    public function testTheAuditTableRefusesUpdatesAndDeletes(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->remind($client, $s, 'alice@example.com', $s['creator']);
        self::assertResponseStatusCodeSame(201);

        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        foreach (['UPDATE planning_availability_reminders SET bulk = true', 'DELETE FROM planning_availability_reminders'] as $sql) {
            // A savepoint keeps the (test) transaction usable after the expected failure.
            $connection->executeStatement('SAVEPOINT append_only_check');
            try {
                $connection->executeStatement($sql);
                self::fail("`{$sql}` must be refused.");
            } catch (DbalException $exception) {
                self::assertStringContainsString('append-only', $exception->getMessage());
                $connection->executeStatement('ROLLBACK TO SAVEPOINT append_only_check');
            }
        }
    }

    public function testTheReminderOfAnExtendedPlanningTargetsOnlyTheUnansweredWindow(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->api($client, 'POST', "/api/availability-collections/{$s['collectionId']}/acknowledge", ['noUnavailability' => true], $s['alice']);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/extensions", ['endsAt' => '2027-07-01'], $s['creator']);
        self::assertResponseIsSuccessful();
        $collections = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/availability-collections", token: $s['creator']);
        $newWindow = array_values(array_filter($collections, static fn (array $c): bool => '2027-05-01' === $c['startsAt']))[0];

        $this->remind($client, $s, 'alice@example.com', $s['creator']);

        self::assertResponseStatusCodeSame(201);
        $html = (string) self::getMailerMessage(0)->getHtmlBody();
        self::assertStringContainsString('1er mai 2027 au 30 juin 2027', $html, 'Alice already answered January–April: only the new window is asked.');
        self::assertStringContainsString('/my-availability?collection='.$newWindow['stableId'], $html);
    }
}
