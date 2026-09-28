<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Demand\DemandMode;
use App\Demand\DemandTriggerEvaluator;
use App\Demand\Weekday;
use App\Entity\DemandPolicyStatus;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\UserRepository;
use App\Service\PlanningLineDemandPolicyService;
use App\Tests\CalendarWorkflowTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * GET/PUT /api/planning-lines/{id}/demand-policy (docs/decisions.md D162).
 *
 * Scenario: the pilot planning (main line: admin, alice, bob) plus a
 * secondary "Renfort" line. In the running example, admin plays Dr A (no
 * trigger), alice Dr B (every day) and bob Dr C (Friday to Sunday).
 */
final class DemandPolicyControllerTest extends WebTestCase
{
    use CalendarWorkflowTestHelpers;
    use ClockSensitiveTrait;

    private const ALL_DAYS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
    private const WEEKEND = ['FRIDAY', 'SATURDAY', 'SUNDAY'];

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
    }

    /**
     * @return array<string, mixed> the pilot scenario, plus `primaryLineId` and one secondary line id per given name
     */
    private function scenario(KernelBrowser $client, array $secondaryNames = ['Renfort']): array
    {
        $s = $this->pilotScenario($client);
        foreach ($secondaryNames as $name) {
            $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/lines", ['name' => $name], $s['creator']);
            self::assertResponseStatusCodeSame(201);
        }
        $lines = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'];
        $s['primaryLineId'] = $lines[0]['stableId'];
        foreach ($secondaryNames as $index => $name) {
            $s['lines'][$name] = $lines[$index + 1]['stableId'];
            $s['teams'][$name] = $lines[$index + 1]['team']['stableId'];
        }

        return $s;
    }

    private function userId(string $email): string
    {
        return (string) static::getContainer()->get(UserRepository::class)->findOneByEmail($email)->getStableId();
    }

    /**
     * @param list<array{0: string, 1: list<string>}> $triggers [email, weekdays]
     *
     * @return array<string, mixed>
     */
    private function conditional(string $sourceLineId, array $triggers, int $increment = 1): array
    {
        return [
            'schemaVersion' => 1,
            'mode' => 'CONDITIONAL_ON_SOURCE_ASSIGNMENT',
            'source' => ['lineStableId' => $sourceLineId],
            'triggers' => array_map(fn (array $t): array => ['userStableId' => $this->userId($t[0]), 'weekdays' => $t[1], 'increment' => $increment], $triggers),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function independent(): array
    {
        return ['schemaVersion' => 1, 'mode' => 'INDEPENDENT', 'source' => null, 'triggers' => []];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function put(KernelBrowser $client, string $token, string $lineId, array $body): array
    {
        return $this->api($client, 'PUT', "/api/planning-lines/{$lineId}/demand-policy", $body, $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(KernelBrowser $client, string $token, string $lineId): array
    {
        return $this->api($client, 'GET', "/api/planning-lines/{$lineId}/demand-policy", token: $token);
    }

    /** @return list<string> */
    private function warningCodes(array $view): array
    {
        return array_column($view['warnings'], 'code');
    }

    // --- reading ------------------------------------------------------------------------------

    public function testALineWithoutPolicyIsIndependentAndOffersItsSourcesWithTheirPeople(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $view = $this->get($client, $s['creator'], $s['lines']['Renfort']);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $view['schemaVersion']);
        self::assertSame('INDEPENDENT', $view['mode']);
        self::assertNull($view['policy']);
        self::assertNull($view['source']);
        self::assertSame([], $view['triggers']);
        self::assertSame(self::ALL_DAYS, $view['weekdays']);
        self::assertSame([], $view['warnings']);
        self::assertFalse($view['targetStructure']['configured']);
        self::assertCount(1, $view['sourceOptions'], 'Only the main line can be a source here.');
        self::assertSame($s['primaryLineId'], $view['sourceOptions'][0]['lineStableId']);
        self::assertEqualsCanonicalizing(
            [$this->userId('admin@example.com'), $this->userId('alice@example.com'), $this->userId('bob@example.com')],
            array_column($view['sourceOptions'][0]['people'], 'userStableId'),
        );
    }

    // --- writing: the running example, persisted then evaluated ------------------------------

    public function testTheRunningExampleIsStoredAsVersionOneAndEvaluatesAsExpected(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $view = $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [
            ['alice@example.com', self::ALL_DAYS],
            ['bob@example.com', ['SUNDAY', 'FRIDAY', 'SATURDAY']],
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame('CONDITIONAL_ON_SOURCE_ASSIGNMENT', $view['mode']);
        self::assertSame(1, $view['policy']['version']);
        self::assertSame($s['primaryLineId'], $view['source']['lineStableId']);
        self::assertSame(self::WEEKEND, $view['triggers'][1]['weekdays'], 'Stored and returned in ISO order.');
        self::assertSame(1, $view['triggers'][1]['increment']);
        self::assertSame(['TARGET_HAS_NO_WEEK_STRUCTURE'], $this->warningCodes($view));

        // Read back from the database, then evaluated by the pure evaluator.
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $line = static::getContainer()->get(PlanningLineRepository::class)->findOneByStableId($s['lines']['Renfort']);
        $rules = static::getContainer()->get(PlanningLineDemandPolicyService::class)->rulesFor($line);
        $evaluator = new DemandTriggerEvaluator();
        foreach (Weekday::cases() as $day) {
            self::assertFalse($evaluator->isTriggered($rules, $this->userId('admin@example.com'), $day), "Dr A never triggers ({$day->value}).");
            self::assertTrue($evaluator->isTriggered($rules, $this->userId('alice@example.com'), $day), "Dr B always triggers ({$day->value}).");
            self::assertSame(\in_array($day->value, self::WEEKEND, true), $evaluator->isTriggered($rules, $this->userId('bob@example.com'), $day), "Dr C only Friday to Sunday ({$day->value}).");
        }
    }

    public function testAChangeCreatesANewVersionAndLeavesThePreviousOneUntouched(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [['bob@example.com', self::WEEKEND]]));

        $view = $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [['bob@example.com', ['SATURDAY', 'SUNDAY']]]));
        self::assertResponseIsSuccessful();
        self::assertSame(2, $view['policy']['version']);

        // The same content again: nothing new.
        $again = $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [['bob@example.com', ['SUNDAY', 'SATURDAY']]]));
        self::assertSame(2, $again['policy']['version']);
        self::assertSame($view['policy']['stableId'], $again['policy']['stableId']);

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $line = static::getContainer()->get(PlanningLineRepository::class)->findOneByStableId($s['lines']['Renfort']);
        $history = static::getContainer()->get(PlanningLineDemandPolicyRepository::class)->findHistoryForLine($line);
        self::assertCount(2, $history);
        self::assertSame(DemandPolicyStatus::RETIRED, $history[0]->getStatus());
        self::assertNotNull($history[0]->getRetiredAt());
        self::assertSame([Weekday::FRIDAY, Weekday::SATURDAY, Weekday::SUNDAY], $history[0]->getTriggers()->first()->getWeekdays(), 'Version 1 still says what it said.');
        self::assertSame(DemandPolicyStatus::ACTIVE, $history[1]->getStatus());
        self::assertSame([Weekday::SATURDAY, Weekday::SUNDAY], $history[1]->getTriggers()->first()->getWeekdays());
    }

    public function testAFailedReplacementLeavesThePolicyInForceActiveAndIntact(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $v1 = $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [['bob@example.com', self::WEEKEND]]));
        $connection = static::getContainer()->get(Connection::class);

        // 1. Refused by validation: nothing is retired, nothing is created.
        $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [['bob@example.com', ['SATURDAY']]], increment: 2));
        self::assertResponseStatusCodeSame(422);

        // 2. Refused while being written (a database failure after the retirement of version 1): all or nothing.
        $connection->executeStatement(<<<'SQL'
            CREATE FUNCTION test_refuse_demand_triggers() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'simulated failure'; END $$ LANGUAGE plpgsql
            SQL);
        $connection->executeStatement('CREATE TRIGGER test_refuse_demand_triggers BEFORE INSERT ON planning_line_demand_triggers FOR EACH ROW EXECUTE FUNCTION test_refuse_demand_triggers()');
        $client->catchExceptions(true);
        $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [['bob@example.com', ['SUNDAY']]]));
        self::assertResponseStatusCodeSame(500);
        $connection->executeStatement('DROP TRIGGER test_refuse_demand_triggers ON planning_line_demand_triggers');

        $view = $this->get($client, $s['creator'], $s['lines']['Renfort']);
        self::assertSame($v1['policy'], $view['policy'], 'Version 1 is still the one in force.');
        self::assertSame(self::WEEKEND, $view['triggers'][0]['weekdays']);
        self::assertSame([['ACTIVE', 1]], array_map(static fn (array $r): array => [$r['status'], (int) $r['version']], $connection->fetchAllAssociative('SELECT status, version FROM planning_line_demand_policies')));
    }

    public function testASavedVersionCanNeverBeEditedInPlace(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [['bob@example.com', self::WEEKEND]]));
        $container = static::getContainer();
        $policy = $container->get(PlanningLineDemandPolicyRepository::class)->findActiveForLine($container->get(PlanningLineRepository::class)->findOneByStableId($s['lines']['Renfort']));

        $this->expectException(\LogicException::class);
        new \App\Entity\DemandTrigger($policy, $container->get(UserRepository::class)->findOneByEmail('alice@example.com'), [Weekday::MONDAY], 1);
    }

    public function testGoingBackToIndependentIsANewVersionWithoutSourceOrTrigger(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [['bob@example.com', self::WEEKEND]]));

        $view = $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->independent());

        self::assertResponseIsSuccessful();
        self::assertSame('INDEPENDENT', $view['mode']);
        self::assertSame(2, $view['policy']['version']);
        self::assertNull($view['source']);
        self::assertSame([], $view['triggers']);
    }

    public function testSubmittingIndependentForALineWithoutPolicyStoresNothing(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $view = $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->independent());

        self::assertResponseIsSuccessful();
        self::assertNull($view['policy'], 'No policy is already INDEPENDENT.');
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM planning_line_demand_policies'));
    }

    // --- structure rules ----------------------------------------------------------------------

    public function testAnIncrementOtherThanOneIsRefused(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $error = $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [['bob@example.com', self::WEEKEND]], increment: 2));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_demand_policy', $error['error']);
        self::assertSame('UNSUPPORTED_INCREMENT', $error['code']);
        self::assertSame('triggers[0].increment', $error['field']);
    }

    public function testASourceOfAnotherPlanningIsRefused(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        [$otherPlanning] = $this->createPlanningWithTeam($client, $s['creator'], 'Autre');
        $otherLine = $this->api($client, 'GET', "/api/plannings/{$otherPlanning}", token: $s['creator'])['lines'][0]['stableId'];

        $error = $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($otherLine, [['bob@example.com', self::WEEKEND]]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('SOURCE_NOT_IN_PLANNING', $error['code']);
    }

    public function testALineCanNeverBeItsOwnSource(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $error = $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['lines']['Renfort'], [['bob@example.com', self::WEEKEND]]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('SOURCE_IS_TARGET', $error['code']);
    }

    public function testTheMainLineCanNeverBeConditional(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $error = $this->put($client, $s['creator'], $s['primaryLineId'], $this->conditional($s['lines']['Renfort'], [['bob@example.com', self::WEEKEND]]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('PRIMARY_LINE_CANNOT_BE_CONDITIONAL', $error['code']);
    }

    public function testAConditionalSourceIsRefusedNoChain(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, ['Renfort', 'Renfort 2']);
        $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [['bob@example.com', self::WEEKEND]]));
        self::assertResponseIsSuccessful();

        // Renfort 2 → Renfort → main line would be a chain of depth 2.
        $error = $this->put($client, $s['creator'], $s['lines']['Renfort 2'], $this->conditional($s['lines']['Renfort'], [['bob@example.com', self::WEEKEND]]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('SOURCE_NOT_INDEPENDENT', $error['code']);
        self::assertNotContains($s['lines']['Renfort'], array_column($this->get($client, $s['creator'], $s['lines']['Renfort 2'])['sourceOptions'], 'lineStableId'), 'A conditional line is never offered as a source.');
    }

    public function testMakingASourceConditionalIsRefusedNoChainNoCycle(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, ['Renfort', 'Renfort 2']);
        // Renfort depends on Renfort 2.
        $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['lines']['Renfort 2'], [['bob@example.com', self::WEEKEND]]));
        self::assertResponseIsSuccessful();

        // Renfort 2 → Renfort would close a cycle; Renfort 2 → main line would make a chain.
        foreach ([$s['lines']['Renfort'], $s['primaryLineId']] as $source) {
            $error = $this->put($client, $s['creator'], $s['lines']['Renfort 2'], $this->conditional($source, [['bob@example.com', self::WEEKEND]]));
            self::assertResponseStatusCodeSame(422);
            self::assertSame('TARGET_IS_A_SOURCE', $error['code']);
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'unknown field' => [['schemaVersion' => 1, 'mode' => 'INDEPENDENT', 'source' => null, 'triggers' => [], 'isDoubled' => true], 'isDoubled'];
        yield 'unknown trigger field' => [['schemaVersion' => 1, 'mode' => 'INDEPENDENT', 'source' => null, 'triggers' => [['userStableId' => 'x', 'weekdays' => ['MONDAY'], 'increment' => 1, 'extra' => 1]]], 'triggers[0].extra'];
        yield 'schemaVersion not an integer' => [['schemaVersion' => '1', 'mode' => 'INDEPENDENT'], 'schemaVersion'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedBodies')]
    public function testAMalformedBodyIsA422ValidationError(array $body, string $field): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $error = $this->put($client, $s['creator'], $s['lines']['Renfort'], $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $error['error']);
        self::assertArrayHasKey($field, $error['violations']);
    }

    /**
     * @return iterable<string, array{0: callable(self, array<string, mixed>): array<string, mixed>, 1: string}>
     */
    public static function invalidPolicies(): iterable
    {
        yield 'unsupported schema version' => [static fn (self $t, array $s): array => ['schemaVersion' => 2] + $t->conditional($s['primaryLineId'], [['bob@example.com', self::WEEKEND]]), 'UNSUPPORTED_SCHEMA_VERSION'];
        yield 'unknown mode' => [static fn (self $t, array $s): array => ['mode' => 'DOUBLED'] + $t->independent(), 'UNKNOWN_MODE'];
        yield 'independent with a source' => [static fn (self $t, array $s): array => ['source' => ['lineStableId' => $s['primaryLineId']]] + $t->independent(), 'INDEPENDENT_WITH_SOURCE'];
        yield 'conditional without source' => [static fn (self $t, array $s): array => ['source' => null] + $t->conditional($s['primaryLineId'], []), 'SOURCE_REQUIRED'];
        yield 'unknown weekday' => [static fn (self $t, array $s): array => $t->conditional($s['primaryLineId'], [['bob@example.com', ['VENDREDI']]]), 'UNKNOWN_WEEKDAY'];
        yield 'no weekday' => [static fn (self $t, array $s): array => $t->conditional($s['primaryLineId'], [['bob@example.com', []]]), 'NO_WEEKDAY'];
        yield 'duplicate weekday' => [static fn (self $t, array $s): array => $t->conditional($s['primaryLineId'], [['bob@example.com', ['FRIDAY', 'FRIDAY']]]), 'DUPLICATE_WEEKDAY'];
        yield 'two triggers for one person' => [static fn (self $t, array $s): array => $t->conditional($s['primaryLineId'], [['bob@example.com', ['FRIDAY']], ['bob@example.com', ['SUNDAY']]]), 'DUPLICATE_TRIGGER_PERSON'];
    }

    /**
     * @param callable(self, array<string, mixed>): array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPolicies')]
    public function testAnInvalidPolicyIsRefusedWithItsCode(callable $body, string $code): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);

        $error = $this->put($client, $s['creator'], $s['lines']['Renfort'], $body($this, $s));

        self::assertResponseStatusCodeSame(422);
        self::assertSame($code, $error['code']);
    }

    public function testAnUnknownPersonIsRefused(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $body = $this->conditional($s['primaryLineId'], []);
        $body['triggers'] = [['userStableId' => '01900000-0000-7000-8000-000000000000', 'weekdays' => ['MONDAY'], 'increment' => 1]];

        $error = $this->put($client, $s['creator'], $s['lines']['Renfort'], $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('UNKNOWN_USER', $error['code']);
    }

    // --- state rules ----------------------------------------------------------------------------

    public function testAConditionalConfigurationAfterPublicationIsRefused(): void
    {
        $client = static::createClient();
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']]);
        $this->generate($client, $s);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/publish", [], $s['creator']);
        self::assertResponseIsSuccessful();
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/lines", ['name' => 'Renfort'], $s['creator']);
        $lines = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'];

        $error = $this->put($client, $s['creator'], $lines[1]['stableId'], $this->conditional($lines[0]['stableId'], [['bob@example.com', self::WEEKEND]]));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('planning_already_published', $error['error']);
        self::assertStringContainsString('before the first publication', $error['message']);
    }

    public function testChangingTheModeOfAMaterializedLineIsRefusedButATriggerChangeIsNot(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, ['Renfort', 'Autre']);
        // "Autre" already has its duties: it can no longer become conditional for this period.
        $this->prepareLine($s['planningId'], [['2027-01-05', '2027-01-06']], lineIndex: 2);
        $error = $this->put($client, $s['creator'], $s['lines']['Autre'], $this->conditional($s['primaryLineId'], [['bob@example.com', self::WEEKEND]]));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('line_already_materialized', $error['error']);

        // "Renfort" is made conditional first, then its duties exist — materialized the real way (D163: weekly
        // structure + preflight, each one CONDITIONAL and linked to the main line's duty that day): only its
        // triggers may still change.
        $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [['bob@example.com', self::WEEKEND]]));
        self::assertResponseIsSuccessful();
        $this->prepareLine($s['planningId'], [['2027-01-08', '2027-01-09']]);
        $this->api($client, 'PUT', "/api/planning-lines/{$s['lines']['Renfort']}/week-structure", [
            'blocks' => [], 'solo' => ['VEN'], 'soloFamily' => '', 'excluded' => ['LUN', 'MAR', 'MER', 'JEU', 'SAM', 'DIM'],
        ], $s['creator']);
        self::assertResponseIsSuccessful();
        $this->api($client, 'GET', "/api/plannings/{$s['planningId']}/generation-preflight", token: $s['creator']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->dutyEntityOn($s['planningId'], '2027-01-08', 1)->isConditional(), 'Precondition: the line now has its (conditional) duty.');

        $view = $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [['bob@example.com', ['SATURDAY']]]));
        self::assertResponseIsSuccessful();
        self::assertSame(2, $view['policy']['version']);

        $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->independent());
        self::assertResponseStatusCodeSame(409);
        $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['lines']['Autre'], [['bob@example.com', ['SATURDAY']]]));
        self::assertResponseStatusCodeSame(409, 'Changing the source is a change of the demand itself.');
    }

    // --- warnings ------------------------------------------------------------------------------

    public function testWarningsAreStructuredAndNeverARefusal(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->userToken($client, 'carol@example.com');
        $this->api($client, 'PUT', "/api/planning-lines/{$s['lines']['Renfort']}/week-structure", [
            'blocks' => [['name' => 'Week-end', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => '']],
            'solo' => ['LUN', 'MAR', 'MER'],
            'soloFamily' => '',
            'excluded' => ['JEU'],
        ], $s['creator']);
        self::assertResponseIsSuccessful();

        $view = $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [
            ['bob@example.com', ['FRIDAY', 'SATURDAY']],     // part of the Friday–Sunday block
            ['alice@example.com', ['THURSDAY', 'MONDAY']],   // Thursday has no duty on this line
            ['carol@example.com', ['MONDAY']],               // not a member of the main line
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame(3, \count($view['triggers']), 'Warnings never refuse.');
        self::assertTrue($view['targetStructure']['configured']);
        self::assertSame(['THURSDAY'], $view['targetStructure']['excludedWeekdays']);
        self::assertSame([['name' => 'Week-end', 'weekdays' => self::WEEKEND]], $view['targetStructure']['blocks']);

        $byCode = [];
        foreach ($view['warnings'] as $warning) {
            $byCode[$warning['code']][] = $warning;
            self::assertNotSame('', $warning['message']);
        }
        self::assertSame(['TRIGGER_PARTIALLY_COVERS_TARGET_BLOCK', 'TRIGGER_DAY_EXCLUDED_FROM_TARGET', 'TRIGGER_PERSON_NOT_IN_SOURCE_LINE'], array_keys($byCode));
        self::assertSame([
            'userStableId' => $this->userId('bob@example.com'),
            'blockName' => 'Week-end',
            'blockWeekdays' => self::WEEKEND,
            'triggeredWeekdays' => ['FRIDAY', 'SATURDAY'],
        ], $byCode['TRIGGER_PARTIALLY_COVERS_TARGET_BLOCK'][0]['details']);
        self::assertSame(['userStableId' => $this->userId('alice@example.com'), 'weekday' => 'THURSDAY'], $byCode['TRIGGER_DAY_EXCLUDED_FROM_TARGET'][0]['details']);
        self::assertSame(['userStableId' => $this->userId('carol@example.com')], $byCode['TRIGGER_PERSON_NOT_IN_SOURCE_LINE'][0]['details']);

        // Same warnings on a plain read.
        self::assertSame($view['warnings'], $this->get($client, $s['creator'], $s['lines']['Renfort'])['warnings']);
    }

    public function testAPersonWithSeveralStintsStaysTheSameTrigger(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [['alice@example.com', self::WEEKEND]]));

        // alice leaves the main line, then comes back: two stints, the same person.
        $aliceStint = $this->memberStableIdIn($s['planningId'], 'alice@example.com');
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/teams/{$s['teamId']}/members/{$aliceStint}/end", ['membershipEnd' => '2027-02-01'], $s['creator']);
        self::assertResponseIsSuccessful();
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/teams/{$s['teamId']}/members", [
            'userStableId' => $this->userId('alice@example.com'),
            'role' => 'MEMBER',
            'membershipStart' => '2027-02-01',
        ], $s['creator']);
        self::assertResponseStatusCodeSame(201);

        $view = $this->get($client, $s['creator'], $s['lines']['Renfort']);

        self::assertSame($this->userId('alice@example.com'), $view['triggers'][0]['userStableId']);
        self::assertNotContains('TRIGGER_PERSON_NOT_IN_SOURCE_LINE', $this->warningCodes($view), 'Still a member of the source line.');
        $people = array_column($view['sourceOptions'][0]['people'], 'userStableId');
        self::assertSame(1, \count(array_keys($people, $this->userId('alice@example.com'), true)), 'Listed once, however many stints.');
        self::assertSame(1, $view['policy']['version'], 'A new stint never changes the policy.');
    }

    // --- line deletion ---------------------------------------------------------------------------

    public function testTheSourceOfAConditionalLineInForceCannotBeDeleted(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client, ['Renfort', 'Renfort 2']);
        $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['lines']['Renfort 2'], [['bob@example.com', self::WEEKEND]]));

        $error = $this->api($client, 'DELETE', "/api/plannings/{$s['planningId']}/lines/{$s['lines']['Renfort 2']}", token: $s['creator']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('line_is_demand_source', $error['error']);

        // Once Renfort no longer depends on it, Renfort 2 can go; the retired version keeps its source as a value.
        $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->independent());
        $this->api($client, 'DELETE', "/api/plannings/{$s['planningId']}/lines/{$s['lines']['Renfort 2']}", token: $s['creator']);
        self::assertResponseStatusCodeSame(204);
        $row = static::getContainer()->get(Connection::class)->fetchAssociative("SELECT source_line_id, source_line_stable_id FROM planning_line_demand_policies WHERE mode = 'CONDITIONAL_ON_SOURCE_ASSIGNMENT'");
        self::assertNull($row['source_line_id']);
        self::assertSame($s['lines']['Renfort 2'], $row['source_line_stable_id']);
    }

    public function testDeletingAConditionalLineRemovesItsOwnPolicies(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        $this->put($client, $s['creator'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [['bob@example.com', self::WEEKEND]]));

        $this->api($client, 'DELETE', "/api/plannings/{$s['planningId']}/lines/{$s['lines']['Renfort']}", token: $s['creator']);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM planning_line_demand_policies'));
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM planning_line_demand_triggers'));
    }

    // --- authorization ----------------------------------------------------------------------------

    public function testAManagerThroughAnyOfTheirMembershipsMayConfigureAndAPlainMemberMayNot(): void
    {
        $client = static::createClient();
        $s = $this->scenario($client);
        // bob: MEMBER on the main line, ADMIN ("Gestionnaire") on the secondary line.
        $this->addMemberAs($client, $s['creator'], $s['planningId'], $s['teams']['Renfort'], 'bob@example.com', 'ADMIN');
        // alice: MEMBER on both lines.
        $this->addMemberAs($client, $s['creator'], $s['planningId'], $s['teams']['Renfort'], 'alice@example.com', 'MEMBER');

        $this->get($client, $s['bob'], $s['lines']['Renfort']);
        self::assertResponseIsSuccessful();
        $this->put($client, $s['bob'], $s['lines']['Renfort'], $this->conditional($s['primaryLineId'], [['admin@example.com', self::WEEKEND]]));
        self::assertResponseIsSuccessful();
        // A manager right is Planning-wide (D147): bob may also read the main line's policy.
        $this->get($client, $s['bob'], $s['primaryLineId']);
        self::assertResponseIsSuccessful();

        $this->get($client, $s['alice'], $s['lines']['Renfort']);
        self::assertResponseStatusCodeSame(403);
        $this->put($client, $s['alice'], $s['lines']['Renfort'], $this->independent());
        self::assertResponseStatusCodeSame(403);
        $this->get($client, $s['outsider'], $s['lines']['Renfort']);
        self::assertResponseStatusCodeSame(403);

        $this->get($client, $s['creator'], '01900000-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheModeEnumIsTheStableApiContract(): void
    {
        self::assertSame(['INDEPENDENT', 'CONDITIONAL_ON_SOURCE_ASSIGNMENT'], array_map(static fn (DemandMode $m): string => $m->value, DemandMode::cases()));
    }
}
