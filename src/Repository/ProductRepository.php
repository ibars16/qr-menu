<?php

namespace App\Repository;

use App\Entity\Product;
use App\Entity\Restaurant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function save(Product $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Product $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findActiveByCategory(int $categoryId): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.category = :category')
            ->andWhere('p.active = true')
            ->setParameter('category', $categoryId)
            ->orderBy('p.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Product[] */
    public function findActiveForRestaurant(Restaurant $restaurant): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.category', 'c')
            ->andWhere('c.restaurant = :restaurant')
            ->andWhere('p.active = true')
            ->andWhere('c.active = true')
            ->setParameter('restaurant', $restaurant)
            ->getQuery()
            ->getResult();
    }

    /**
     * Cheap existence check (no entity hydration) used to decide whether the
     * menu page needs to detour through the translating/loading screen — see
     * MenuController::renderMenu().
     */
    public function hasAnyMissingTranslation(Restaurant $restaurant, string $locale): bool
    {
        $count = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->innerJoin('p.category', 'c')
            ->leftJoin('p.translations', 't', 'WITH', 't.locale = :locale')
            ->andWhere('c.restaurant = :restaurant')
            ->andWhere('p.active = true')
            ->andWhere('c.active = true')
            ->andWhere('t.id IS NULL')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
