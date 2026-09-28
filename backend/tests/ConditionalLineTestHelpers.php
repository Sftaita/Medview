<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Duty;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningLine;
use App\Entity\PlanningSnapshot;
use App\Fairness\OptimizationProblem;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\PlanningSnapshotRepository;
use App\Repository\UserRepository;
use App\Service\PlanningRuleSetService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The conditional-line scenario shared by the D164/D165 tests: the pilot
 * planning's main line (admin, alice, bob) and a "Renfort" line (carol,
 * dave by default) depending on it. Dr A = admin (no trigger), Dr B = alice
 * (every day), Dr C = bob (Friday to Sunday). Renfort's weekly structure:
 * Tuesday alone, a Friday–Sunday block. The using class also uses
 * CalendarWorkflowTestHelpers (pilot scenario, API calls, calendar reads).
 */
trait ConditionalLineTestHelpers
{
    private const ALL_DAYS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
    private const WEEK = [['2027-01-05', '2027-01-06'], ['2027-01-08', '2027-01-09'], ['2027-01-09', '2027-01-10'], ['2027-01-10', '2027-01-11']];
    private const PRIMARY = ['admin@example.com', 'alice@example.com', 'bob@example.com'];

    /**
     * @param list<array{0: string, 1: string}> $primaryDays
     * @param array<string, mixed>              $renfortStructure
     * @param list<string>                      $renfortPeople
     *
     * @return array<string, mixed>
     */
    private function scenario(KernelBrowser $client, array $primaryDays = self::WEEK, ?array $renfortStructure = null, array $renfortPeople = ['carol@example.com', 'dave@example.com']): array
    {
        $s = $this->pilotScenario($client);
        $this->prepareLine($s['planningId'], $primaryDays);
        $this->api($client, 'POST', "/api/plannings/{$s['planningId']}/lines", ['name' => 'Renfort'], $s['creator']);
        self::assertResponseStatusCodeSame(201);
        $lines = $this->api($client, 'GET', "/api/plannings/{$s['planningId']}", token: $s['creator'])['lines'];
        $s['primaryLineId'] = $lines[0]['stableId'];
        $s['renfortLineId'] = $lines[1]['stableId'];
        $s['carol'] = $this->userToken($client, 'carol@example.com');
        $s['dave'] = $this->userToken($client, 'dave@example.com');
        foreach ($renfortPeople as $email) {
            $this->addMemberAs($client, $s['creator'], $s['planningId'], $lines[1]['team']['stableId'], $email, 'MEMBER');
        }
        $this->activateRuleSet(static::getContainer()->get(PlanningRuleSetService::class), $this->line($s['planningId'], 1)->getPlanningTeam());
        $this->setPolicy($client, $s, [['alice@example.com', self::ALL_DAYS], ['bob@example.com', ['FRIDAY', 'SATURDAY', 'SUNDAY']]]);
        $this->api($client, 'PUT', "/api/planning-lines/{$s['renfortLineId']}/week-structure", $renfortStructure ?? [
            'blocks' => [['name' => 'Week-end', 'days' => ['VEN', 'SAM', 'DIM'], 'family' => '']],
            'solo' => ['MAR'],
            'soloFamily' => '',
            'excluded' => ['LUN', 'MER', 'JEU'],
        ], $s['creator']);
        self::assertResponseIsSuccessful();

        return $s;
    }

    /**
     * @param list<array{0: string, 1: list<string>}> $triggers
     */
    private function setPolicy(KernelBrowser $client, array $s, array $triggers): void
    {
        $this->api($client, 'PUT', "/api/planning-lines/{$s['renfortLineId']}/demand-policy", [
            'schemaVersion' => 1,
            'mode' => 'CONDITIONAL_ON_SOURCE_ASSIGNMENT',
            'source' => ['lineStableId' => $s['primaryLineId']],
            'triggers' => array_map(fn (array $t): array => ['userStableId' => $this->userId($t[0]), 'weekdays' => $t[1], 'increment' => 1], $triggers),
        ], $s['creator']);
        self::assertResponseIsSuccessful();
    }

