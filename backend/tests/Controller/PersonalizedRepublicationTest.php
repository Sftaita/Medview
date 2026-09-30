<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Planning;
use App\Entity\PlanningPublication;
use App\Entity\PlanningPublicationNotification;
use App\Entity\PublicationNotificationStatus;
use App\Entity\User;
use App\Repository\PlanningPublicationDocumentRepository;
use App\Repository\PlanningPublicationNotificationRepository;
use App\Repository\PlanningPublicationRepository;
use App\Repository\PlanningRepository;
use App\Repository\UserRepository;
use App\Service\PlanningPdfRenderer;
use App\Service\PlanningPublicationService;
use App\Service\PublicationNotificationSender;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

/**
 * Personalised republication emails (docs/decisions.md D172): the current
 * calendar is compared with the last publication, only the people whose own
 * duties changed are emailed — once each, with their own changes and the PDF
 * of exactly the republished version — and every email is delivered at most
 * once, retried after a failure, never silently lost.
 */
final class PersonalizedRepublicationTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;

    private const STANDALONE = [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07'], ['2027-01-07', '2027-01-08']];

    /** The listener refusing emails for failFor(), if any. */
    private ?\Closure $failingListener = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    // --- who is told what ------------------------------------------------------------------------------------

    /**
     * One person holding memberships in two lines (docs/decisions.md D160) loses a duty on one line and gains one
     * on the other: ONE email, with both changes — the diff is by User, never by membership.
     */
    public function testSeveralChangesOfOnePersonAcrossTwoLinesMakeOneEmail(): void
    {
        [$client, $s] = $this->twoLinesWithAliceOnBoth();

        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-05'), 'bob@example.com');
        self::assertResponseIsSuccessful();
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-07', 1), 'alice@example.com', lineIndex: 1);
        self::assertResponseIsSuccessful();

        $response = $this->republish($client, $s);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $response['publication']['changedDutyCount']);

        self::assertSame(['alice@example.com', 'bob@example.com', 'junior@example.com'], $this->emailedAddresses());
        $alice = $this->bodyFor('alice@example.com');
        self::assertStringContainsString("GARDE RETIRÉE\n\n- Mardi 5 janvier 2027 — Seniors", $alice);
        self::assertStringContainsString("GARDE AJOUTÉE\n\n- Jeudi 7 janvier 2027 — Juniors", $alice);
        self::assertStringContainsString("GARDE AJOUTÉE\n\n- Mardi 5 janvier 2027 — Seniors", $this->bodyFor('bob@example.com'));
        self::assertStringContainsString("GARDE RETIRÉE\n\n- Jeudi 7 janvier 2027 — Juniors", $this->bodyFor('junior@example.com'));
        self::assertStringNotContainsString('Juniors', $this->bodyFor('bob@example.com'), 'Bob\'s email says nothing of the other line\'s change.');

        $notifications = $this->notificationsOf($this->latestPublication($s['planningId']));
        self::assertCount(3, $notifications, 'One notification per person, whatever their number of changes.');
    }

    /**
     * Two duties edited, one of them put back as published: only the change that remains is announced, to the
     * people it concerns — an edit undone before the republication tells nobody anything.
     */
    public function testAnEditUndoneBeforeTheRepublicationTellsNobody(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->forceHolders($client, $s, ['2027-01-05' => 'alice@example.com', '2027-01-06' => 'alice@example.com', '2027-01-07' => 'admin@example.com']);
        $this->publish($client, $s);
        self::assertResponseIsSuccessful();

        $tuesday = $this->dutyOn($s['planningId'], '2027-01-05');
        $this->reassignTo($client, $s, $tuesday, 'bob@example.com');
        $this->reassignTo($client, $s, $tuesday, 'admin@example.com');
        $this->reassignTo($client, $s, $tuesday, 'alice@example.com'); // back to what was published
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-07'), 'bob@example.com');

        $response = $this->republish($client, $s);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $response['publication']['changedDutyCount']);
        self::assertSame(['admin@example.com', 'bob@example.com'], $this->emailedAddresses(), 'Alice\'s A → B → A is no change: not told.');
        self::assertStringNotContainsString('5 janvier', $this->bodyFor('admin@example.com'), 'Admin held Tuesday for a moment only: never published, never announced.');
    }

    public function testARepublicationWithNoEffectiveDifferenceSendsNothingAndRecordsNothing(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->forceHolders($client, $s, ['2027-01-06' => 'alice@example.com']);
        $this->publish($client, $s);
        $publications = \count($this->publicationsOf($s['planningId']));

        $wednesday = $this->dutyOn($s['planningId'], '2027-01-06');
        $this->reassignTo($client, $s, $wednesday, 'bob@example.com');
        $this->unassignDuty($client, $s, $wednesday);
        $this->reassignTo($client, $s, $wednesday, 'alice@example.com');

        $response = $this->republish($client, $s);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_changes', $response['error']);
        self::assertEmailCount(0);
        self::assertCount($publications, $this->publicationsOf($s['planningId']));
    }

    public function testADeactivatedFormerHolderIsNeverEmailed(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        // Alice holds only Wednesday: a deactivated account still holding a duty would block the republication.
        $this->forceHolders($client, $s, ['2027-01-05' => 'admin@example.com', '2027-01-06' => 'alice@example.com', '2027-01-07' => 'admin@example.com']);
        $this->publish($client, $s);

        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'), 'bob@example.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->user('alice@example.com')->setActive(false);
        $em->flush();

        $response = $this->republish($client, $s);
        self::assertResponseIsSuccessful();
        self::assertSame(['bob@example.com'], $this->emailedAddresses());
        self::assertSame(1, $response['recipientCount']);
    }

    // --- one version for the entries, the emails and the PDF ---------------------------------------------------

    public function testTheAttachedPdfIsTheRepublishedVersion(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->forceHolders($client, $s, ['2027-01-06' => 'alice@example.com']);
        $this->publish($client, $s);
        $first = $this->latestPublication($s['planningId']);

        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'), 'bob@example.com');
        $this->republish($client, $s);
        self::assertResponseIsSuccessful();
        $republished = $this->latestPublication($s['planningId']);
        self::assertNotSame($first->getId(), $republished->getId());

        $renderer = static::getContainer()->get(PlanningPdfRenderer::class);
        $expected = $this->storedPdf($republished);
        self::assertNotSame(self::normalizedPdf($this->storedPdf($first)), self::normalizedPdf($expected), 'Precondition: the two versions differ.');
        self::assertSame(self::normalizedPdf($renderer->render($republished)), self::normalizedPdf($expected), 'Stored = that version\'s rendering.');
        foreach (self::getMailerMessages() as $message) {
            self::assertInstanceOf(Email::class, $message);
            self::assertSame($expected, $message->getAttachments()[0]->getBody(), 'The republished version, byte for byte.');
        }

        // And what that version says: Bob on Wednesday — the PDF lists every date, line and holder, nothing else.
        $days = array_merge(...array_column($renderer->view($republished)['weeks'], 'days'));
        self::assertSame('Mer 06/01', $days[5]['label']);
        self::assertSame($this->fullName('bob@example.com'), $days[5]['cells'][0][0]['name']);
    }

    /**
     * An email retried after a later edit still says what was republished — its frozen changes and the PDF of its
     * own publication — never the live calendar.
     */
    public function testARetryAfterALaterEditSendsTheFrozenVersion(): void
    {
        [$client, $s] = $this->publishedThenReassigned('alice@example.com', 'bob@example.com');

        $this->failFor('bob@example.com');
        $outcome = $this->publicationService()->republish($this->planning($s['planningId']), $this->user('creator@example.com'));
        self::assertSame(2, $outcome->recipientCount);
        self::assertSame(1, $outcome->sentCount);
        $republished = $outcome->publication;
        $bobs = $this->notificationFor($republished, 'bob@example.com');
        self::assertSame(PublicationNotificationStatus::FAILED, $bobs->getStatus());

        // Edited again before the retry: Wednesday now goes to admin (unpublished).
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'), 'admin@example.com');
        $this->stopFailing();
        $before = \count(self::getMailerMessages());
        self::assertSame(['attempted' => 1, 'sent' => 1, 'failed' => 0], $this->sender()->retryDue());

        $messages = \array_slice(self::getMailerMessages(), $before);
        self::assertCount(1, $messages);
        self::assertInstanceOf(Email::class, $messages[0]);
        self::assertSame('bob@example.com', $messages[0]->getTo()[0]->getAddress());
        self::assertStringContainsString("GARDE AJOUTÉE\n\n- Mercredi 6 janvier 2027 — Seniors", (string) $messages[0]->getTextBody());
        self::assertSame($this->storedPdf($republished), $messages[0]->getAttachments()[0]->getBody(), 'The PDF of the republished version, not of the live calendar.');
    }

    /**
     * docs/decisions.md D173: the PDF sent by a retry is the one generated with the publication — its period,
     * dates, lines, holders and planning name — even after the planning was extended, renamed and edited.
     */
    public function testARetriedEmailKeepsThePdfAndPeriodOfItsPublicationAfterAnExtensionARenameAndAnEdit(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->forceHolders($client, $s, ['2027-01-06' => 'alice@example.com']);

        $this->failFor('alice@example.com');
        $outcome = $this->publicationService()->publish($this->planning($s['planningId']), $this->user('creator@example.com'));
        self::assertSame(2, $outcome->sentCount);
        $publication = $outcome->publication;
        $stored = static::getContainer()->get(PlanningPublicationDocumentRepository::class)->findOneByPublication($publication);
        self::assertNotNull($stored);
        $original = $stored->getContent();
        self::assertSame(hash('sha256', $original), $stored->getSha256());
        self::assertSame(['2027-01-01', '2027-04-30', 'Gardes Seniors'], [$stored->getPeriodFirstDay()->format('Y-m-d'), $stored->getPeriodLastDay()->format('Y-m-d'), $stored->getPlanningName()]);

        // After the failure: the planning is extended by three months, renamed, and Wednesday changes hands.
        // Extending a published planning is refused by the API today (planning_period_locked, D122)…
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/extensions", ['endsAt' => '2027-08-01'], $s['creator']);
        self::assertResponseStatusCodeSame(409);
        // …so its period is moved the way PlanningExtensionService does it: whatever lifts that lock later, the
        // publication's PDF must not follow.
        $planning = $this->planning($s['planningId']);
        $planning->extendTo($planning->getStartsAt(), new \DateTimeImmutable('2027-08-01', $planning->getStartsAt()->getTimezone()));
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->api($client, 'PATCH', "/api/plannings/{$s['planningId']}", ['name' => 'Gardes renommées'], $s['creator']);
        self::assertResponseIsSuccessful();
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'), 'bob@example.com');
        self::assertResponseIsSuccessful();

        // Precondition: rendering it today would give another file — more days, another name.
        $renderer = static::getContainer()->get(PlanningPdfRenderer::class);
        $publication = $this->latestPublication($s['planningId']); // re-read: the planning as it is now
        $today = $renderer->view($publication);
        self::assertSame('Du 1er janvier 2027 au 31 juillet 2027', $today['periodLabel']);
        self::assertNotSame(self::normalizedPdf($original), self::normalizedPdf($renderer->render($publication)));

        $this->stopFailing();
        $before = \count(self::getMailerMessages());
        self::assertSame(['attempted' => 1, 'sent' => 1, 'failed' => 0], $this->sender()->retryDue());
        $messages = \array_slice(self::getMailerMessages(), $before);
        self::assertCount(1, $messages);
        $email = $messages[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('alice@example.com', $email->getTo()[0]->getAddress());
        self::assertSame($original, $email->getAttachments()[0]->getBody(), 'The PDF generated with the publication, byte for byte.');
        self::assertSame('planning-gardes-seniors.pdf', $email->getAttachments()[0]->getFilename());
        self::assertSame('Planning de garde disponible — Gardes Seniors', $email->getSubject(), 'The name it was published under.');
        self::assertStringContainsString('du 1er janvier 2027 au 30 avril 2027 est disponible', (string) $email->getTextBody(), 'The published period, not the extended one.');

        // "Télécharger le PDF" serves that same file too.
        $client->request('GET', "/api/plannings/{$s['planningId']}/publication.pdf", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$s['alice']]);
        self::assertResponseIsSuccessful();
        self::assertSame($original, (string) $client->getResponse()->getContent());
    }

    // --- no parallel or repeated send, no silent loss, retry after an uncertain SMTP outcome ---------------------

    public function testARepeatedRequestNeverEmailsAnyoneTwice(): void
    {
        [$client, $s] = $this->publishedThenReassigned('alice@example.com', 'bob@example.com');

        $this->republish($client, $s);
        self::assertResponseIsSuccessful();
        self::assertSame(['alice@example.com', 'bob@example.com'], $this->emailedAddresses());

        // The same click again (a double click, a replayed request): nothing left to announce.
        $response = $this->republish($client, $s);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_changes', $response['error']);
        self::assertEmailCount(0);

        // And the sender itself never sends a SENT notification again, nor does the retry command.
        $publication = $this->latestPublication($s['planningId']);
        self::assertSame(['recipientCount' => 2, 'sentCount' => 2], $this->sender()->sendForPublication($publication));
        self::assertSame(['attempted' => 0, 'sent' => 0, 'failed' => 0], $this->sender()->retryDue());
        self::assertEmailCount(0);
        foreach ($this->notificationsOf($publication) as $notification) {
            self::assertSame(1, $notification->getAttempts());
        }
    }

    public function testAFailedEmailIsRetriedOnlyForItsRecipientAndReportedMeanwhile(): void
    {
        [$client, $s] = $this->publishedThenReassigned('alice@example.com', 'bob@example.com');

        $this->failFor('alice@example.com');
        $outcome = $this->publicationService()->republish($this->planning($s['planningId']), $this->user('creator@example.com'));
        self::assertSame(1, $outcome->sentCount, 'The publication itself is recorded: an email failure never undoes it.');

        $history = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/publication-state", token: $s['creator'])['history'];
        self::assertSame(['recipientCount' => 2, 'sentCount' => 1, 'failedCount' => 1], array_intersect_key(end($history), array_flip(['recipientCount', 'sentCount', 'failedCount'])));

        $this->stopFailing();
        $before = \count(self::getMailerMessages());
        self::assertSame(['attempted' => 1, 'sent' => 1, 'failed' => 0], $this->sender()->retryDue());
        $messages = \array_slice(self::getMailerMessages(), $before);
        self::assertCount(1, $messages, 'Bob, already told, is not emailed again.');
        self::assertInstanceOf(Email::class, $messages[0]);
        self::assertSame('alice@example.com', $messages[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('GARDE RETIRÉE', (string) $messages[0]->getTextBody());

        $alices = $this->notificationFor($outcome->publication, 'alice@example.com');
        self::assertSame(PublicationNotificationStatus::SENT, $alices->getStatus());
        self::assertSame(2, $alices->getAttempts());
        self::assertSame(['attempted' => 0, 'sent' => 0, 'failed' => 0], $this->sender()->retryDue(), 'Nothing left.');
    }

    public function testAnEmailThatKeepsFailingIsGivenUpAfterMaxAttemptsAndStaysVisible(): void
    {
        [$client, $s] = $this->publishedThenReassigned('alice@example.com', 'bob@example.com');

        $this->failFor('alice@example.com');
        $outcome = $this->publicationService()->republish($this->planning($s['planningId']), $this->user('creator@example.com'));
        for ($i = 1; $i < PlanningPublicationNotification::MAX_ATTEMPTS; ++$i) {
            self::assertSame(['attempted' => 1, 'sent' => 0, 'failed' => 1], $this->sender()->retryDue());
        }
        self::assertSame(['attempted' => 0, 'sent' => 0, 'failed' => 0], $this->sender()->retryDue(), 'Given up: never retried forever.');

        $alices = $this->notificationFor($outcome->publication, 'alice@example.com');
        self::assertSame(PublicationNotificationStatus::FAILED, $alices->getStatus());
        self::assertSame(PlanningPublicationNotification::MAX_ATTEMPTS, $alices->getAttempts());
        $history = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/publication-state", token: $s['creator'])['history'];
        self::assertSame(1, end($history)['failedCount'], 'Never a silent loss: the history still says one email did not go out.');
    }

    /**
     * The request died between the commit and the sending (the notifications are PENDING): the retry command sends
     * them — but not while the request that recorded them may still be sending them.
     */
    public function testNotificationsLeftPendingByADeadRequestAreSentByTheRetry(): void
    {
        [$client, $s] = $this->publishedThenReassigned('alice@example.com', 'bob@example.com');
        $this->republish($client, $s);
        self::assertResponseIsSuccessful();
        $publication = $this->latestPublication($s['planningId']);
        // As if the process had died right after the commit: nothing attempted yet.
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "UPDATE planning_publication_notifications SET status = 'PENDING', attempts = 0, sent_at = NULL, claimed_at = NULL WHERE publication_id = :id",
            ['id' => $publication->getId()],
        );

        self::assertSame(['attempted' => 0, 'sent' => 0, 'failed' => 0], $this->sender()->retryDue(), 'Too recent: the request may still be sending them.');
        self::mockTime(Clock::get()->now()->modify('+3 minutes'));
        $before = \count(self::getMailerMessages());
        self::assertSame(['attempted' => 2, 'sent' => 2, 'failed' => 0], $this->sender()->retryDue());
        self::assertCount(2, \array_slice(self::getMailerMessages(), $before));
    }

    public function testASenderThatDiedMidSendIsTakenOverOnlyOnceItsClaimIsStale(): void
    {
        [$client, $s] = $this->publishedThenReassigned('alice@example.com', 'bob@example.com');
        $this->republish($client, $s);
        $publication = $this->latestPublication($s['planningId']);
        $alices = $this->notificationFor($publication, 'alice@example.com');
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "UPDATE planning_publication_notifications SET status = 'SENDING', sent_at = NULL, claimed_at = :at WHERE id = :id",
            ['at' => Clock::get()->now()->format('Y-m-d H:i:s'), 'id' => $alices->getId()],
        );

        $repository = static::getContainer()->get(PlanningPublicationNotificationRepository::class);
        self::assertFalse($repository->claim($alices, Clock::get()->now(), Clock::get()->now()->modify('-15 minutes')), 'A fresh claim belongs to its sender.');
        self::assertSame(['attempted' => 0, 'sent' => 0, 'failed' => 0], $this->sender()->retryDue());

        self::mockTime(Clock::get()->now()->modify('+16 minutes'));
        self::assertSame(['attempted' => 1, 'sent' => 1, 'failed' => 0], $this->sender()->retryDue());
    }

    // --- first publication ---------------------------------------------------------------------------------------

    public function testTheFirstPublicationStillEmailsEveryParticipantWithThePdfOfWhatWasPublished(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->publish($client, $s);
        self::assertResponseIsSuccessful();

        self::assertSame(['admin@example.com', 'alice@example.com', 'bob@example.com'], $this->emailedAddresses());
        $expected = $this->storedPdf($this->latestPublication($s['planningId']));
        foreach (self::getMailerMessages() as $message) {
            self::assertInstanceOf(Email::class, $message);
            self::assertStringStartsWith('Planning de garde disponible', (string) $message->getSubject());
            self::assertCount(1, $message->getAttachments());
            self::assertSame($expected, $message->getAttachments()[0]->getBody());
            self::assertStringContainsString('vous recevrez un email détaillant vos seuls changements', (string) $message->getTextBody());
        }
    }

    // --- helpers ---------------------------------------------------------------------------------------------------

    /**
     * The pilot scenario with distinct names — the PDF and the emails are checked by the names they show.
     *
     * @return array<string, mixed>
     */
    private function scenario(KernelBrowser $client): array
    {
        $s = $this->pilotScenario($client);
        foreach (['admin' => ['Anne', 'Admin'], 'alice' => ['Alice', 'Bernard'], 'bob' => ['Bob', 'Claes']] as $who => [$first, $last]) {
            $user = $this->user($who.'@example.com');
            $user->setFirstName($first);
            $user->setLastName($last);
        }
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $s;
    }

    /**
     * Primary line (Seniors: admin, alice, bob) with duties on the 5th and 6th, secondary line (Juniors: alice and
     * junior) with duties on the 7th and 8th — alice holds the 5th (Seniors), junior both junior duties; published.
     *
     * @return array{0: KernelBrowser, 1: array<string, mixed>}
     */
    private function twoLinesWithAliceOnBoth(): array
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $juniorTeam = $this->addSecondaryLine($client, $s, ['junior@example.com']);
        // Alice already has an account and a Seniors membership: a second membership, same User (D160).
        $this->addMemberAs($client, $s['creator'], $s['planningId'], $juniorTeam, 'alice@example.com', 'MEMBER');
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07']]);
        $this->prepareLine($s['planningId'], [['2027-01-07', '2027-01-08'], ['2027-01-08', '2027-01-09']], lineIndex: 1);
        $this->generate($client, $s);
        $this->forceHolders($client, $s, ['2027-01-05' => 'alice@example.com', '2027-01-06' => 'admin@example.com']);
        $this->forceHolders($client, $s, ['2027-01-07' => 'junior@example.com', '2027-01-08' => 'junior@example.com'], 1);
        $this->publish($client, $s);
        self::assertResponseIsSuccessful();

        return [$client, $s];
    }

    /**
     * Published with $from on Wednesday the 6th, then reassigned to $to (not republished).
     *
     * @return array{0: KernelBrowser, 1: array<string, mixed>}
     */
    private function publishedThenReassigned(string $from, string $to): array
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->forceHolders($client, $s, ['2027-01-06' => $from]);
        $this->publish($client, $s);
        self::assertResponseIsSuccessful();
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'), $to);
        self::assertResponseIsSuccessful();

        return [$client, $s];
    }

    /**
     * Before publication: sets the holder of line $lineIndex's duty on each date (a reassignment, like the UI).
     *
     * @param array<string, mixed>  $s
     * @param array<string, string> $holders date → email
     */
    private function forceHolders(KernelBrowser $client, array $s, array $holders, int $lineIndex = 0): void
    {
        $calendar = $this->currentCalendar($s['planningId']);
        foreach ($holders as $date => $email) {
            if (($calendar["{$lineIndex}|{$date}|ONCALL"] ?? null) === $email) {
                continue;
            }
            $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], $date, $lineIndex), $email, lineIndex: $lineIndex);
            self::assertResponseIsSuccessful();
        }
    }

    /** Makes the mail transport refuse every email to $address (until stopFailing()). */
    private function failFor(string $address): void
    {
        $this->stopFailing();
        $this->failingListener = static function (MessageEvent $event) use ($address): void {
            $message = $event->getMessage();
            if ($message instanceof Email && $message->getTo()[0]->getAddress() === $address) {
                throw new TransportException('SMTP refused '.$address);
            }
        };
        // Before the test mailer's own logger, so a refused email is never counted as sent.
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(MessageEvent::class, $this->failingListener, 1000);
    }

    private function stopFailing(): void
    {
        if (null !== $this->failingListener) {
            static::getContainer()->get(EventDispatcherInterface::class)->removeListener(MessageEvent::class, $this->failingListener);
            $this->failingListener = null;
        }
    }

    private function storedPdf(PlanningPublication $publication): string
    {
        $document = static::getContainer()->get(PlanningPublicationDocumentRepository::class)->findOneByPublication($publication);
        self::assertNotNull($document, 'Every publication stores its PDF (D173).');

        return $document->getContent();
    }

    /** dompdf stamps the rendering time and a random file id — everything else is the content. */
    private static function normalizedPdf(string $pdf): string
    {
        self::assertStringStartsWith('%PDF', $pdf);

        return (string) preg_replace(['~/(CreationDate|ModDate) \(D:[^)]*\)~', '~/ID\s*\[<[0-9a-fA-F]+>\s*<[0-9a-fA-F]+>\]~'], ['/$1 ()', '/ID []'], $pdf);
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function publish(KernelBrowser $client, array $s): array
    {
        return $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function republish(KernelBrowser $client, array $s): array
    {
        return $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/republish", [], $s['creator']);
    }

    private function publicationService(): PlanningPublicationService
    {
        return static::getContainer()->get(PlanningPublicationService::class);
    }

    private function sender(): PublicationNotificationSender
    {
        return static::getContainer()->get(PublicationNotificationSender::class);
    }

    private function planning(string $stableId): Planning
    {
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($stableId);
        self::assertNotNull($planning);

        return $planning;
    }

    private function user(string $email): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
        self::assertNotNull($user);

        return $user;
    }

    private function fullName(string $email): string
    {
        return $this->user($email)->getFirstName().' '.$this->user($email)->getLastName();
    }

    /**
     * @return list<PlanningPublication>
     */
    private function publicationsOf(string $planningStableId): array
    {
        return static::getContainer()->get(PlanningPublicationRepository::class)->findByPlanning($this->planning($planningStableId));
    }

    private function latestPublication(string $planningStableId): PlanningPublication
    {
        $publication = static::getContainer()->get(PlanningPublicationRepository::class)->findLatestForPlanning($this->planning($planningStableId));
        self::assertNotNull($publication);

        return $publication;
    }

    /**
     * @return list<PlanningPublicationNotification>
     */
    private function notificationsOf(PlanningPublication $publication): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $notifications = static::getContainer()->get(PlanningPublicationNotificationRepository::class)->findByPublication($publication);
        foreach ($notifications as $notification) {
            $em->refresh($notification);
        }

        return $notifications;
    }

    private function notificationFor(PlanningPublication $publication, string $email): PlanningPublicationNotification
    {
        foreach ($this->notificationsOf($publication) as $notification) {
            if ($notification->getUser()->getEmail() === $email) {
                return $notification;
            }
        }

        self::fail("No notification for {$email}.");
    }

    /**
     * @return list<string> sorted recipient addresses of the emails sent so far in this request/test
     */
    private function emailedAddresses(): array
    {
        $addresses = array_map(static fn (Email $email): string => $email->getTo()[0]->getAddress(), array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email)));
        sort($addresses);

        return $addresses;
    }

    private function bodyFor(string $address): string
    {
        $bodies = [];
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email && $message->getTo()[0]->getAddress() === $address) {
                $bodies[] = (string) $message->getTextBody();
            }
        }
        self::assertCount(1, $bodies, "Exactly one email to {$address}.");

        return $bodies[0];
    }
}
