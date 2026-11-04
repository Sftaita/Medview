<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningLineCoverageChange;
use App\Entity\PlanningPeriodStatus;
use App\Entity\User;
use App\Exception\CalendarBusyException;
use App\Exception\LineCoverageStartConfirmationRequiredException;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Début de couverture de cette ligne" (docs/decisions.md D233): from when a
 * duty of this line is needed, whoever belongs to it. Before that date nothing
 * is demanded, whatever the triggers say; from that date everything applies as
 * usual.
 *
 * Saving is one transaction, under the planning's row lock, the engine lock
 * and the calendar lock: the journal, the duties retired, the date. A duty
 * someone holds is never retired, removed or reassigned: it stays with its
 * holder, simply no longer required. A retired duty keeps its row, its events,
 * its snapshot decision and its publication entries; a date moved back never
 * resurrects it, the completion creates a new duty.
 *
 * Membership dates are never read or written here: they say who may hold a
 * duty, this date says whether a duty is needed.
 */
final class LineCoverageStartService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DutyRepository $dutyRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly CalendarWriteLock $calendarWriteLock,
        private readonly InheritedReinforcementService $inheritedReinforcements,
    ) {
    }

    /** Nothing written. */
    public function plan(PlanningLine $line, ?\DateTimeImmutable $startsOn): LineCoverageStartPlan
    {
        $retired = [];
        $kept = [];
        if (null !== $startsOn) {
            foreach ($line->getPeriods() as $period) {
                if (PlanningPeriodStatus::ARCHIVED === $period->getStatus()) {
                    continue;
                }
                $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period);
                $current = [];
                foreach (null !== $generation ? $this->assignmentRepository->findForGenerations([$generation]) : [] as $assignment) {
                    $current[(int) $assignment->getDuty()->getId()] = $assignment;
                }
                foreach ($this->dutyRepository->findByPlanningPeriod($period) as $duty) {
                    if ($duty->getLocalDate()->format('Y-m-d') >= $startsOn->format('Y-m-d')) {
                        continue;
                    }
                    $assignment = $current[(int) $duty->getId()] ?? null;
                    if (null === $assignment) {
                        $retired[] = $duty;
                    } else {
                        $user = $assignment->getTeamMember()->getUser();
                        $kept[] = ['duty' => $duty, 'holderFirstName' => $user->getFirstName(), 'holderLastName' => $user->getLastName()];
                    }
                }
            }
        }

        return new LineCoverageStartPlan($line, $line->getCoverageStartsOn(), $startsOn, $retired, $kept);
    }

    /**
     * @return array{plan: LineCoverageStartPlan, change: ?PlanningLineCoverageChange}
     *
     * @throws LineCoverageStartConfirmationRequiredException
     * @throws CalendarBusyException
     */
    public function change(PlanningLine $line, ?\DateTimeImmutable $startsOn, User $author, ?string $confirmation): array
    {
        return $this->inTransaction($line->getPlanning(), function () use ($line, $startsOn, $author, $confirmation): array {
            $plan = $this->plan($line, $startsOn);
            if (!$plan->changesAnything()) {
                return ['plan' => $plan, 'change' => null];
            }
            if ($plan->confirmationRequired() && $confirmation !== $plan->hash()) {
                throw new LineCoverageStartConfirmationRequiredException($plan);
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $change = new PlanningLineCoverageChange($line, $author, PlanningLineCoverageChange::COVERAGE_START, $plan->previousStartsOn, $plan->newStartsOn, [
                'retired' => array_map(self::dutyRow(...), $plan->retired),
                'kept' => array_map(static fn (array $row): array => self::dutyRow($row['duty']) + ['holder' => $row['holderFirstName'].' '.$row['holderLastName']], $plan->kept),
            ], $now);
            $this->entityManager->persist($change);
            $this->entityManager->flush();

            foreach ($plan->retired as $duty) {
                $duty->retireFromCoverage($change, $now);
            }
            $line->changeCoverageStart($startsOn);
            $this->entityManager->flush();

            return ['plan' => $plan, 'change' => $change];
        });
    }

    /**
     * Creates, on an explicit request, the reinforcements that became
     * necessary since a reassignment of the source — even on a published line,
     * where nothing else ever creates them (D223). They are empty: a manager
     * assigns them, and they wait for a republication. Read with the same rules
     * as the demand and the preflight.
     *
     * @return int the number of duties created
     *
     * @throws CalendarBusyException
     */
    public function createMissingReinforcements(PlanningLine $line, PlanningCycle $cycle, User $author): int
    {
        return $this->inTransaction($line->getPlanning(), function () use ($line, $cycle, $author): int {
            $period = $line->periodIn($cycle);
            if (null === $period || PlanningPeriodStatus::ARCHIVED === $period->getStatus()) {
                return 0;
            }
            $created = $this->inheritedReinforcements->materializeRequested($period);
            if ([] === $created) {
                return 0;
            }
            $this->entityManager->persist(new PlanningLineCoverageChange($line, $author, PlanningLineCoverageChange::REINFORCEMENTS_CREATED, $line->getCoverageStartsOn(), $line->getCoverageStartsOn(), [
                'created' => array_map(self::dutyRow(...), $created),
            ], new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
            $this->entityManager->flush();

            return \count($created);
        });
    }

    /**
     * @return array{dutyStableId: string, date: string}
     */
    private static function dutyRow(Duty $duty): array
    {
        return ['dutyStableId' => (string) $duty->getStableId(), 'date' => $duty->getLocalDate()->format('Y-m-d')];
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function inTransaction(Planning $planning, callable $operation): mixed
    {
        return $this->entityManager->wrapInTransaction(function () use ($planning, $operation): mixed {
            $this->entityManager->refresh($planning, LockMode::PESSIMISTIC_WRITE);
            if (!(bool) $this->entityManager->getConnection()->fetchOne('SELECT pg_try_advisory_xact_lock(7351, :planning)', ['planning' => $planning->getId()])) {
                throw new CalendarBusyException('A generation or a completion is in progress on this planning.');
            }
            $this->calendarWriteLock->acquire($planning);

            return $operation();
        });
    }
}
