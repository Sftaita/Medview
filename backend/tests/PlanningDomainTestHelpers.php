<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\FairnessPeriod;
use App\Entity\Planning;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningTeam;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared fixture builders for planning-domain tests (KernelTestCase-based,
 * each wrapped in a rolled-back transaction by dama/doctrine-test-bundle —
 * see D017/D024 for why that makes persisting real fixtures per test safe).
 */
trait PlanningDomainTestHelpers
{
    /**
     * A PlanningTeam always belongs to exactly one Planning
     * (docs/decisions.md D079). When $planning is omitted, a fresh
     * throwaway Planning is created for it — two createTeam() calls with no
     * explicit $planning therefore belong to two *different* Plannings by
     * default, which is what most cross-team tests in this suite actually
     * want (they are testing mono-team consistency, not Planning sharing).
     * Pass the same $planning explicitly when a test specifically needs two
     * teams of the *same* Planning (e.g. membership-conflict scenarios).
     */
    private function createTeam(EntityManagerInterface $em, string $name = 'Cardiology', ?Planning $planning = null): PlanningTeam
    {
        $planning ??= $this->createStandalonePlanning($em);

        $team = new PlanningTeam($planning, $name);
        $em->persist($team);
        $em->flush();

        return $team;
    }

    private function createStandalonePlanning(EntityManagerInterface $em, ?User $creator = null): Planning
    {
        $planning = new Planning(
            'Test Planning '.bin2hex(random_bytes(4)),
            $creator ?? $this->createUser($em),
            $this->date('2027-01-01'),
            $this->date('2028-01-01'),
            'Europe/Brussels',
        );
        $em->persist($planning);
        $em->flush();

        return $planning;
    }

    private function createUser(EntityManagerInterface $em, ?string $email = null): User
    {
        $user = new User(
            $email ?? sprintf('user-%s@example.com', bin2hex(random_bytes(4))),
            'Test',
            'User',
            'irrelevant-hash',
        );
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }

    private function createPlanningPeriod(
        EntityManagerInterface $em,
        PlanningTeam $team,
        string $startsAt = '2027-01-01',
        string $endsAt = '2027-05-01',
    ): PlanningPeriod {
        $fairnessPeriod = new FairnessPeriod($team, '2027', $this->date('2027-01-01'), $this->date('2028-01-01'));
        $em->persist($fairnessPeriod);

        $planningPeriod = new PlanningPeriod($team, $fairnessPeriod, 'Jan-Apr', $this->date($startsAt), $this->date($endsAt));
        $em->persist($planningPeriod);
        $em->flush();

        return $planningPeriod;
    }
}
