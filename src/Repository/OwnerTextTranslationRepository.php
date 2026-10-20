<?php

namespace App\Repository;

use App\Entity\OwnerTextTranslation;
use App\Entity\Restaurant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OwnerTextTranslation> */
class OwnerTextTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OwnerTextTranslation::class);
    }

    /**
     * Translations into $locale of the given source hashes, in one query.
     *
     * @param  string[] $hashes
     * @return array<string, string> source hash => translated text
     */
    public function findTranslatedByHash(Restaurant $restaurant, string $locale, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('t')
            ->select('t.sourceHash', 't.translatedText')
            ->where('t.restaurant = :restaurant')
            ->andWhere('t.locale = :locale')
            ->andWhere('t.sourceHash IN (:hashes)')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('locale', $locale)
            ->setParameter('hashes', array_values(array_unique($hashes)))
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'translatedText', 'sourceHash');
    }

    /**
     * Stores one translation unless the same (restaurant, locale, source
     * text) is already there — INSERT … ON CONFLICT DO NOTHING, so a row
     * written by a concurrent loading screen is skipped by the database
     * instead of raising a unique violation (which would close the
     * EntityManager for the rest of the request).
     *
     * @return bool true if a row was inserted, false if it already existed
     */
    public function insertIfMissing(Restaurant $restaurant, string $locale, string $sourceLocale, string $sourceText, string $translatedText, string $origin = OwnerTextTranslation::ORIGIN_AI): bool
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO owner_text_translation (restaurant_id, locale, source_hash, source_text, translated_text, origin, created_at)
             VALUES (:restaurant, :locale, :hash, :source, :translated, :origin, :createdAt)
             ON CONFLICT (restaurant_id, locale, source_hash) DO NOTHING',
            [
                'restaurant' => $restaurant->getId(),
                'locale'     => $locale,
                'hash'       => OwnerTextTranslation::hash($sourceLocale, $sourceText),
                'source'     => $sourceText,
                'translated' => $translatedText,
                'origin'     => $origin,
                'createdAt'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        ) > 0;
    }
}
