<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningPublication;
use App\Entity\PlanningPublicationDelivery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningPublicationDelivery>
 */
class PlanningPublicationDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningPublicationDelivery::class);
    }

    /**
     * @return list<PlanningPublicationDelivery>
     */
    public function findByPublication(PlanningPublication $publication): array
    {
        return $this->findBy(['publication' => $publication], ['id' => 'ASC']);
    }
}
