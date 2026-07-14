<?php

namespace App\Repository;

use App\Entity\Restaurant;
use App\Entity\SmartWaiterExchangeLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SmartWaiterExchangeLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SmartWaiterExchangeLog::class);
    }

    public function countConversations(Restaurant $restaurant): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(DISTINCT l.conversationId)')
            ->andWhere('l.restaurant = :restaurant')
            ->setParameter('restaurant', $restaurant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function averageLatencyMs(Restaurant $restaurant): ?float
    {
        $avg = $this->createQueryBuilder('l')
            ->select('AVG(l.latencyMs)')
            ->andWhere('l.restaurant = :restaurant')
            ->andWhere('l.success = true')
            ->setParameter('restaurant', $restaurant)
            ->getQuery()
            ->getSingleScalarResult();

        return $avg !== null ? (float) $avg : null;
    }
}
