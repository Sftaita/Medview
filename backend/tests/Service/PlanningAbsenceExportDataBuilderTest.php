<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Planning;
use App\Entity\PlanningLineType;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use App\Entity\UserAvailabilityPeriod;
use App\Entity\UserAvailabilityType;
use App\Repository\PlanningLineRepository;
use App\Service\PlanningAbsenceExportData;
use App\Service\PlanningAbsenceExportDataBuilder;
use App\Service\PlanningAbsenceExportMember;
use App\Service\PlanningExtensionService;
use App\Service\PlanningLineService;
use App\Service\PlanningService;
use App\Service\PlanningTeamMembershipService;
use App\Service\TeamMemberNonParticipationService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The data of the absence export (docs/availability.md §11, docs/decisions.md
 * D181), built from real entities: who is in, which civil days count, and
 * that nothing outside the planning period — or outside a person's
 * memberships — ever leaks in.
 */
final class PlanningAbsenceExportDataBuilderTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use PlanningDomainTestHelpers;

    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-12-10 09:00:00 Europe/Brussels');
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheWholeScopeOfAPlanningStartingAndEndingMidMonth(): void
    {
        // 15 January 2027 → 15 April 2027 exclusive: last day 14 April.
        $planning = $this->planning('2027-01-15', '2027-04-15');
        $primary = $this->lines($planning)[0];
        $urgences = self::getContainer()->get(PlanningLineService::class)->addLine($planning, 'Urgences', PlanningLineType::SECONDARY);
        $membership = self::getContainer()->get(PlanningTeamMembershipService::class);

        $jean = $this->person('Jean', 'Dupont');
        $alice = $this->person('Alice', 'Martin');
        $pierre = $this->person('Pierre', 'Lambert');
        $elodie = $this->person('Élodie', 'Écuyer');
        $bob = $this->person('Bob', 'Bernard');
        $claire = $this->person('Claire', 'Petit');
        $zacharie = $this->person('Zacharie', 'Bornes');
        foreach ([$jean, $alice, $pierre, $elodie, $bob, $zacharie] as $user) {
            $membership->addMember($primary->getPlanningTeam(), $user, TeamMemberRole::MEMBER, $this->date('2027-01-15'));
        }
        // Two successive stints, on two lines, with a gap between them.
        $first = $membership->addMember($primary->getPlanningTeam(), $claire, TeamMemberRole::MEMBER, $this->date('2027-01-15'));
        $membership->endMembership($first, $this->date('2027-02-01'));
        $membership->addMember($urgences->getPlanningTeam(), $claire, TeamMemberRole::MEMBER, $this->date('2027-02-15'));

        // Jean: whole days, then a morning and an afternoon of the same legacy day (one day, never counted twice).
        $this->absent($jean, '2027-01-15T00:00:00+01:00', '2027-01-19T00:00:00+01:00');
        $this->absent($jean, '2027-02-03T00:00:00+01:00', '2027-02-07T00:00:00+01:00');
        $this->absent($jean, '2027-02-07T08:00:00+01:00', '2027-02-07T10:00:00+01:00');
        $this->absent($jean, '2027-02-07T14:00:00+01:00', '2027-02-07T18:00:00+01:00');
        $this->absent($jean, '2027-03-01T00:00:00+01:00', '2027-03-05T00:00:00+01:00', UserAvailabilityType::PREFER_DUTY);
        // Alice: starts before, ends after, and two periods entirely outside.
        $this->absent($alice, '2027-01-10T00:00:00+01:00', '2027-01-20T00:00:00+01:00');
        $this->absent($alice, '2027-04-10T00:00:00+02:00', '2027-04-20T00:00:00+02:00');
        $this->absent($alice, '2026-12-01T00:00:00+01:00', '2026-12-05T00:00:00+01:00');
        $this->absent($alice, '2027-06-01T00:00:00+02:00', '2027-06-05T00:00:00+02:00');
        // Élodie: one period covering far more than the planning.
        $this->absent($elodie, '2026-12-01T00:00:00+01:00', '2027-06-01T00:00:00+02:00');
        // Bob: across the spring DST change, and a legacy period with times of day.
        $this->absent($bob, '2027-03-27T00:00:00+01:00', '2027-03-29T00:00:00+02:00');
        $this->absent($bob, '2027-02-10T14:00:00+01:00', '2027-02-11T09:00:00+01:00');
        // Zacharie: ends exactly at the start, starts exactly at the exclusive end.
        $this->absent($zacharie, '2027-01-01T00:00:00+01:00', '2027-01-15T00:00:00+01:00');
        $this->absent($zacharie, '2027-04-15T00:00:00+02:00', '2027-04-17T00:00:00+02:00');
        // Claire: one period across her gap.
        $this->absent($claire, '2027-01-25T00:00:00+01:00', '2027-02-20T00:00:00+01:00');
        // An administrative non-participation is not an unavailability.
        self::getContainer()->get(TeamMemberNonParticipationService::class)->create(
            $this->membershipOf($planning, $pierre),
            new \DateTimeImmutable('2027-02-01T00:00:00+01:00'),
            new \DateTimeImmutable('2027-02-10T00:00:00+01:00'),
        );

        $data = $this->build($planning);

        self::assertSame('2027-01-15', $data->first);
        self::assertSame('2027-04-14', $data->last, 'endsAt is exclusive.');
        self::assertSame('Gardes Orthopédie', $data->planningName);
        self::assertSame(
            ['Bernard', 'Bornes', 'Dupont', 'Écuyer', 'Lambert', 'Martin', 'Petit'],
            array_map(static fn (PlanningAbsenceExportMember $m): string => $m->lastName, $data->members),
            'Alphabetical by last name, accents sorted as French readers expect; the creator is not a participant.',
        );

        $member = $this->memberOf($data, $jean);
        self::assertSame([['2027-01-15', '2027-01-18'], ['2027-02-03', '2027-02-07']], $member->absenceRuns());
        self::assertCount(9, $member->days, '4 + 5 days; 7 February, touched by two periods, counts once; the preference is not exported.');
        self::assertSame(['Ligne principale'], $member->lineNames);
        self::assertSame((string) $jean->getStableId(), $member->userStableId);

        $member = $this->memberOf($data, $alice);
        self::assertSame([['2027-01-15', '2027-01-19'], ['2027-04-10', '2027-04-14']], $member->absenceRuns(), 'Clipped to the planning; nothing outside it.');
        self::assertCount(10, $member->days);

        self::assertSame([], $this->memberOf($data, $zacharie)->days, 'Ending exactly at the start, or starting exactly at the exclusive end, touches no day of the planning.');
        self::assertSame([], $this->memberOf($data, $pierre)->days, 'No unavailability: listed with zero days — the non-participation is not one.');

        $member = $this->memberOf($data, $elodie);
        self::assertSame([['2027-01-15', '2027-04-14']], $member->absenceRuns());
        self::assertCount(17 + 28 + 31 + 14, $member->days, 'Every day of the planning, none outside.');

        $member = $this->memberOf($data, $bob);
        self::assertSame([['2027-02-10', '2027-02-11'], ['2027-03-27', '2027-03-28']], $member->absenceRuns(), 'Partial days count; the DST weekend is exactly two days.');

        $member = $this->memberOf($data, $claire);
        self::assertSame([['2027-01-25', '2027-01-31'], ['2027-02-15', '2027-02-19']], $member->absenceRuns(), 'Only the days she was a member.');
        self::assertSame(['Ligne principale', 'Urgences'], $member->lineNames);
        self::assertSame([['2027-01-15', '2027-01-31'], ['2027-02-15', '2027-04-14']], $member->membershipRuns);
        self::assertSame([['2027-01-15', '2027-04-14']], $this->memberOf($data, $jean)->membershipRuns);
    }

    public function testAPlanningAcrossTwoYearsAndAnotherPlanningsMembersStayOut(): void
    {
        $planning = $this->planning('2026-12-20', '2027-01-11');
        $other = $this->planning('2026-12-20', '2027-01-11', 'Autre planning');
        $membership = self::getContainer()->get(PlanningTeamMembershipService::class);

        $inside = $this->person('Anna', 'Dedans');
        $outside = $this->person('Oscar', 'Dehors');
        $membership->addMember($this->lines($planning)[0]->getPlanningTeam(), $inside, TeamMemberRole::MEMBER, $this->date('2026-12-20'));
        $membership->addMember($this->lines($other)[0]->getPlanningTeam(), $outside, TeamMemberRole::MEMBER, $this->date('2026-12-20'));
        $this->absent($inside, '2026-12-30T00:00:00+01:00', '2027-01-03T00:00:00+01:00');
        $this->absent($outside, '2026-12-30T00:00:00+01:00', '2027-01-03T00:00:00+01:00');

        $data = $this->build($planning);

        self::assertSame(['Dedans'], array_map(static fn (PlanningAbsenceExportMember $m): string => $m->lastName, $data->members), 'Only this planning\'s participants.');
        self::assertSame([['2026-12-30', '2027-01-02']], $data->members[0]->absenceRuns());
        self::assertSame('2027-01-10', $data->last);
    }

    public function testAnExtensionIsTakenIntoAccount(): void
    {
        $planning = $this->planning('2027-01-15', '2027-02-01');
        $user = $this->person('Eva', 'Longue');
        self::getContainer()->get(PlanningTeamMembershipService::class)->addMember($this->lines($planning)[0]->getPlanningTeam(), $user, TeamMemberRole::MEMBER, $this->date('2027-01-15'));
        $this->absent($user, '2027-02-10T00:00:00+01:00', '2027-02-12T00:00:00+01:00');

        self::assertSame([], $this->build($planning)->members[0]->days, 'Not in the planning yet.');

        self::getContainer()->get(PlanningExtensionService::class)->extend($planning, null, $this->date('2027-03-01'), $planning->getCreator());

        $data = $this->build($planning);
        self::assertSame('2027-02-28', $data->last);
        self::assertSame([['2027-02-10', '2027-02-11']], $data->members[0]->absenceRuns(), 'The extended period is exported (the open membership follows it).');
    }

    public function testThePlanningTimezoneDecidesTheDays(): void
    {
        $planning = $this->planning('2027-01-01', '2027-02-01', 'Gardes NY', 'America/New_York');
        $user = $this->person('Nina', 'York');
        self::getContainer()->get(PlanningTeamMembershipService::class)->addMember($this->lines($planning)[0]->getPlanningTeam(), $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));
        // Midnight to midnight in New York, stored in UTC.
        $this->absent($user, '2027-01-10T05:00:00+00:00', '2027-01-12T05:00:00+00:00');

        self::assertSame([['2027-01-10', '2027-01-11']], $this->build($planning)->members[0]->absenceRuns());
    }

    public function testAllParticipantsAndAbsencesAreReadInAConstantNumberOfQueries(): void
    {
        $planning = $this->planning('2027-01-15', '2027-04-15');
        $membership = self::getContainer()->get(PlanningTeamMembershipService::class);
        for ($i = 0; $i < 8; ++$i) {
            $user = $this->person('P'.$i, 'Personne'.$i);
            $membership->addMember($this->lines($planning)[0]->getPlanningTeam(), $user, TeamMemberRole::MEMBER, $this->date('2027-01-15'));
            $this->absent($user, '2027-02-0'.($i + 1).'T00:00:00+01:00', '2027-02-1'.$i.'T00:00:00+01:00');
        }
        $this->em->clear();
        $planning = $this->em->getRepository(Planning::class)->find($planning->getId());

        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();
        $data = $this->build($planning);
        $queries = \count($holder->getData()['default'] ?? []);

        self::assertCount(8, $data->members);
        self::assertSame(8, array_sum(array_map(static fn (PlanningAbsenceExportMember $m): int => \count($m->days) > 0 ? 1 : 0, $data->members)));
        self::assertGreaterThan(0, $queries, 'The SQL log is live.');
        self::assertLessThanOrEqual(3, $queries, 'Lines, memberships with their users, periods — never one query per person.');
    }

    // --- helpers -------------------------------------------------------------------

    private function build(Planning $planning): PlanningAbsenceExportData
    {
        return self::getContainer()->get(PlanningAbsenceExportDataBuilder::class)->build($planning);
    }

    private function planning(string $startsAt, string $endsAt, string $name = 'Gardes Orthopédie', string $timezone = self::TZ): Planning
    {
        return self::getContainer()->get(PlanningService::class)->create($name, $this->createUser($this->em), $this->date($startsAt), $this->date($endsAt), $timezone, 'Ligne principale');
    }

    /** @return list<\App\Entity\PlanningLine> */
    private function lines(Planning $planning): array
    {
        return self::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning);
    }

    private function person(string $firstName, string $lastName): User
    {
        $user = new User(\sprintf('%s-%s@example.com', strtolower($firstName), bin2hex(random_bytes(4))), $firstName, $lastName, 'irrelevant-hash');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function absent(User $user, string $startsAt, string $endsAt, UserAvailabilityType $type = UserAvailabilityType::UNAVAILABLE): void
    {
        $this->em->persist(new UserAvailabilityPeriod($user, $type, new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt)));
        $this->em->flush();
    }

    private function membershipOf(Planning $planning, User $user): \App\Entity\PlanningTeamMember
    {
        return self::getContainer()->get(\App\Repository\PlanningTeamMemberRepository::class)->findOpenMembershipsForUserInPlanning($planning, $user)[0];
    }

    private function memberOf(PlanningAbsenceExportData $data, User $user): PlanningAbsenceExportMember
    {
        foreach ($data->members as $member) {
            if ($member->userStableId === (string) $user->getStableId()) {
                return $member;
            }
        }

        self::fail($user->getLastName().' is missing from the export.');
    }
}
