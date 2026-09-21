<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AvailabilityAcknowledgementKind;
use App\Entity\AvailabilityCollection;
use App\Entity\AvailabilityCollectionResponse;
use App\Entity\AvailabilityCollectionStatus;
use App\Entity\AvailabilityResponseStatus;
use App\Entity\Planning;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningLineType;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningTeam;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use App\Entity\UserAvailabilityType;
use App\Exception\AvailabilityCollectionClosedException;
use App\Exception\AvailabilityCollectionOutsidePlanningException;
use App\Exception\AvailabilityCollectionOverlapException;
use App\Exception\ConflictingUnavailabilityException;
use App\Exception\InvalidAvailabilityDeadlineException;
use App\Exception\NoNewPlanningRangeException;
use App\Exception\NotAnAvailabilityRespondentException;
use App\Exception\PlanningPeriodLockedException;
use App\Exception\PlanningRangeShrinkException;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\AvailabilityCollectionResponseRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\UserAvailabilityPeriodRepository;
use App\Service\AvailabilityCollectionService;
use App\Service\DateWindow;
use App\Service\PlanningExtensionService;
use App\Service\PlanningLineService;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningService;
use App\Service\PlanningSnapshotService;
use App\Service\PlanningTeamMembershipService;
use App\Service\UserAvailabilityService;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The availability-collection workflow (docs/availability-collection.md,
 * D120-D124) against the real database, with a frozen clock so every date in
 * the scenarios ("le 10/12 le planning est prolongé…") is deterministic.
 */
