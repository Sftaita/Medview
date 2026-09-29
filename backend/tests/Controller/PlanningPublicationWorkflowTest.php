<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PlanningPublicationKind;
use App\Repository\PlanningPublicationDeliveryRepository;
use App\Repository\PlanningPublicationEntryRepository;
use App\Repository\PlanningPublicationRepository;
use App\Repository\PlanningRepository;
use App\Service\PlanningPdfRenderer;
use App\Tests\CalendarWorkflowTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;

/**
 * Publication and republication (docs/decisions.md D143): what was
 * diffused is recorded duty by duty, the PDF comes from that record, a
 * later edit only marks the planning "Modifications non publiées", and a
 * republication emails exactly the people concerned by an impacted date.
 */
final class PlanningPublicationWorkflowTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;

    private const STANDALONE = [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07'], ['2027-01-07', '2027-01-08']];

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    // --- first publication -----------------------------------------------------------

    public function testFirstPublicationRecordsWhatWasPublishedAndEmailsEveryParticipantWithThePdf(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $calendar = $this->currentCalendar($s['planningId']);

        $response = $this->publishPlanning($client, $s);
        self::assertResponseIsSuccessful();
        self::assertSame('FIRST', $response['publication']['kind']);
        self::assertSame(3, $response['recipientCount'], 'admin, alice and bob — every participant, duty or not.');
        self::assertSame(3, $response['sentCount']);

        $messages = self::getMailerMessages();
        self::assertCount(3, $messages);
        $recipients = array_map(static fn (Email $email): string => $email->getTo()[0]->getAddress(), $messages);
        sort($recipients);
        self::assertSame(['admin@example.com', 'alice@example.com', 'bob@example.com'], $recipients);

        $email = $messages[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertStringContainsString('Planning de garde disponible', (string) $email->getSubject());
        self::assertStringContainsString('du 1er janvier 2027 au 30 avril 2027 est disponible', (string) $email->getTextBody());
        self::assertStringContainsString('Gardes Seniors', (string) $email->getTextBody());
        $attachments = $email->getAttachments();
        self::assertCount(1, $attachments);
        self::assertSame('application/pdf', $attachments[0]->getMediaType().'/'.$attachments[0]->getMediaSubtype());
        self::assertStringStartsWith('%PDF', $attachments[0]->getBody());

        $publication = $this->latestPublication($s['planningId']);
        self::assertSame(PlanningPublicationKind::FIRST, $publication->getKind());
        $entries = static::getContainer()->get(PlanningPublicationEntryRepository::class)->findByPublication($publication);
        self::assertCount(3, $entries, 'One entry per duty.');
        foreach ($entries as $entry) {
            $key = '0|'.$entry->getDuty()->getLocalDate()->format('Y-m-d').'|ONCALL';
            self::assertSame($calendar[$key], $entry->getTeamMember()?->getUser()->getEmail());
        }
        self::assertCount(3, static::getContainer()->get(PlanningPublicationDeliveryRepository::class)->findByPublication($publication));

        $state = $this->state($client, $s);
        self::assertTrue($state['published']);
        self::assertNotNull($state['lastPublishedAt']);
        self::assertFalse($state['hasUnpublishedChanges']);
    }

    public function testThePdfShowsEveryDateEveryLineThePeopleAndTheBlocks(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->addSecondaryLine($client, $s, ['junior@example.com']);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], ['2027-01-08', '2027-01-09', '2027-01-10']);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: 1);
        $this->generate($client, $s);
        $this->publishPlanning($client, $s);
        self::assertResponseIsSuccessful();

        $view = static::getContainer()->get(PlanningPdfRenderer::class)->view($this->latestPublication($s['planningId']));
        self::assertSame(['Seniors', 'Juniors'], array_column($view['lines'], 'name'));
        $days = array_merge(...array_column($view['weeks'], 'days'));
        self::assertCount(120, $days, 'Every date of 2027-01-01 → 2027-04-30.');
        self::assertSame('Ven 01/01', $days[0]['label']);
        self::assertStringStartsWith('Semaine du 28 décembre 2026', $view['weeks'][0]['label']);

        $tuesday = $days[4];
        self::assertSame('Mar 05/01', $tuesday['label']);
        self::assertSame('Test User', $tuesday['cells'][0][0]['name']);
        self::assertSame('Test User', $tuesday['cells'][1][0]['name']);
        self::assertSame([], $days[5]['cells'][0], 'No duty on the 6th: an empty cell, never a fabricated one.');

        $friday = $days[7];
        $saturday = $days[8];
        self::assertSame('first', $friday['cells'][0][0]['blockPart']);
        self::assertSame('last', $saturday['cells'][0][0]['blockPart']);
        self::assertSame('Test group', $friday['cells'][0][0]['block']);

        $client->request('GET', "/api/plannings/{$s['planningId']}/publication.pdf", server: $this->bearer($s['alice']));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('attachment; filename=planning-gardes-seniors.pdf', (string) $client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testThePdfIsThePublishedStateNeverTheUnpublishedEdits(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->publishPlanning($client, $s);
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'));

        $view = static::getContainer()->get(PlanningPdfRenderer::class)->view($this->latestPublication($s['planningId']));
        $days = array_merge(...array_column($view['weeks'], 'days'));
        self::assertNotNull($days[5]['cells'][0][0]['name'], 'Still the published holder, not the live removal.');
    }

    public function testNoPdfBeforeTheFirstPublication(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $client->request('GET', "/api/plannings/{$s['planningId']}/publication.pdf", server: $this->bearer($s['creator']));
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', "/api/plannings/{$s['planningId']}/publication.pdf", server: $this->bearer($s['outsider']));
        self::assertResponseStatusCodeSame(403);
    }

    // --- edits after publication -------------------------------------------------------

    public function testAnEditAfterPublicationSendsNoEmailAndMarksUnpublishedChanges(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->publishPlanning($client, $s);

        $holder = $this->currentCalendar($s['planningId'])['0|2027-01-06|ONCALL'];
        $replacement = 'alice@example.com' === $holder ? 'bob@example.com' : 'alice@example.com';
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'), $replacement);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(0, message: 'A modification never emails anyone by itself.');

        $state = $this->state($client, $s);
        self::assertTrue($state['published']);
        self::assertTrue($state['hasUnpublishedChanges']);
        self::assertCount(1, $state['changes']);
        self::assertSame('2027-01-06', $state['changes'][0]['date']);
        self::assertSame('PUBLISHED', $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'][0]['periodStatus']);
    }

    /**
     * The production case of 2026-09-29, anonymised: after the publication, holes are made (a removal), and the
     * holder of another duty declares an unavailability covering it. The removal alone would be republishable
     * (D143); the now-invalid assignment is not — and the preflight says which duty, which dates, which rule.
     */
    public function testAnUnavailabilityDeclaredAfterPublicationBlocksTheRepublicationAndIsLocalized(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->publishPlanning($client, $s);
        self::assertResponseIsSuccessful();

        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-05'));
        self::assertResponseIsSuccessful();
        $holder = $this->currentCalendar($s['planningId'])['0|2027-01-07|ONCALL'];
        self::assertNotNull($holder);
        // The holder's own token: 'alice@example.com' → $s['alice'].
        $this->declareRange($client, $s[strstr($holder, '@', true)], '2027-01-07', '2027-01-08');

        $preflight = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/publication-preflight", token: $s['creator']);
        self::assertFalse($preflight['republishable']);
        self::assertCount(1, $preflight['uncoveredDuties'], 'The removal is listed, but does not block a republication.');
        self::assertCount(1, $preflight['invalidAssignments']);
        $item = $preflight['invalidAssignments'][0];
        self::assertSame($this->dutyOn($s['planningId'], '2027-01-07'), $item['duty']['dutyStableId']);
        self::assertSame(['2027-01-07'], $item['dates']);
        self::assertSame([$item['duty']['dutyStableId']], $item['dutyStableIds']);
        self::assertSame('UNAVAILABLE', $item['reasonCode']);
        self::assertSame('indisponible', $item['reason']);
        self::assertSame('Seniors', $item['duty']['lineName']);

        $response = $this->republishPlanning($client, $s);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_publishable', $response['error']);

        // Fixing that one assignment is enough: the removal itself stays announceable.
        $this->unassignDuty($client, $s, $item['duty']['dutyStableId']);
        self::assertResponseIsSuccessful();
        $preflight = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/publication-preflight", token: $s['creator']);
        self::assertSame([], $preflight['invalidAssignments']);
        self::assertTrue($preflight['republishable']);
    }

    public function testRepublicationIsRefusedWhenNothingChangedEvenAfterAnEditAndItsUndo(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->publishPlanning($client, $s);

        $response = $this->republishPlanning($client, $s);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_changes', $response['error']);

        $holder = $this->currentCalendar($s['planningId'])['0|2027-01-06|ONCALL'];
        $replacement = 'alice@example.com' === $holder ? 'bob@example.com' : 'alice@example.com';
        $duty = $this->dutyOn($s['planningId'], '2027-01-06');
        $this->reassignTo($client, $s, $duty, $replacement);
        $this->reassignTo($client, $s, $duty, $holder);

        self::assertFalse($this->state($client, $s)['hasUnpublishedChanges'], 'A → B → A is no change for anyone.');
        $this->republishPlanning($client, $s);
        self::assertResponseStatusCodeSame(409);
        self::assertCount(1, static::getContainer()->get(PlanningPublicationRepository::class)->findByPlanning($this->planningEntity($s['planningId'])), 'Nothing recorded.');
    }

    public function testRepublicationAudienceIsOldAndNewHolderPlusEveryoneOnDutyThatDayOnOtherLines(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->addSecondaryLine($client, $s, ['junior@example.com', 'junior2@example.com']);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07']]);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06'], ['2027-01-06', '2027-01-07']], lineIndex: 1);
        $this->generate($client, $s);
        $this->publishPlanning($client, $s);

        $calendar = $this->currentCalendar($s['planningId']);
        $oldHolder = $calendar['0|2027-01-06|ONCALL'];
        $newHolder = $this->someoneElse($oldHolder, $calendar['0|2027-01-05|ONCALL']);
        $colleagueSameDay = $calendar['1|2027-01-06|ONCALL'];
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'), $newHolder);
        self::assertResponseIsSuccessful();

        $response = $this->republishPlanning($client, $s);
        self::assertResponseIsSuccessful();
        self::assertSame('UPDATE', $response['publication']['kind']);
        self::assertSame(1, $response['publication']['changedDutyCount']);

        $expected = array_values(array_unique([$oldHolder, $newHolder, $colleagueSameDay]));
        sort($expected);
        self::assertSame($expected, $this->emailedAddresses(), 'Old holder, new holder, and the other line\'s person on duty that day — nobody else.');

        $body = (string) self::getMailerMessages()[0]->getTextBody();
        self::assertStringContainsString('Modification du planning de garde', $body);
        self::assertStringContainsString('Mercredi 6 janvier 2027', $body);
        self::assertStringContainsString('Seniors : Test User → Test User', $body);
        self::assertStringContainsString('Juniors : Test User — inchangé', $body);
        foreach (self::getMailerMessages() as $message) {
            self::assertCount(0, $message->getAttachments(), 'No PDF on a republication.');
        }
    }

    public function testRepublishingAChangedBlockInformsColleaguesOfEveryDayOfTheBlock(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->addSecondaryLine($client, $s, ['junior@example.com', 'junior2@example.com', 'junior3@example.com']);
        $this->prepareLine($s['planningId'], [], ['2027-01-08', '2027-01-09', '2027-01-10']);
        // Other line: one person on Friday, another on Saturday, a third on Sunday (outside the block).
        $this->prepareLine($s['planningId'], [['2027-01-08', '2027-01-09'], ['2027-01-09', '2027-01-10'], ['2027-01-10', '2027-01-11']], lineIndex: 1);
        $this->generate($client, $s);
        $this->publishPlanning($client, $s);

        $calendar = $this->currentCalendar($s['planningId']);
        $blockHolder = $this->blockHolder($calendar);
        $newHolder = $this->someoneElse($blockHolder);
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-09'), $newHolder);
        $this->republishPlanning($client, $s);
        self::assertResponseIsSuccessful();

        $expected = array_values(array_unique([$blockHolder, $newHolder, $calendar['1|2027-01-08|ONCALL'], $calendar['1|2027-01-09|ONCALL']]));
        sort($expected);
        self::assertSame($expected, $this->emailedAddresses(), 'Both days of the block are impacted — Sunday\'s other-line colleague is not.');
        $body = (string) self::getMailerMessages()[0]->getTextBody();
        self::assertStringContainsString('du vendredi 8 au samedi 9 janvier 2027 (bloc Test group)', $body);
    }

    public function testARemovalWithoutReplacementIsRepublishedToTheRemovedPersonAndColleagues(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->addSecondaryLine($client, $s, ['junior@example.com']);
        $this->prepareLine($s['planningId'], [['2027-01-06', '2027-01-07']]);
        $this->prepareLine($s['planningId'], [['2027-01-06', '2027-01-07']], lineIndex: 1);
        $this->generate($client, $s);
        $this->publishPlanning($client, $s);
        $calendar = $this->currentCalendar($s['planningId']);

        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'));
        $state = $this->state($client, $s);
        self::assertTrue($state['hasUnpublishedChanges']);
        self::assertNull($state['changes'][0]['after']);

        $this->republishPlanning($client, $s);
        self::assertResponseIsSuccessful();
        $expected = [$calendar['0|2027-01-06|ONCALL'], 'junior@example.com'];
        sort($expected);
        self::assertSame($expected, $this->emailedAddresses());
        self::assertStringContainsString('Test User → Non attribué', (string) self::getMailerMessages()[0]->getTextBody());
    }

    public function testASuccessfulRepublicationBecomesTheNewReference(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->publishPlanning($client, $s);
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'));
        self::assertTrue($this->state($client, $s)['hasUnpublishedChanges']);

        $this->republishPlanning($client, $s);
        self::assertResponseIsSuccessful();

        $state = $this->state($client, $s);
        self::assertFalse($state['hasUnpublishedChanges']);
        self::assertSame([], $state['changes']);
        self::assertCount(2, $state['history']);
        self::assertSame(['FIRST', 'UPDATE'], array_column($state['history'], 'kind'));
        $this->republishPlanning($client, $s);
        self::assertResponseStatusCodeSame(409, 'Nothing new since the republication.');

        // The new reference also drives the PDF: the removal is now what was published.
        $view = static::getContainer()->get(PlanningPdfRenderer::class)->view($this->latestPublication($s['planningId']));
        $days = array_merge(...array_column($view['weeks'], 'days'));
        self::assertNull($days[5]['cells'][0][0]['name']);
    }

    public function testRepublicationNeedsAFirstPublicationAndACoherentCalendar(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);

        $response = $this->republishPlanning($client, $s);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_yet_published', $response['error']);

        $this->publishPlanning($client, $s);
        $holder = $this->currentCalendar($s['planningId'])['0|2027-01-06|ONCALL'];
        $this->reassignTo($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'), $this->someoneElse($holder));
        // The new holder becomes unavailable afterwards: the calendar is no longer coherent.
        $newHolder = $this->currentCalendar($s['planningId'])['0|2027-01-06|ONCALL'];
        $this->declareRange($client, $s[explode('@', $newHolder)[0]], '2027-01-06', '2027-01-07');

        $response = $this->republishPlanning($client, $s);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_publishable', $response['error']);
        self::assertCount(1, $response['preflight']['invalidAssignments']);
    }

    public function testPublicationStateDetailsAndRepublicationAreForManagersOnly(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], self::STANDALONE);
        $this->generate($client, $s);
        $this->publishPlanning($client, $s);
        $this->unassignDuty($client, $s, $this->dutyOn($s['planningId'], '2027-01-06'));

        $memberView = $this->state($client, $s, $s['alice']);
        self::assertTrue($memberView['published']);
        self::assertNotNull($memberView['lastPublishedAt']);
        self::assertArrayNotHasKey('changes', $memberView);
        self::assertArrayNotHasKey('hasUnpublishedChanges', $memberView);

        $this->republishPlanning($client, $s, $s['alice']);
        self::assertResponseStatusCodeSame(403);
        $this->state($client, $s, $s['outsider']);
        self::assertResponseStatusCodeSame(403);

        $this->republishPlanning($client, $s, $s['admin']);
        self::assertResponseIsSuccessful();
    }

    // --- helpers -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function publishPlanning(KernelBrowser $client, array $s, ?string $token = null): array
    {
        return $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $token ?? $s['creator']);
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function republishPlanning(KernelBrowser $client, array $s, ?string $token = null): array
    {
        return $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/republish", [], $token ?? $s['creator']);
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function state(KernelBrowser $client, array $s, ?string $token = null): array
    {
        return $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/publication-state", token: $token ?? $s['creator']);
    }

    private function planningEntity(string $planningStableId): \App\Entity\Planning
    {
        return static::getContainer()->get(PlanningRepository::class)->findOneByStableId($planningStableId);
    }

    private function latestPublication(string $planningStableId): \App\Entity\PlanningPublication
    {
        $publication = static::getContainer()->get(PlanningPublicationRepository::class)->findLatestForPlanning($this->planningEntity($planningStableId));
        self::assertNotNull($publication);

        return $publication;
    }

    /**
     * @return list<string> sorted recipient addresses of the last request's emails
     */
    private function emailedAddresses(): array
    {
        $addresses = array_map(static fn (Email $email): string => $email->getTo()[0]->getAddress(), self::getMailerMessages());
        sort($addresses);

        return $addresses;
    }

    /** A primary-line member who is none of $excluded. */
    private function someoneElse(?string ...$excluded): string
    {
        foreach (['admin@example.com', 'alice@example.com', 'bob@example.com'] as $email) {
            if (!\in_array($email, $excluded, true)) {
                return $email;
            }
        }

        self::fail('No other member.');
    }

    /**
     * @param array<string, string|null> $calendar
     */
    private function blockHolder(array $calendar): string
    {
        foreach ($calendar as $key => $who) {
            if (str_starts_with($key, '0|2027-01-08|')) {
                return (string) $who;
            }
        }

        self::fail('No block on 2027-01-08.');
    }
}
