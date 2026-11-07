<?php

namespace App\Repository;

use App\Entity\CategoryTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CategoryTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategoryTranslation::class);
    }

    public function save(CategoryTranslation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(CategoryTranslation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Stores an AI translation of a category name into $locale unless it
     * already has one (ON CONFLICT DO NOTHING on unique_category_locale) —
     * same reasoning as ProductTranslationRepository::insertAiIfMissing().
     */
    public function insertAiIfMissing(int $categoryId, string $locale, string $name): bool
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            "INSERT INTO category_translation (category_id, locale, name, source)
             VALUES (:category, :locale, :name, :source)
             ON CONFLICT (category_id, locale) DO NOTHING",
            [
                'category' => $categoryId,
                'locale'   => $locale,
                'name'     => $name,
                'source'   => CategoryTranslation::SOURCE_AI,
            ],
        ) > 0;
    }
}
