<?php

namespace App\Repository;

use App\Entity\MenuImportBatch;
use App\Entity\Restaurant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MenuImportBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MenuImportBatch::class);
    }

    /** @return MenuImportBatch[] */
    public function findForRestaurant(Restaurant $restaurant): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.restaurant = :restaurant')
            ->setParameter('restaurant', $restaurant)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
