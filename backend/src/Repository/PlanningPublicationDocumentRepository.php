<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningPublication;
use App\Entity\PlanningPublicationDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningPublicationDocument>
 */
class PlanningPublicationDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningPublicationDocument::class);
    }

    public function findOneByPublication(PlanningPublication $publication): ?PlanningPublicationDocument
    {
        return $this->findOneBy(['publication' => $publication]);
    }
}
