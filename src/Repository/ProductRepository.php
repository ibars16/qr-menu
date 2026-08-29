<?php

namespace App\Repository;

use App\Entity\Product;
use App\Entity\Restaurant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProductRepository extends ServiceEntityRepository
{
    /**
     * Safety-net TTL for the public-menu content cache (see MenuController)
     * — independent of Restaurant::$menuContentVersion, which is the fast
     * path for invalidation; this is the backstop for the couple of write
     * paths that can't cleanly bump one restaurant's version (see
     * ProductAllergenResolver's docblock for the concrete case).
     */
    private const CACHE_TTL = 21600;

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

    /**
     * $cacheKeyPrefix is opt-in and only ever passed by the public menu
     * (MenuController) — every other caller (admin screens) must keep
     * getting live data, so leaving it null (the default) is what keeps
     * this method safe to call from anywhere else.
     *
     * @return Product[]
     */
    public function findActiveForRestaurant(Restaurant $restaurant, ?string $cacheKeyPrefix = null): array
    {
        $query = $this->createQueryBuilder('p')
            ->innerJoin('p.category', 'c')
            ->andWhere('c.restaurant = :restaurant')
            ->andWhere('p.active = true')
            ->andWhere('c.active = true')
            ->setParameter('restaurant', $restaurant)
            ->getQuery();

        if ($cacheKeyPrefix !== null) {
            $query->enableResultCache(self::CACHE_TTL, $cacheKeyPrefix . '_active');
        }

        return $query->getResult();
    }

    /**
     * Active dishes for $restaurant NOT in $excludeIds, in menu order — used
     * with ProductViewRepository::viewedProductIds() to list dishes nobody
     * has looked at (see StatsController). An empty $excludeIds means every
     * active dish qualifies (Doctrine's NOT IN() rejects an empty set, so
     * that clause is skipped entirely rather than passed an empty array).
     *
     * @param int[] $excludeIds
     * @return Product[]
     */
    public function findActiveExcludingIds(Restaurant $restaurant, array $excludeIds): array
    {
        $qb = $this->createQueryBuilder('p')
            ->innerJoin('p.category', 'c')
            ->andWhere('c.restaurant = :restaurant')
            ->andWhere('p.active = true')
            ->andWhere('c.active = true')
            ->setParameter('restaurant', $restaurant)
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('p.position', 'ASC');

        if ($excludeIds !== []) {
            $qb->andWhere('p.id NOT IN (:excludeIds)')
                ->setParameter('excludeIds', $excludeIds);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Bulk-initializes the to-many collections the public menu render path
     * touches per product — tags, price variants, translations, and both
     * ingredient-link collections — so later per-product access (in
     * MenuController::renderMenu()'s price-conversion loop, and in the
     * layout templates) hits an already-loaded PersistentCollection instead
     * of firing Doctrine's default one-query-per-product-per-collection
     * lazy load. Each collection is fetch-joined in its own query (one per
     * collection, not combined) specifically to avoid the cartesian-product
     * row blowup a single query joining multiple to-many collections would
     * produce; relies on the entity identity map so these fetch-joined rows
     * land on the exact same Product instances the caller already holds.
     *
     * $cacheKeyPrefix is opt-in, same rule as findActiveForRestaurant()'s —
     * only the public menu passes one.
     *
     * @param Product[] $products
     */
    public function warmMenuCollections(array $products, ?string $cacheKeyPrefix = null): void
    {
        if ($products === []) {
            return;
        }

        foreach (['tags', 'priceVariants', 'translations', 'ingredientLinks', 'globalIngredientLinks'] as $field) {
            $query = $this->createQueryBuilder('p')
                ->select('p', 'rel')
                ->leftJoin("p.{$field}", 'rel')
                ->where('p IN (:products)')
                ->setParameter('products', $products)
                ->getQuery();

            if ($cacheKeyPrefix !== null) {
                $query->enableResultCache(self::CACHE_TTL, $cacheKeyPrefix . '_' . $field);
            }

            $query->getResult();
        }
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
