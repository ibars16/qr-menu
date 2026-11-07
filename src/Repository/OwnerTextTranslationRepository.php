<?php

namespace App\Repository;

use App\Entity\OwnerTextTranslation;
use App\Entity\Restaurant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
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
     * The owner's own text (origin 'human', in any language) each of the
     * given source hashes is a translation of — the latest one when there
     * are several. A text with none is not known to be a translation.
     *
     * @param  string[] $hashes
     * @return array<string, array{0: string, 1: string}> source hash => [locale, owner's text]
     */
    public function findOwnerOriginals(Restaurant $restaurant, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('t')
            ->select('t.sourceHash', 't.locale', 't.translatedText')
            ->where('t.restaurant = :restaurant')
            ->andWhere('t.origin = :human')
            ->andWhere('t.sourceHash IN (:hashes)')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('human', OwnerTextTranslation::ORIGIN_HUMAN)
            ->setParameter('hashes', array_values(array_unique($hashes)))
            ->orderBy('t.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $originals = [];
        foreach ($rows as $row) {
            $originals[$row['sourceHash']] = [$row['locale'], $row['translatedText']];
        }

        return $originals;
    }

    /**
     * Stores one translation written by a main-language change
     * (MenuLanguageChanger), replacing a row already there for the same
     * (restaurant, locale, source text) — an older translation of a text
     * the AI happened to write the same way again — except that an AI one
     * ('ai') never replaces the owner's ('human'). INSERT … ON CONFLICT DO
     * UPDATE … WHERE, so no unique violation either.
     */
    public function saveFromConversion(Restaurant $restaurant, string $locale, string $sourceLocale, string $sourceText, string $translatedText, string $origin): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO owner_text_translation (restaurant_id, locale, source_hash, source_text, translated_text, origin, created_at)
             VALUES (:restaurant, :locale, :hash, :source, :translated, :origin, :createdAt)
             ON CONFLICT (restaurant_id, locale, source_hash) DO UPDATE
                SET source_text = EXCLUDED.source_text, translated_text = EXCLUDED.translated_text,
                    origin = EXCLUDED.origin, created_at = EXCLUDED.created_at
              WHERE EXCLUDED.origin = :human OR owner_text_translation.origin <> :human',
            [
                'restaurant' => $restaurant->getId(),
                'locale'     => $locale,
                'hash'       => OwnerTextTranslation::hash($sourceLocale, $sourceText),
                'source'     => $sourceText,
                'translated' => $translatedText,
                'origin'     => $origin,
                'createdAt'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'human'      => OwnerTextTranslation::ORIGIN_HUMAN,
            ],
        );
    }

    /**
     * Deletes the restaurant's rows whose source hash isn't one of
     * $liveHashes (see OwnerTextTranslationPruner) — every row when it's
     * empty. One statement; the restaurant's rows only.
     *
     * @param  string[] $liveHashes
     * @return int rows deleted
     */
    public function deleteUnreferenced(Restaurant $restaurant, array $liveHashes): int
    {
        $connection = $this->getEntityManager()->getConnection();
        if ($liveHashes === []) {
            return (int) $connection->executeStatement('DELETE FROM owner_text_translation WHERE restaurant_id = ?', [$restaurant->getId()]);
        }

        return (int) $connection->executeStatement(
            'DELETE FROM owner_text_translation WHERE restaurant_id = ? AND source_hash NOT IN (?)',
            [$restaurant->getId(), array_values(array_unique($liveHashes))],
            [ParameterType::INTEGER, ArrayParameterType::STRING],
        );
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
