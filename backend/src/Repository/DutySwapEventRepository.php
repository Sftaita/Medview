<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DutySwapEvent;
use App\Entity\DutySwapRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DutySwapEvent>
 */
class DutySwapEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DutySwapEvent::class);
    }

    /**
     * @return list<DutySwapEvent> chronological (insertion order breaks ties)
     */
    public function findByRequest(DutySwapRequest $request): array
    {
        return $this->findBy(['request' => $request], ['occurredAt' => 'ASC', 'id' => 'ASC']);
    }
}