    /**
     * Forces the main line's holder of each date: the two other main-line people are away that day.
     *
     * @param array<string, string> $holderByDate
     *
     * @return list<array{0: string, 1: string}> the unavailabilities declared (token, stableId) — liftUnavailabilities() removes them
     */
    private function forceHolders(KernelBrowser $client, array $s, array $holderByDate): array
    {
        $declared = [];
        // Consecutive days of one person are declared as one range (two touching unavailabilities are refused).
        foreach (self::PRIMARY as $email) {
            $days = array_keys(array_filter($holderByDate, static fn (string $holder): bool => $holder !== $email));
            sort($days);
            $ranges = [];
            foreach ($days as $day) {
                $end = (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
                $last = array_key_last($ranges);
                if (null !== $last && $ranges[$last][1] === $day) {
                    $ranges[$last][1] = $end;
                } else {
                    $ranges[] = [$day, $end];
                }
            }
            foreach ($ranges as [$from, $to]) {
                $token = $s[explode('@', $email)[0]];
                $declared[] = [$token, (string) $this->declareRange($client, $token, $from, $to)['stableId']];
            }
        }

        return $declared;
    }

    /**
     * After the generation: everybody is free again, so the live calendar can be edited freely.
     *
     * @param list<array{0: string, 1: string}> $declared
     */
    private function liftUnavailabilities(KernelBrowser $client, array $declared): void
    {
        foreach ($declared as [$token, $stableId]) {
            $this->api($client, 'DELETE', "/api/me/calendar/{$stableId}", token: $token);
            self::assertResponseIsSuccessful();
        }
    }

    /**
     * The calendar result's Renfort duties by date (GET /result) — what the frontend reads.
     *
     * @return array<string, array<string, mixed>>
     */
    private function renfortResult(KernelBrowser $client, array $s): array
    {
        $duties = [];
        foreach ($this->api($client, 'GET', "/api/plannings/{$s['planningId']}/result", token: $s['creator'])['lines'] as $line) {
            if ($line['lineStableId'] !== $s['renfortLineId']) {
                continue;
            }
            foreach ($line['duties'] as $duty) {
                $duties[$duty['date']] = $duty;
            }
        }
        ksort($duties);

        return $duties;
    }

    /** The live state of each Renfort date, as the calendar result gives it. */
    private function renfortStates(KernelBrowser $client, array $s): array
    {
        return array_map(static fn (array $duty): ?string => $duty['demand']['state'] ?? null, $this->renfortResult($client, $s));
    }

    /**
     * @return array<string, mixed> the finished job
     */
    private function launch(KernelBrowser $client, array $s, array $body = []): array
    {
        return $this->generateNow($client, $s['planningId'], $s['creator'], $body);
    }

    private function userId(string $email): string
    {
        return (string) static::getContainer()->get(UserRepository::class)->findOneByEmail($email)->getStableId();
    }

    private function line(string $planningStableId, int $index): PlanningLine
    {
        $planning = static::getContainer()->get(PlanningRepository::class)->findOneByStableId($planningStableId);

        return static::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[$index];
    }

    private function currentGeneration(array $s, int $lineIndex): ?PlanningGeneration
    {
        return static::getContainer()->get(PlanningGenerationRepository::class)->findMostRecentCompletedByPlanningPeriod($this->line($s['planningId'], $lineIndex)->getPlanningPeriod());
    }

    private function snapshotOf(PlanningGeneration $generation): PlanningSnapshot
    {
        return static::getContainer()->get(PlanningSnapshotRepository::class)->findOneByGeneration($generation);
    }

    /**
     * @return array<string, Duty> the Renfort duties by date
     */
    private function renfortDuties(array $s): array
    {
        $duties = [];
        foreach (static::getContainer()->get(DutyRepository::class)->findByPlanningPeriod($this->line($s['planningId'], 1)->getPlanningPeriod()) as $duty) {
            $duties[$duty->getLocalDate()->format('Y-m-d')] = $duty;
        }
        ksort($duties);

        return $duties;
    }

    /**
     * @return array<string, string|null> Renfort holder by date
     */
    private function renfortCalendar(array $s): array
    {
        $calendar = [];
        foreach ($this->currentCalendar($s['planningId']) as $key => $holder) {
            [$lineIndex, $date] = explode('|', $key);
            if ('1' === $lineIndex) {
                $calendar[$date] = $holder;
            }
        }

        return $calendar;
    }

    /** The problem the solver received for the Renfort line, if any. */
    private function renfortProblem(array $s): ?OptimizationProblem
    {
        $renfortPeriodId = $this->line($s['planningId'], 1)->getPlanningPeriod()->getId();
        foreach (array_reverse(FaultInjectingPlanningSolver::$solvedProblems) as $problem) {
            $units = [...$problem->getRequiredDutyUnits(), ...$problem->getOptionalDutyUnits()];
            if ([] === $units) {
                // An empty problem: only a conditional line with no triggered unit is ever solved empty here.
                return $problem;
            }
            if ($units[0]->getDuties()[0]->getPlanningPeriod()->getId() === $renfortPeriodId) {
                return $problem;
            }
        }

        return null;
    }
}
