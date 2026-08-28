<?php

namespace App\Repository;

use App\Entity\Product;
use App\Entity\ProductView;
use App\Entity\Restaurant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProductViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductView::class);
    }

    /**
     * Top-viewed products for $restaurant since $since, joined to Product so
     * the caller gets a usable entity back instead of a bare id (avoids an
     * N+1 lookup for dish names on the stats page).
     *
     * @return array<int, array{product: \App\Entity\Product, views: int}>
     */
    public function topProducts(Restaurant $restaurant, \DateTimeImmutable $since, int $limit = 10): array
    {
        // Rooted on Product (not ProductView) — Doctrine refuses to select a
        // joined entity via SELECT when the query's own root alias isn't
        // also in the SELECT list ("Cannot select entity through
        // identification variables without choosing at least one root
        // entity alias"), so `p` has to be the root here, not a join off `v`.
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('p AS product', 'COUNT(v.id) AS views')
            ->from(Product::class, 'p')
            ->innerJoin(ProductView::class, 'v', 'WITH', 'v.product = p')
            ->andWhere('v.restaurant = :restaurant')
            ->andWhere('v.createdAt >= :since')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('since', $since)
            ->groupBy('p.id')
            ->orderBy('views', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row) => ['product' => $row['product'], 'views' => (int) $row['views']],
            $rows
        );
    }

    /**
     * Day-bucketed view counts for $restaurant since $since, ascending,
     * inclusive of days with zero views only if a row exists that day (the
     * caller fills gap days). Uses a native query (DATE()) rather than DQL
     * since Doctrine has no built-in DATE() function.
     *
     * @return array<int, array{day: string, views: int}>
     */
    public function viewsPerDay(Restaurant $restaurant, \DateTimeImmutable $since): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT DATE(created_at) AS day, COUNT(*) AS views
                FROM product_view
                WHERE restaurant_id = :restaurant AND created_at >= :since
                GROUP BY DATE(created_at)
                ORDER BY day ASC';

        $rows = $conn->fetchAllAssociative($sql, [
            'restaurant' => $restaurant->getId(),
            'since' => $since->format('Y-m-d H:i:s'),
        ]);

        return array_map(
            static fn (array $row) => ['day' => (string) $row['day'], 'views' => (int) $row['views']],
            $rows
        );
    }

    public function totalViews(Restaurant $restaurant, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.restaurant = :restaurant')
            ->andWhere('v.createdAt >= :since')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Ids of every dish with at least one view since $since — feeds
     * ProductRepository::findActiveExcludingIds() for the "dishes with no
     * views" list (see StatsController).
     *
     * @return int[]
     */
    public function viewedProductIds(Restaurant $restaurant, \DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('DISTINCT IDENTITY(v.product) AS id')
            ->andWhere('v.restaurant = :restaurant')
            ->andWhere('v.createdAt >= :since')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }
}