final class AvailabilityCollectionServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;

    private EntityManagerInterface $em;
    private PlanningService $planningService;
    private PlanningExtensionService $extensionService;
    private AvailabilityCollectionService $collectionService;
    private PlanningTeamMembershipService $membershipService;
    private UserAvailabilityService $availabilityService;
    private AvailabilityCollectionRepository $collectionRepository;
    private AvailabilityCollectionResponseRepository $responseRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->planningService = $container->get(PlanningService::class);
        $this->extensionService = $container->get(PlanningExtensionService::class);
        $this->collectionService = $container->get(AvailabilityCollectionService::class);
        $this->membershipService = $container->get(PlanningTeamMembershipService::class);
        $this->availabilityService = $container->get(UserAvailabilityService::class);
        $this->collectionRepository = $container->get(AvailabilityCollectionRepository::class);
        $this->responseRepository = $container->get(AvailabilityCollectionResponseRepository::class);

        // Every test starts on the day the planning gets created in the running example.
        self::mockTime('2026-08-25 09:00:00 Europe/Brussels');
    }

    private function planning(string $startsAt = '2026-09-01', string $endsAt = '2027-01-01', bool $includeCreator = false, ?User $creator = null): Planning
    {
        return $this->planningService->create(
            'Gardes '.bin2hex(random_bytes(3)),
            $creator ?? $this->createUser($this->em),
            $this->date($startsAt),
            $this->date($endsAt),
            'Europe/Brussels',
            'Ligne principale',
            $includeCreator,
        );
    }

    private function primaryTeam(Planning $planning): PlanningTeam
    {
        return self::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningTeam();
    }

    private function join(Planning $planning, ?User $user = null, string $start = '2026-08-25'): User
    {
        $user ??= $this->createUser($this->em);
        $this->membershipService->addMember($this->primaryTeam($planning), $user, TeamMemberRole::MEMBER, $this->date($start));

        return $user;
    }

    private function firstCollection(Planning $planning): AvailabilityCollection
    {
        $all = $this->collectionRepository->findByPlanning($planning);

        return $all[array_key_last($all)];
    }

    private function responseOf(AvailabilityCollection $collection, User $user): AvailabilityCollectionResponse
    {
        $response = $this->responseRepository->findOneForUser($collection, $user);
        self::assertNotNull($response, 'expected a response row for this user');

        return $response;
    }

    private function statusOf(AvailabilityCollection $collection, User $user): AvailabilityResponseStatus
    {
        return $this->responseOf($collection, $user)->getStatus();
    }

    private function brussels(string $dateTime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dateTime, new \DateTimeZone('Europe/Brussels'));
    }

    /* --------------------------------------------------------------- 1. creation */

    public function testCreatingAPlanningOpensTheInitialCollectionOverItsWholeRange(): void
    {
        $planning = $this->planning('2026-09-01', '2027-01-01');

        $collections = $this->collectionRepository->findByPlanning($planning);

        self::assertCount(1, $collections);
        self::assertSame('2026-09-01', $collections[0]->getStartsAt()->format('Y-m-d'));
        self::assertSame('2027-01-01', $collections[0]->getEndsAt()->format('Y-m-d'));
        self::assertSame(AvailabilityCollectionStatus::OPEN, $collections[0]->getStatus());
        self::assertNull($collections[0]->getDeadline());
        self::assertSame('2026-08-25 07:00:00', $collections[0]->getOpenedAt()->format('Y-m-d H:i:s'), 'openedAt is stored in UTC');
    }

    /* ------------------------------------------------------- 3. creator inclusion */

    public function testTheCreatorIsNotAParticipantByDefault(): void
    {
        $creator = $this->createUser($this->em);
        $planning = $this->planning(creator: $creator);

        self::assertNull(self::getContainer()->get(PlanningTeamMemberRepository::class)->findOpenMembershipForUserInPlanning($planning, $creator));
        self::assertNull($this->responseRepository->findOneForUser($this->firstCollection($planning), $creator));
    }

    public function testIncludingTheCreatorMakesThemAPlainParticipantOfThePrimaryLine(): void
    {
        $creator = $this->createUser($this->em);

        $planning = $this->planning(includeCreator: true, creator: $creator);

        $membership = self::getContainer()->get(PlanningTeamMemberRepository::class)->findOpenMembershipForUserInPlanning($planning, $creator);
        self::assertNotNull($membership, 'ticking "M\'inclure" must create a real membership — there is no separate flag');
        self::assertSame($this->primaryTeam($planning), $membership->getPlanningTeam());
        self::assertSame(TeamMemberRole::OWNER, $membership->getRole());
        // Covers the whole planning (it starts after "today" here), like any other member would.
        self::assertSame('2026-08-25', $membership->getMembershipStart()->format('Y-m-d'));
        self::assertSame(1.0, $membership->getParticipationPeriods()->first()->toFloat(), 'no favour and no penalty: participation factor 1.0');
        // …and, being a participant, they are expected to answer the availability collection.
        self::assertSame(AvailabilityResponseStatus::PENDING, $this->statusOf($this->firstCollection($planning), $creator));
    }

    public function testAnIncludedCreatorIsInTheSnapshotLikeAnyMemberAndAnExcludedOneIsNot(): void
    {
        $creatorIn = $this->createUser($this->em);
        $creatorOut = $this->createUser($this->em);
        $planningIn = $this->planning(includeCreator: true, creator: $creatorIn);
        $planningOut = $this->planning(creator: $creatorOut);

        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);
        $ruleSetService = self::getContainer()->get(PlanningRuleSetService::class);
        $lineRepository = self::getContainer()->get(PlanningLineRepository::class);

        $userStableIds = [];
        foreach ([$planningIn, $planningOut] as $planning) {
            $period = $lineRepository->findByPlanning($planning)[0]->getPlanningPeriod();
            $this->activateRuleSet($ruleSetService, $period->getTeam());
            $generation = new PlanningGeneration($period);
            $this->em->persist($generation);
            $this->em->flush();
            $snapshot = $snapshotService->createSnapshot($generation);
            $userStableIds[] = array_map(
                static fn ($member): string => (string) $member->getSourceUserStableId(),
                $snapshot->getMembers()->toArray(),
            );
        }

        self::assertSame([(string) $creatorIn->getStableId()], $userStableIds[0]);
        self::assertSame([], $userStableIds[1]);
    }

    /* ---------------------------------------------- 4/7. the flagship scenario */

    public function testAnOldUnavailabilityNeverCountsAsAnswerForALaterExtension(): void
    {
        // 07/08 — X fills in unavailabilities, including some in January (before any collection exists).
        self::mockTime('2026-08-07 10:00:00 Europe/Brussels');
        $x = $this->createUser($this->em);
        $this->availabilityService->create($x, UserAvailabilityType::UNAVAILABLE, $this->brussels('2027-01-15 00:00'), $this->brussels('2027-01-20 00:00'));
        $this->availabilityService->create($x, UserAvailabilityType::UNAVAILABLE, $this->brussels('2026-10-05 00:00'), $this->brussels('2026-10-08 00:00'));

        // 25/08 — the planning is created for 01/09 → 31/12. X is a member, and answers it.
        self::mockTime('2026-08-25 09:00:00 Europe/Brussels');
        $planning = $this->planning('2026-09-01', '2027-01-01');
        $this->join($planning, $x);
        $initial = $this->firstCollection($planning);
        self::assertSame(AvailabilityResponseStatus::PENDING, $this->statusOf($initial, $x), 'having saved absences beforehand is not an answer');
        self::mockTime('2026-08-26 09:00:00 Europe/Brussels');
        $this->collectionService->acknowledge($initial, $x, AvailabilityAcknowledgementKind::CONFIRMED);

        // 10/12 — the planning is extended by three months, deadline 20/12.
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        $collections = $this->extensionService->extend($planning, null, $this->date('2027-04-01'), $planning->getCreator(), $this->date('2026-12-20'));

        self::assertCount(1, $collections);
        $extension = $collections[0];
        self::assertSame('2027-01-01', $extension->getStartsAt()->format('Y-m-d'));
        self::assertSame('2027-04-01', $extension->getEndsAt()->format('Y-m-d'));
        self::assertSame('2026-12-20', $extension->getDeadline()->format('Y-m-d'));
        self::assertSame(
            AvailabilityResponseStatus::PENDING,
            $this->statusOf($extension, $x),
            'X has unavailabilities inside January–March, saved long before: that proves nothing about having reviewed the new window',
        );
        // …while the answer to the first window is untouched.
        self::assertSame(AvailabilityResponseStatus::ACKNOWLEDGED, $this->statusOf($initial, $x));
    }

    /* --------------------------------------------------------- 5/6. answering */

    public function testAnswerWithNoUnavailabilityAtAll(): void
    {
        $planning = $this->planning();
        $x = $this->join($planning);
        $collection = $this->firstCollection($planning);
        self::mockTime('2026-08-28 12:00:00 Europe/Brussels');

        $response = $this->collectionService->acknowledge($collection, $x, AvailabilityAcknowledgementKind::NO_UNAVAILABILITY);

        self::assertSame(AvailabilityResponseStatus::ACKNOWLEDGED, $response->getStatus());
        self::assertSame(AvailabilityAcknowledgementKind::NO_UNAVAILABILITY, $response->getAcknowledgementKind());
        self::assertSame('2026-08-28 10:00:00', $response->getAcknowledgedAt()->format('Y-m-d H:i:s'));
        self::assertSame([], self::getContainer()->get(UserAvailabilityPeriodRepository::class)->findByUser($x), 'answering never writes to the calendar');
    }

    public function testAnswerAfterHavingEnteredUnavailabilities(): void
    {
        $planning = $this->planning();
        $x = $this->join($planning);
        $collection = $this->firstCollection($planning);
        $this->availabilityService->create($x, UserAvailabilityType::UNAVAILABLE, $this->brussels('2026-10-05 00:00'), $this->brussels('2026-10-08 00:00'));

        $response = $this->collectionService->acknowledge($collection, $x, AvailabilityAcknowledgementKind::CONFIRMED);

        self::assertSame(AvailabilityResponseStatus::ACKNOWLEDGED, $response->getStatus());
        self::assertSame(AvailabilityAcknowledgementKind::CONFIRMED, $response->getAcknowledgementKind());
        self::assertCount(1, self::getContainer()->get(UserAvailabilityPeriodRepository::class)->findByUser($x), 'the calendar is untouched by the answer');
    }

    public function testConfirmingWithZeroAbsencesIsAlsoAValidExplicitAnswer(): void
    {
        $planning = $this->planning();
        $x = $this->join($planning);

        $response = $this->collectionService->acknowledge($this->firstCollection($planning), $x, AvailabilityAcknowledgementKind::CONFIRMED);

        self::assertSame(AvailabilityResponseStatus::ACKNOWLEDGED, $response->getStatus());
    }

    public function testClaimingNoUnavailabilityIsRefusedWhileAnUnavailablePeriodExistsInTheWindow(): void
    {
        $planning = $this->planning();
        $x = $this->join($planning);
        $collection = $this->firstCollection($planning);
        $this->availabilityService->create($x, UserAvailabilityType::UNAVAILABLE, $this->brussels('2026-10-05 00:00'), $this->brussels('2026-10-08 00:00'));

        try {
            $this->collectionService->acknowledge($collection, $x, AvailabilityAcknowledgementKind::NO_UNAVAILABILITY);
            self::fail('expected a ConflictingUnavailabilityException');
        } catch (ConflictingUnavailabilityException $exception) {
            self::assertSame(1, $exception->unavailablePeriodCount);
        }

        self::assertSame(AvailabilityResponseStatus::PENDING, $this->statusOf($collection, $x), 'a refused answer records nothing');
    }

    public function testNoUnavailabilityIgnoresPeriodsOutsideTheWindowAndPreferences(): void
    {
        $planning = $this->planning('2027-01-01', '2027-04-01');
        $x = $this->join($planning);
        $collection = $this->firstCollection($planning);
        // An absence in 2026 (outside the window) and a preference inside it: neither contradicts "no unavailability".
        $this->availabilityService->create($x, UserAvailabilityType::UNAVAILABLE, $this->brussels('2026-10-05 00:00'), $this->brussels('2026-10-08 00:00'));
        $this->availabilityService->create($x, UserAvailabilityType::PREFER_DUTY, $this->brussels('2027-02-01 00:00'), $this->brussels('2027-02-04 00:00'));

        $response = $this->collectionService->acknowledge($collection, $x, AvailabilityAcknowledgementKind::NO_UNAVAILABILITY);

        self::assertSame(AvailabilityResponseStatus::ACKNOWLEDGED, $response->getStatus());
    }

    public function testAcknowledgingTwiceIsIdempotentAndKeepsTheFirstConfirmation(): void
    {
        $planning = $this->planning();
        $x = $this->join($planning);
        $collection = $this->firstCollection($planning);
        self::mockTime('2026-08-28 12:00:00 Europe/Brussels');
        $first = $this->collectionService->acknowledge($collection, $x, AvailabilityAcknowledgementKind::CONFIRMED);
        $firstAt = $first->getAcknowledgedAt();

        self::mockTime('2026-09-02 08:00:00 Europe/Brussels');
        $second = $this->collectionService->acknowledge($collection, $x, AvailabilityAcknowledgementKind::NO_UNAVAILABILITY);

        self::assertSame($first->getId(), $second->getId());
        self::assertEquals($firstAt, $second->getAcknowledgedAt(), 'a double click / second tab must not move the first confirmation');
        self::assertSame(AvailabilityAcknowledgementKind::CONFIRMED, $second->getAcknowledgementKind());
        self::assertCount(1, array_filter(
            $this->responseRepository->findByCollection($collection),
            static fn (AvailabilityCollectionResponse $r): bool => $r->isAcknowledged(),
        ), 'still exactly one response row');
    }

    public function testSomeoneWhoIsNotARespondentCannotAnswer(): void
    {
        $planning = $this->planning();
        $this->join($planning);
        $outsider = $this->createUser($this->em);

        $this->expectException(NotAnAvailabilityRespondentException::class);

        $this->collectionService->acknowledge($this->firstCollection($planning), $outsider, AvailabilityAcknowledgementKind::CONFIRMED);
    }

    public function testACollectionThatIsClosedNoLongerAcceptsAnswers(): void
    {
        $planning = $this->planning();
        $x = $this->join($planning);
        $collection = $this->firstCollection($planning);
        $this->collectionService->close($collection);

        try {
            $this->collectionService->acknowledge($collection, $x, AvailabilityAcknowledgementKind::CONFIRMED);
            self::fail('expected AvailabilityCollectionClosedException');
        } catch (AvailabilityCollectionClosedException) {
            self::assertSame(AvailabilityResponseStatus::PENDING, $this->statusOf($collection, $x));
        }
    }

    public function testAnAnswerGivenBeforeClosingStaysReadableAfterClosingWithoutError(): void
    {
        $planning = $this->planning();
        $x = $this->join($planning);
        $collection = $this->firstCollection($planning);
        $this->collectionService->acknowledge($collection, $x, AvailabilityAcknowledgementKind::CONFIRMED);
        $this->collectionService->close($collection);

        $again = $this->collectionService->acknowledge($collection, $x, AvailabilityAcknowledgementKind::CONFIRMED);

        self::assertSame(AvailabilityResponseStatus::ACKNOWLEDGED, $again->getStatus());
    }

    /* -------------------------------------------------- 6/18. availability changes */

    public function testACalendarChangeInsideTheWindowUpdatesLastAvailabilityChangeButNeverAnswersForThePerson(): void
    {
        $planning = $this->planning();
        $x = $this->join($planning);
        $collection = $this->firstCollection($planning);
        self::mockTime('2026-09-03 15:00:00 Europe/Brussels');

        $this->availabilityService->create($x, UserAvailabilityType::UNAVAILABLE, $this->brussels('2026-10-05 00:00'), $this->brussels('2026-10-08 00:00'));

        $response = $this->responseOf($collection, $x);
        self::assertSame('2026-09-03 13:00:00', $response->getLastAvailabilityChangeAt()->format('Y-m-d H:i:s'));
        self::assertSame(AvailabilityResponseStatus::PENDING, $response->getStatus(), 'entering absences is not answering');
    }

    public function testAChangeAfterTheConfirmationKeepsTheAnswerAndOnlyMovesLastChange(): void
    {
        $planning = $this->planning();
        $x = $this->join($planning);
        $collection = $this->firstCollection($planning);
        self::mockTime('2026-09-03 09:00:00 Europe/Brussels');
        $this->collectionService->acknowledge($collection, $x, AvailabilityAcknowledgementKind::CONFIRMED);

        self::mockTime('2026-09-10 09:00:00 Europe/Brussels');
        $period = $this->availabilityService->create($x, UserAvailabilityType::UNAVAILABLE, $this->brussels('2026-10-05 00:00'), $this->brussels('2026-10-08 00:00'));
        $response = $this->responseOf($collection, $x);
        self::assertSame(AvailabilityResponseStatus::ACKNOWLEDGED, $response->getStatus());
        self::assertSame('2026-09-03 07:00:00', $response->getAcknowledgedAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-10 07:00:00', $response->getLastAvailabilityChangeAt()->format('Y-m-d H:i:s'));

        self::mockTime('2026-09-11 09:00:00 Europe/Brussels');
        $this->availabilityService->reschedule($period, UserAvailabilityType::UNAVAILABLE, $this->brussels('2026-10-06 00:00'), $this->brussels('2026-10-09 00:00'));
        self::assertSame('2026-09-11 07:00:00', $this->responseOf($collection, $x)->getLastAvailabilityChangeAt()->format('Y-m-d H:i:s'));

        self::mockTime('2026-09-12 09:00:00 Europe/Brussels');
        $this->availabilityService->delete($period);
        $response = $this->responseOf($collection, $x);
        self::assertSame('2026-09-12 07:00:00', $response->getLastAvailabilityChangeAt()->format('Y-m-d H:i:s'));
        self::assertSame(AvailabilityResponseStatus::ACKNOWLEDGED, $response->getStatus(), 'still answered: the change never reopens it (D121)');
    }

    public function testAChangeOutsideTheWindowLeavesTheResponseUntouched(): void
    {
        $planning = $this->planning('2027-01-01', '2027-04-01');
        $x = $this->join($planning);
        $collection = $this->firstCollection($planning);

        $this->availabilityService->create($x, UserAvailabilityType::UNAVAILABLE, $this->brussels('2026-10-05 00:00'), $this->brussels('2026-10-08 00:00'));

        self::assertNull($this->responseOf($collection, $x)->getLastAvailabilityChangeAt());
    }

    public function testMovingAPeriodIntoTheWindowCountsAsATouch(): void
    {
        $planning = $this->planning('2027-01-01', '2027-04-01');
        $x = $this->join($planning);
        $collection = $this->firstCollection($planning);
        $period = $this->availabilityService->create($x, UserAvailabilityType::UNAVAILABLE, $this->brussels('2026-10-05 00:00'), $this->brussels('2026-10-08 00:00'));
        self::assertNull($this->responseOf($collection, $x)->getLastAvailabilityChangeAt());

        $this->availabilityService->reschedule($period, UserAvailabilityType::UNAVAILABLE, $this->brussels('2027-02-05 00:00'), $this->brussels('2027-02-08 00:00'));

        self::assertNotNull($this->responseOf($collection, $x)->getLastAvailabilityChangeAt());
    }

    public function testWindowBoundariesAreResolvedInEuropeBrussels(): void
    {
        $planning = $this->planning('2027-01-01', '2027-04-01');
        $x = $this->join($planning);
        $collection = $this->firstCollection($planning);

        // 01/04 00:30 Brussels (summer time) = 31/03 22:30 UTC: already outside the window [.., 01/04 00:00 Brussels).
        $this->availabilityService->create($x, UserAvailabilityType::UNAVAILABLE, $this->brussels('2027-04-01 00:30'), $this->brussels('2027-04-02 00:00'));
        self::assertNull($this->responseOf($collection, $x)->getLastAvailabilityChangeAt(), 'in UTC this instant is still 31/03 — the planning timezone decides');

        // 31/03 23:30 Brussels is the last half hour of the window.
        $this->availabilityService->create($x, UserAvailabilityType::PREFER_DUTY, $this->brussels('2027-03-31 23:30'), $this->brussels('2027-04-01 00:00'));
        self::assertNotNull($this->responseOf($collection, $x)->getLastAvailabilityChangeAt());
    }

    public function testAClosedCollectionIsFrozenHistory(): void
    {
        $planning = $this->planning();
        $x = $this->join($planning);
        $collection = $this->firstCollection($planning);
        self::mockTime('2026-09-03 09:00:00 Europe/Brussels');
        $this->collectionService->acknowledge($collection, $x, AvailabilityAcknowledgementKind::CONFIRMED);
        $this->collectionService->close($collection);
        $before = $this->collectionService->progress($collection);

        // Later: the calendar changes and somebody new joins.
        self::mockTime('2026-11-20 09:00:00 Europe/Brussels');
        $this->availabilityService->create($x, UserAvailabilityType::UNAVAILABLE, $this->brussels('2026-12-05 00:00'), $this->brussels('2026-12-08 00:00'));
        $newcomer = $this->join($planning, start: '2026-11-20');

        self::assertNull($this->responseOf($collection, $x)->getLastAvailabilityChangeAt(), 'a closed collection records nothing anymore');
        self::assertNull($this->responseRepository->findOneForUser($collection, $newcomer), 'a closed collection never gains a respondent');
        self::assertSame($before, $this->collectionService->progress($collection));
    }

    /* ------------------------------------------------------------ 8. late ones */

    public function testPendingResponsesAreExactlyThePeopleWhoDidNotAnswer(): void
    {
        $planning = $this->planning();
        $dupont = $this->join($planning);
        $martin = $this->join($planning);
        $durand = $this->join($planning);
        $lambert = $this->join($planning);
        $collection = $this->firstCollection($planning);
        $this->collectionService->acknowledge($collection, $dupont, AvailabilityAcknowledgementKind::CONFIRMED);
        $this->collectionService->acknowledge($collection, $martin, AvailabilityAcknowledgementKind::NO_UNAVAILABILITY);
        // Durand touches his calendar but never confirms: still late.
        $this->availabilityService->create($durand, UserAvailabilityType::UNAVAILABLE, $this->brussels('2026-10-05 00:00'), $this->brussels('2026-10-08 00:00'));

        $pending = array_map(static fn (AvailabilityCollectionResponse $r): int => $r->getUser()->getId(), $this->collectionService->pendingResponses($collection));

        self::assertEqualsCanonicalizing([$durand->getId(), $lambert->getId()], $pending);
        self::assertSame(['expected' => 4, 'acknowledged' => 2, 'pending' => 2], $this->collectionService->progress($collection));
    }

    /* ------------------------------------------------------------- 14. isolation */

    public function testTwoPlanningsNeverShareAnswers(): void
    {
        $planningA = $this->planning();
        $planningB = $this->planning();
        $x = $this->join($planningA);
        $this->join($planningB, $x);
        $collectionA = $this->firstCollection($planningA);
        $collectionB = $this->firstCollection($planningB);

        $this->collectionService->acknowledge($collectionA, $x, AvailabilityAcknowledgementKind::CONFIRMED);

        self::assertSame(AvailabilityResponseStatus::ACKNOWLEDGED, $this->statusOf($collectionA, $x));
        self::assertSame(AvailabilityResponseStatus::PENDING, $this->statusOf($collectionB, $x), 'answering planning A says nothing about planning B');
        self::assertSame(['expected' => 1, 'acknowledged' => 0, 'pending' => 1], $this->collectionService->progress($collectionB));
    }

    public function testACalendarChangeTouchesEveryOpenPlanningItOverlaps(): void
    {
        $planningA = $this->planning();
        $planningB = $this->planning();
        $x = $this->join($planningA);
        $this->join($planningB, $x);

        $this->availabilityService->create($x, UserAvailabilityType::UNAVAILABLE, $this->brussels('2026-10-05 00:00'), $this->brussels('2026-10-08 00:00'));

        // The calendar is global (D057): one change is a change for both plannings.
        self::assertNotNull($this->responseOf($this->firstCollection($planningA), $x)->getLastAvailabilityChangeAt());
        self::assertNotNull($this->responseOf($this->firstCollection($planningB), $x)->getLastAvailabilityChangeAt());
    }

    /* ------------------------------------------------- 15. membership changes */

    public function testSomeoneAddedAfterTheOpeningIsExpectedInTheOpenCollection(): void
    {
        $planning = $this->planning();
        $this->join($planning);
        $collection = $this->firstCollection($planning);
        self::assertSame(1, $this->collectionService->progress($collection)['expected']);

        self::mockTime('2026-09-15 09:00:00 Europe/Brussels');
        $late = $this->join($planning, start: '2026-09-15');

        self::assertSame(AvailabilityResponseStatus::PENDING, $this->statusOf($collection, $late));
        self::assertSame(2, $this->collectionService->progress($collection)['expected']);
    }

    public function testSomeoneWhoLeavesBeforeAnsweringIsNoLongerExpectedButKeepsTheirRow(): void
    {
        $planning = $this->planning();
        $leaver = $this->join($planning);
        $stayer = $this->join($planning);
        $collection = $this->firstCollection($planning);
        $membership = self::getContainer()->get(PlanningTeamMemberRepository::class)->findOpenMembershipForUserInPlanning($planning, $leaver);

        // Leaves before the window even starts (membership ends 2026-08-30, window starts 2026-09-01).
        $this->membershipService->endMembership($membership, $this->date('2026-08-30'));

        self::assertSame(AvailabilityResponseStatus::WITHDRAWN, $this->statusOf($collection, $leaver));
        self::assertSame(['expected' => 1, 'acknowledged' => 0, 'pending' => 1], $this->collectionService->progress($collection));
        self::assertSame([$stayer->getId()], array_map(static fn (AvailabilityCollectionResponse $r): int => $r->getUser()->getId(), $this->collectionService->pendingResponses($collection)));
    }

    public function testSomeoneWhoLeavesInTheMiddleOfTheWindowIsStillExpectedForTheirPart(): void
    {
        $planning = $this->planning();
        $leaver = $this->join($planning);
        $collection = $this->firstCollection($planning);
        $membership = self::getContainer()->get(PlanningTeamMemberRepository::class)->findOpenMembershipForUserInPlanning($planning, $leaver);

        $this->membershipService->endMembership($membership, $this->date('2026-10-15'));

        self::assertSame(AvailabilityResponseStatus::PENDING, $this->statusOf($collection, $leaver));
    }

    public function testAnAnswerGivenBeforeLeavingIsNeverErased(): void
    {
        $planning = $this->planning();
        $leaver = $this->join($planning);
        $collection = $this->firstCollection($planning);
        $this->collectionService->acknowledge($collection, $leaver, AvailabilityAcknowledgementKind::CONFIRMED);
        $membership = self::getContainer()->get(PlanningTeamMemberRepository::class)->findOpenMembershipForUserInPlanning($planning, $leaver);

        $this->membershipService->endMembership($membership, $this->date('2026-08-30'));

        self::assertSame(AvailabilityResponseStatus::ACKNOWLEDGED, $this->statusOf($collection, $leaver));
        self::assertSame(1, $this->collectionService->progress($collection)['acknowledged']);
    }

    public function testRejoiningReinstatesTheWithdrawnResponse(): void
    {
        $planning = $this->planning();
        $x = $this->join($planning);
        $collection = $this->firstCollection($planning);
        $membership = self::getContainer()->get(PlanningTeamMemberRepository::class)->findOpenMembershipForUserInPlanning($planning, $x);
        $this->membershipService->endMembership($membership, $this->date('2026-08-30'));
        self::assertSame(AvailabilityResponseStatus::WITHDRAWN, $this->statusOf($collection, $x));

        $this->join($planning, $x, start: '2026-09-05');

        self::assertSame(AvailabilityResponseStatus::PENDING, $this->statusOf($collection, $x));
        self::assertCount(1, array_filter(
            $this->responseRepository->findByCollection($collection),
            static fn (AvailabilityCollectionResponse $r): bool => $r->getUser() === $x,
        ), 'reinstated, never duplicated');
    }

    public function testAMembershipThatStartsAfterTheWindowIsNotExpectedInIt(): void
    {
        $planning = $this->planning('2026-09-01', '2027-01-01');
        $collection = $this->firstCollection($planning);

        // A future-dated membership that only begins once the collected window is over.
        $late = $this->join($planning, start: '2027-02-01');

        self::assertNull($this->responseRepository->findOneForUser($collection, $late));
        self::assertSame(0, $this->collectionService->progress($collection)['expected']);
    }

    /* ------------------------------------------------------ 11. extension flow */

    public function testExtendingCascadesTheRangeToEveryLineAndOpensOnlyTheNewSlice(): void
    {
        $planning = $this->planning('2026-09-01', '2027-01-01');
        self::getContainer()->get(PlanningLineService::class)->addLine($planning, 'Renfort', PlanningLineType::SECONDARY);
        $initial = $this->firstCollection($planning);

        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        $collections = $this->extensionService->extend($planning, null, $this->date('2027-04-01'), $planning->getCreator(), $this->date('2026-12-20'));

        self::assertSame('2027-04-01', $planning->getEndsAt()->format('Y-m-d'));
        foreach (self::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning) as $line) {
            self::assertSame('2027-04-01', $line->getPlanningPeriod()->getEndsAt()->format('Y-m-d'));
            self::assertSame('2027-04-01', $line->getPlanningPeriod()->getFairnessPeriod()->getEndsAt()->format('Y-m-d'));
            self::assertSame('2026-09-01', $line->getPlanningPeriod()->getStartsAt()->format('Y-m-d'));
        }
        self::assertCount(1, $collections);
        self::assertCount(2, $this->collectionRepository->findByPlanning($planning));
        // The first collection is exactly as it was.
        self::assertSame('2026-09-01', $initial->getStartsAt()->format('Y-m-d'));
        self::assertSame('2027-01-01', $initial->getEndsAt()->format('Y-m-d'));
        self::assertSame(AvailabilityCollectionStatus::OPEN, $initial->getStatus());
    }

    public function testSuccessiveExtensionsEachOpenTheirOwnCollection(): void
    {
        $planning = $this->planning('2026-09-01', '2027-01-01');

        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        $this->extensionService->extend($planning, null, $this->date('2027-04-01'), $planning->getCreator());
        self::mockTime('2027-03-10 09:00:00 Europe/Brussels');
        $this->extensionService->extend($planning, null, $this->date('2027-07-01'), $planning->getCreator());

        $windows = array_map(
            static fn (AvailabilityCollection $c): string => $c->getStartsAt()->format('Y-m-d').'/'.$c->getEndsAt()->format('Y-m-d'),
            array_reverse($this->collectionRepository->findByPlanning($planning)),
        );
        self::assertSame(['2026-09-01/2027-01-01', '2027-01-01/2027-04-01', '2027-04-01/2027-07-01'], $windows);
    }

    public function testExtendingByTheStartOpensTheHeadWindow(): void
    {
        $planning = $this->planning('2026-09-01', '2027-01-01');

        $collections = $this->extensionService->extend($planning, $this->date('2026-08-01'), null, $planning->getCreator());

        self::assertCount(1, $collections);
        self::assertSame('2026-08-01', $collections[0]->getStartsAt()->format('Y-m-d'));
        self::assertSame('2026-09-01', $collections[0]->getEndsAt()->format('Y-m-d'));
        self::assertSame('2026-08-01', $planning->getStartsAt()->format('Y-m-d'));
    }

    public function testExtendingBothSidesOpensTwoCollections(): void
    {
        $planning = $this->planning('2026-09-01', '2027-01-01');

        $collections = $this->extensionService->extend($planning, $this->date('2026-08-01'), $this->date('2027-02-01'), $planning->getCreator());

        self::assertCount(2, $collections);
        self::assertCount(3, $this->collectionRepository->findByPlanning($planning));
    }

    public function testNewMembersOfAnExtensionAreExpectedOnlyIfTheyStillBelongToThePlanning(): void
    {
        $planning = $this->planning('2026-09-01', '2027-01-01');
        $stays = $this->join($planning);
        $left = $this->join($planning);
        $membership = self::getContainer()->get(PlanningTeamMemberRepository::class)->findOpenMembershipForUserInPlanning($planning, $left);
        $this->membershipService->endMembership($membership, $this->date('2026-11-30'));

        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        $extension = $this->extensionService->extend($planning, null, $this->date('2027-04-01'), $planning->getCreator())[0];

        self::assertSame(AvailabilityResponseStatus::PENDING, $this->statusOf($extension, $stays));
        self::assertNull($this->responseRepository->findOneForUser($extension, $left), 'someone who left before the new window is never asked about it');
    }

    public function testAnExtensionWithoutAnyNewDateIsRefusedAndOpensNothing(): void
    {
        $planning = $this->planning('2026-09-01', '2027-01-01');

        try {
            $this->extensionService->extend($planning, null, $this->date('2027-01-01'), $planning->getCreator());
            self::fail('expected NoNewPlanningRangeException');
        } catch (NoNewPlanningRangeException) {
            self::assertCount(1, $this->collectionRepository->findByPlanning($planning), 'never an empty collection');
        }
    }

    public function testTheSameExtensionSentTwiceOpensOnlyOneCollection(): void
    {
        $planning = $this->planning('2026-09-01', '2027-01-01');
        $this->extensionService->extend($planning, null, $this->date('2027-04-01'), $planning->getCreator());

        try {
            // A second tab / double click: same target range, computed from the range the first one produced.
            $this->extensionService->extend($planning, null, $this->date('2027-04-01'), $planning->getCreator());
            self::fail('expected NoNewPlanningRangeException');
        } catch (NoNewPlanningRangeException) {
            self::assertCount(2, $this->collectionRepository->findByPlanning($planning));
        }
    }

    public function testShrinkingAPlanningIsRefused(): void
    {
        $planning = $this->planning('2026-09-01', '2027-01-01');

        $this->expectException(PlanningRangeShrinkException::class);

        $this->extensionService->extend($planning, null, $this->date('2026-12-01'), $planning->getCreator());
    }

    public function testAValidatedLineBlocksTheExtensionAndLeavesEverythingUntouched(): void
    {
        $planning = $this->planning('2026-09-01', '2027-01-01');
        $period = self::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningPeriod();
        $period->transitionTo(PlanningPeriodStatus::GENERATED);
        $period->transitionTo(PlanningPeriodStatus::VALIDATED);
        $this->em->flush();

        try {
            $this->extensionService->extend($planning, null, $this->date('2027-04-01'), $planning->getCreator());
            self::fail('expected PlanningPeriodLockedException');
        } catch (PlanningPeriodLockedException) {
            self::assertSame('2027-01-01', $planning->getEndsAt()->format('Y-m-d'));
            self::assertSame('2027-01-01', $period->getEndsAt()->format('Y-m-d'));
            self::assertCount(1, $this->collectionRepository->findByPlanning($planning));
        }
    }

    public function testAGeneratedButNotValidatedLineCanBeExtended(): void
    {
        $planning = $this->planning('2026-09-01', '2027-01-01');
        $period = self::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningPeriod();
        $period->transitionTo(PlanningPeriodStatus::GENERATED);
        $this->em->flush();

        $this->extensionService->extend($planning, null, $this->date('2027-04-01'), $planning->getCreator());

        self::assertSame('2027-04-01', $period->getEndsAt()->format('Y-m-d'));
    }

    public function testADeadlineInThePastIsRefusedBeforeAnythingChanges(): void
    {
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        $planning = $this->planning('2026-09-01', '2027-01-01');

        try {
            $this->extensionService->extend($planning, null, $this->date('2027-04-01'), $planning->getCreator(), $this->date('2026-12-09'));
            self::fail('expected InvalidAvailabilityDeadlineException');
        } catch (InvalidAvailabilityDeadlineException) {
            self::assertSame('2027-01-01', $planning->getEndsAt()->format('Y-m-d'), 'a refused deadline must not leave a half-extended planning');
            self::assertCount(1, $this->collectionRepository->findByPlanning($planning));
        }
    }

    public function testADeadlineTodayIsAccepted(): void
    {
        self::mockTime('2026-12-10 23:30:00 Europe/Brussels');
        $planning = $this->planning('2026-09-01', '2027-01-01');

        $extension = $this->extensionService->extend($planning, null, $this->date('2027-04-01'), $planning->getCreator(), $this->date('2026-12-10'))[0];

        self::assertSame('2026-12-10', $extension->getDeadline()->format('Y-m-d'), 'today is judged in the planning timezone, not UTC');
    }

    /* ------------------------------------------------------ explicit collections */

    public function testAnExplicitCollectionCannotOverlapAnExistingOne(): void
    {
        $planning = $this->planning('2026-09-01', '2027-01-01');

        $this->expectException(AvailabilityCollectionOverlapException::class);

        $this->collectionService->open($planning, new DateWindow($this->date('2026-12-01'), $this->date('2026-12-15')), $planning->getCreator());
    }

    public function testAnExplicitCollectionMustStayInsideThePlanning(): void
    {
        $planning = $this->planning('2026-09-01', '2027-01-01');

        $this->expectException(AvailabilityCollectionOutsidePlanningException::class);

        $this->collectionService->open($planning, new DateWindow($this->date('2027-01-01'), $this->date('2027-02-01')), $planning->getCreator());
    }

    public function testTheDeadlineCanBeChangedWhileOpenButNotOnceClosed(): void
    {
        $planning = $this->planning();
        $collection = $this->firstCollection($planning);

        $this->collectionService->changeDeadline($collection, $this->date('2026-09-30'));
        self::assertSame('2026-09-30', $collection->getDeadline()->format('Y-m-d'));

        $this->collectionService->changeDeadline($collection, null);
        self::assertNull($collection->getDeadline());

        try {
            $this->collectionService->changeDeadline($collection, $this->date('2026-08-24'));
            self::fail('expected InvalidAvailabilityDeadlineException');
        } catch (InvalidAvailabilityDeadlineException) {
        }

        $this->collectionService->close($collection);
        $this->expectException(AvailabilityCollectionClosedException::class);
        $this->collectionService->changeDeadline($collection, $this->date('2026-10-30'));
    }

    /* -------------------------------------------- 13. workflow ≠ eligibility */

    public function testTheSnapshotCapturesTheCalendarWhateverTheAnswerStatus(): void
    {
        $planning = $this->planning('2027-01-01', '2027-05-01');
        $answered = $this->join($planning);
        $silent = $this->join($planning);
        $collection = $this->firstCollection($planning);
        $this->availabilityService->create($answered, UserAvailabilityType::UNAVAILABLE, $this->brussels('2027-02-01 00:00'), $this->brussels('2027-02-05 00:00'));
        $this->availabilityService->create($silent, UserAvailabilityType::UNAVAILABLE, $this->brussels('2027-03-01 00:00'), $this->brussels('2027-03-05 00:00'));
        $this->collectionService->acknowledge($collection, $answered, AvailabilityAcknowledgementKind::CONFIRMED);

        $period = self::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningPeriod();
        $this->activateRuleSet(self::getContainer()->get(PlanningRuleSetService::class), $period->getTeam());
        $generation = new PlanningGeneration($period);
        $this->em->persist($generation);
        $this->em->flush();
        $snapshot = self::getContainer()->get(PlanningSnapshotService::class)->createSnapshot($generation);

        // Both people's absences are in the snapshot: the answer status has no say in eligibility.
        $counts = [];
        foreach ($snapshot->getMembers() as $member) {
            $counts[(string) $member->getSourceUserStableId()] = $member->getAvailabilityPeriods()->count();
        }
        self::assertSame(
            [(string) $answered->getStableId() => 1, (string) $silent->getStableId() => 1],
            $counts,
        );
    }

    /* --------------------------------------------------- DB-level invariants */

    public function testTheDatabaseRefusesTwoOverlappingCollectionsOfOnePlanning(): void
    {
        $planning = $this->planning('2026-09-01', '2027-04-01');
        $connection = $this->em->getConnection();
        $insert = static fn (string $stableId, string $from, string $to) => $connection->executeStatement(
            'INSERT INTO availability_collections (stable_id, starts_at, ends_at, opened_at, status, created_at, updated_at, planning_id, created_by_id) VALUES (?, ?, ?, NOW(), \'OPEN\', NOW(), NOW(), ?, ?)',
            [$stableId, $from, $to, $planning->getId(), $planning->getCreator()->getId()],
        );

        $this->expectException(DbalException::class);
        $this->expectExceptionMessageMatches('/excl_availability_collections_no_overlap/');

        // The initial collection already covers the whole planning; a raw insert bypasses the service check.
        $insert((string) \Symfony\Component\Uid\Uuid::v7(), '2026-12-01', '2026-12-15');
    }

    public function testTheDatabaseRefusesHalfAnAcknowledgement(): void
    {
        $planning = $this->planning();
        $x = $this->join($planning);
        $response = $this->responseOf($this->firstCollection($planning), $x);

        $this->expectException(DbalException::class);
        $this->expectExceptionMessageMatches('/chk_availability_collection_responses_ack/');

        $this->em->getConnection()->executeStatement(
            'UPDATE availability_collection_responses SET acknowledged_at = NOW() WHERE id = ?',
            [$response->getId()],
        );
    }
}
