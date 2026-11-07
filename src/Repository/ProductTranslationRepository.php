<?php

namespace App\Repository;

use App\Entity\ProductTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProductTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductTranslation::class);
    }

    public function save(ProductTranslation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ProductTranslation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Stores an AI translation of a dish into $locale unless the dish already
     * has one in it (ON CONFLICT DO NOTHING on unique_product_locale): a row
     * another visit stored meanwhile — the owner's or the AI's — is kept as
     * it is, and nothing goes through the EntityManager, so a duplicate can
     * never close it for the rest of the request. True when it stored one.
     */
    public function insertAiIfMissing(int $productId, string $locale, string $name, ?string $description): bool
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            "INSERT INTO product_translation (product_id, locale, name, description, source)
             VALUES (:product, :locale, :name, :description, :source)
             ON CONFLICT (product_id, locale) DO NOTHING",
            [
                'product'     => $productId,
                'locale'      => $locale,
                'name'        => $name,
                'description' => $description,
                'source'      => ProductTranslation::SOURCE_AI,
            ],
        ) > 0;
    }
}
