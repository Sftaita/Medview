<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\PlanningRuleSetRepository;
use App\Tests\PlanningPilotTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Paramètres de génération" — GET/activate a PlanningLine's team's
 * PlanningRuleSet (docs/decisions.md D137). No configurable field is ever
 * sent by a real client — every `PlanningRuleSetConfiguration` field is
 * unread by the solver today (see the controller's own docblock) — so
 * these tests exercise the real business-facing contract: activation
 * unblocks generation, never mutates a previous ACTIVE RuleSet, and stays
 * reserved to a manager.
 */
final class PlanningRuleSetControllerTest extends WebTestCase
{
    use PlanningPilotTestHelpers;

    /**
     * @param array<string, mixed> $s
     */
    private function primaryLineStableId(array $s): string
    {
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($s['planningId']);
        $line = static::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0];

        return (string) $line->getStableId();
    }

    public function testAFreshLineHasNoActiveRuleSet(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $lineId = $this->primaryLineStableId($s);

        $result = $this->api($client, 'GET', "/api/planning-lines/{$lineId}/rule-set", token: $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertSame(['active' => false], $result);
    }

    public function testTheCreatorCanActivateAndItUnblocksGeneration(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $lineId = $this->primaryLineStableId($s);

        $result = $this->api($client, 'POST', "/api/planning-lines/{$lineId}/rule-set/activate", [], token: $s['creator']);
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($result['active']);
        self::assertNotNull($result['activatedAt']);

        $reread = $this->api($client, 'GET', "/api/planning-lines/{$lineId}/rule-set", token: $s['creator']);
        self::assertTrue($reread['active']);

        // No duty exists yet (no week structure configured) — but the
        // NO_ACTIVE_RULE_SET blocker specifically must be gone.
        $preflight = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/generation-preflight", token: $s['creator']);
        self::assertNotContains('NO_ACTIVE_RULE_SET', array_column($preflight['blockers'], 'code'));
        self::assertTrue($preflight['lines'][0]['hasActiveRuleSet']);
    }

    public function testATeamAdminCanAlsoActivate(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $lineId = $this->primaryLineStableId($s);

        $this->api($client, 'POST', "/api/planning-lines/{$lineId}/rule-set/activate", [], token: $s['admin']);
        self::assertResponseStatusCodeSame(201);
    }

    public function testActivatingTwiceRetiresThePreviousOneRatherThanMutatingIt(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $lineId = $this->primaryLineStableId($s);

        $first = $this->api($client, 'POST', "/api/planning-lines/{$lineId}/rule-set/activate", [], token: $s['creator']);
        self::assertResponseStatusCodeSame(201);

        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($s['planningId']);
        $team = static::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningTeam();
        $ruleSetRepository = static::getContainer()->get(PlanningRuleSetRepository::class);
        $firstActive = $ruleSetRepository->findActive($team);
        self::assertNotNull($firstActive);
        $firstStableId = (string) $firstActive->getStableId();

        $second = $this->api($client, 'POST', "/api/planning-lines/{$lineId}/rule-set/activate", [], token: $s['creator']);
        self::assertResponseStatusCodeSame(201);

        $secondActive = $ruleSetRepository->findActive($team);
        self::assertNotNull($secondActive);
        self::assertNotSame($firstStableId, (string) $secondActive->getStableId(), 'A second activation creates and activates a brand new version, never reuses the first.');
        self::assertSame(2, $secondActive->getVersion());
    }

    public function testAPlainMemberCannotActivate(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $lineId = $this->primaryLineStableId($s);

        $this->api($client, 'GET', "/api/planning-lines/{$lineId}/rule-set", token: $s['alice']);
        self::assertResponseStatusCodeSame(403);

        $this->api($client, 'POST', "/api/planning-lines/{$lineId}/rule-set/activate", [], token: $s['alice']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownLineIsA404(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);

        $this->api($client, 'GET', '/api/planning-lines/01000000-0000-7000-8000-000000000000/rule-set', token: $s['creator']);
        self::assertResponseStatusCodeSame(404);
    }
}
