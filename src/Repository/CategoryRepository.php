<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Restaurant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CategoryRepository extends ServiceEntityRepository
{
    /** Same safety-net TTL and reasoning as ProductRepository::CACHE_TTL. */
    private const CACHE_TTL = 21600;

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

    /**
     * Bulk-initializes $categories' translations collections in one query —
     * same fetch-join-per-request idiom as ProductRepository::warmMenuCollections()
     * and ProductTagRepository::warmTranslations(), so the per-category
     * getTranslation() calls in CategoryTranslationService and the public
     * menu templates don't each trigger their own lazy load.
     *
     * $cacheKeyPrefix is opt-in — only ever passed from the public menu path
     * (via CategoryTranslationService), so admin screens keep getting live
     * data by default.
     *
     * @param Category[] $categories
     */
    public function warmTranslations(array $categories, ?string $cacheKeyPrefix = null): void
    {
        if ($categories === []) {
            return;
        }

        $query = $this->createQueryBuilder('c')
            ->select('c', 'tr')
            ->leftJoin('c.translations', 'tr')
            ->where('c IN (:categories)')
            ->setParameter('categories', $categories)
            ->getQuery();

        if ($cacheKeyPrefix !== null) {
            $query->enableResultCache(self::CACHE_TTL, $cacheKeyPrefix . '_categorytranslations');
        }

        $query->getResult();
    }
}
