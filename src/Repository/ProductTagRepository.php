<?php

namespace App\Repository;

use App\Entity\ProductTag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProductTagRepository extends ServiceEntityRepository
{
    /** Same safety-net TTL and reasoning as ProductRepository::CACHE_TTL. */
    private const CACHE_TTL = 21600;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductTag::class);
    }

    /**
     * Bulk-initializes $tags' translations collections in one query, so
     * later per-tag getTranslation() calls (see TagTranslationService)
     * don't each trigger their own lazy load — same fetch-join-per-request
     * idiom as ProductRepository::warmMenuCollections().
     *
     * $cacheKeyPrefix is opt-in — only ever passed from the public menu
     * path (via TagTranslationService), so admin screens keep getting live
     * data by default.
     *
     * @param ProductTag[] $tags
     */
    public function warmTranslations(array $tags, ?string $cacheKeyPrefix = null): void
    {
        if ($tags === []) {
            return;
        }

        $query = $this->createQueryBuilder('t')
            ->select('t', 'tr')
            ->leftJoin('t.translations', 'tr')
            ->where('t IN (:tags)')
            ->setParameter('tags', $tags)
            ->getQuery();

        if ($cacheKeyPrefix !== null) {
            $query->enableResultCache(self::CACHE_TTL, $cacheKeyPrefix . '_tagtranslations');
        }

        $query->getResult();
    }
}
