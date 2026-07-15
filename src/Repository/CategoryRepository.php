<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Restaurant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Case-insensitive exact-name lookup across ALL of the restaurant's
     * category translations, regardless of locale — the Category twin of
     * IngredientRepository::findExistingByNameAnyLocale(), same shape, same
     * reason: used by MenuImportAssembler so an imported "Pizzas" reuses the
     * restaurant's existing category (whatever locale it happens to be
     * named in) instead of creating a duplicate.
     */
    public function findExistingByNameAnyLocale(Restaurant $restaurant, string $name): ?Category
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.translations', 't')
            ->andWhere('c.restaurant = :restaurant')
            ->andWhere('LOWER(t.name) = LOWER(:name)')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('name', trim($name))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(Category $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Category $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findActiveByRestaurant(int $restaurantId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.restaurant = :restaurant')
            ->andWhere('c.active = true')
            ->setParameter('restaurant', $restaurantId)
            ->orderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
