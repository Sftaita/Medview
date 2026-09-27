<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planning;
use App\Entity\User;
use App\Entity\WeeklyDutyReminder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WeeklyDutyReminder>
 */
class WeeklyDutyReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeeklyDutyReminder::class);
    }

    public function exists(User $user, Planning $planning, \DateTimeImmutable $weekStart): bool
    {
        return null !== $this->findOneBy(['user' => $user, 'planning' => $planning, 'weekStart' => $weekStart]);
    }
}
