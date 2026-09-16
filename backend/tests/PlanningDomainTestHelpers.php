<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\FairnessPeriod;
use App\Entity\PlanningPeriod;
use App\Entity\Team;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared fixture builders for planning-domain tests (KernelTestCase-based,
 * each wrapped in a rolled-back transaction by dama/doctrine-test-bundle —
 * see D017/D024 for why that makes persisting real fixtures per test safe).
 */
trait PlanningDomainTestHelpers
{
    private function createTeam(EntityManagerInterface $em, string $name = 'Cardiology', string $slug = 'cardiology'): Team
    {
        $team = new Team($name, $slug.'-'.bin2hex(random_bytes(4)));
        $em->persist($team);
        $em->flush();

        return $team;
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
        Team $team,
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
