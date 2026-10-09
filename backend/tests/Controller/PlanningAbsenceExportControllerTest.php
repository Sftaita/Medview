<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\PlanningPilotTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * GET /api/plannings/{id}/availability-export.pdf (docs/availability.md §11,
 * docs/decisions.md D181): who may download everyone's absences, and what
 * comes back.
 */
final class PlanningAbsenceExportControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use PlanningPilotTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    public function testTheCreatorAndATeamAdminDownloadAPdf(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->declareRange($client, $s['alice'], '2027-02-10', '2027-02-13');
        $this->declareRange($client, $s['bob'], '2027-02-11', '2027-02-12');

        foreach (['creator', 'admin'] as $who) {
            $this->download($client, $s['planningId'], $s[$who]);
            self::assertResponseIsSuccessful("{$who} manages the availabilities of this planning.");

            $response = $client->getResponse();
            self::assertSame('application/pdf', $response->headers->get('Content-Type'));
            self::assertSame('attachment; filename=MedVue_Absences_Gardes-Seniors_2027-01-01_2027-04-30.pdf', $response->headers->get('Content-Disposition'));
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            self::assertStringStartsWith('%PDF-', (string) $response->getContent());
            self::assertSame(5, $this->pageCount((string) $response->getContent()), 'January to April, then the summary.');
        }
    }

    public function testAPlanningWithoutAnyAbsenceStillGivesAValidPdf(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $this->download($client, $s['planningId'], $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF-', (string) $client->getResponse()->getContent());
    }

    public function testAPlainMemberAnOutsiderAndAnonymousAreRefused(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $this->download($client, $s['planningId'], $s['alice']);
        self::assertResponseStatusCodeSame(403, 'A plain member never sees everybody\'s absences.');
        $this->download($client, $s['planningId'], $s['outsider']);
        self::assertResponseStatusCodeSame(403);
        $this->download($client, $s['planningId'], null);
        self::assertResponseStatusCodeSame(401);
        $this->download($client, '0190a6b2-0000-7000-8000-000000000000', $s['creator']);
        self::assertResponseStatusCodeSame(404);
        $this->download($client, 'not-a-uuid', $s['creator']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testManagingOnePlanningGivesNoAccessToAnother(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        // The outsider creates their own planning: its creator cannot read the first one, and the
        // first one's creator and admin cannot read theirs.
        [$otherPlanningId] = $this->createPlanningWithTeam($client, $s['outsider'], 'Autre');

        $this->download($client, $otherPlanningId, $s['outsider']);
        self::assertResponseIsSuccessful();
        $this->download($client, $s['planningId'], $s['outsider']);
        self::assertResponseStatusCodeSame(403);
        $this->download($client, $otherPlanningId, $s['creator']);
        self::assertResponseStatusCodeSame(403);
        $this->download($client, $otherPlanningId, $s['admin']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAdminWhoseRightWasWithdrawnIsRefused(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $adminRowId = $this->memberIdOf($client, $s, 'admin@example.com');

        $this->api($client, 'PUT', "/api/plannings/{$s['planningId']}/teams/{$s['teamId']}/members/{$adminRowId}/role", ['role' => 'MEMBER'], $s['creator']);
        self::assertResponseIsSuccessful();

        $this->download($client, $s['planningId'], $s['admin']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testOnlyGetIsRouted(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/availability-export.pdf", ['userStableIds' => []], $s['creator']);
        self::assertResponseStatusCodeSame(405, 'The participants are never chosen by the client.');
    }

    private function download(KernelBrowser $client, string $planningId, ?string $token): void
    {
        $server = ['REMOTE_ADDR' => $this->randomTestIp()];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $client->request('GET', "/api/plannings/{$planningId}/availability-export.pdf", server: $server);
    }

    private function pageCount(string $bytes): int
    {
        return (int) preg_match_all('#/Type\s*/Page\b(?!s)#', $bytes);
    }
}
