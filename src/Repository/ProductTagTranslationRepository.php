<?php

namespace App\Repository;

use App\Entity\ProductTagTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProductTagTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductTagTranslation::class);
    }

    /**
     * Stores an AI translation of a tag name into $locale unless it already
     * has one (ON CONFLICT DO NOTHING on unique_tag_locale) — same reasoning
     * as ProductTranslationRepository::insertAiIfMissing().
     */
    public function insertAiIfMissing(int $tagId, string $locale, string $name): bool
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            "INSERT INTO product_tag_translation (tag_id, locale, name, source)
             VALUES (:tag, :locale, :name, :source)
             ON CONFLICT (tag_id, locale) DO NOTHING",
            [
                'tag'    => $tagId,
                'locale' => $locale,
                'name'   => $name,
                'source' => ProductTagTranslation::SOURCE_AI,
            ],
        ) > 0;
    }
}
