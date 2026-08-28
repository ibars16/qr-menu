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

    public function countConversations(Restaurant $restaurant, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(DISTINCT l.conversationId)')
            ->andWhere('l.restaurant = :restaurant')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return array<int, array{locale: string, count: int}> ordered most-used first */
    public function localeBreakdown(Restaurant $restaurant, \DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('l.locale AS locale', 'COUNT(l.id) AS cnt')
            ->andWhere('l.restaurant = :restaurant')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('since', $since)
            ->groupBy('l.locale')
            ->orderBy('cnt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row) => ['locale' => $row['locale'], 'count' => (int) $row['cnt']],
            $rows
        );
    }
}
