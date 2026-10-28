<?php

namespace App\Repository;

use App\Entity\Ingredient;
use App\Entity\Restaurant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

class IngredientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ingredient::class);
    }

    /**
     * Search for autocomplete across $locales — the Admin Panel language and
     * the menu language, where a typed or imported name is written. Each
     * ingredient appears once, ranked by its best-matching name (see
     * IngredientRelevanceRanking), and is shown in $displayLocale, or by the
     * name that matched when it has none there.
     *
     * Uses a raw query (rather than DQL) so the ranking's ILIKE/similarity
     * matches can hit the pg_trgm GIN index on ingredient_translation.name.
     *
     * @param  list<string> $locales
     * @return list<array{id: int, name: string}>
     */
    public function searchByLocales(Restaurant $restaurant, array $locales, string $displayLocale, string $query, int $limit = 20): array
    {
        $ranking = IngredientRelevanceRanking::build('it.name', $query);

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "WITH matches AS (
                 SELECT i.id,
                        it.name AS matched,
                        ROW_NUMBER() OVER (PARTITION BY i.id ORDER BY {$ranking['orderBy']}) AS best,
                        ROW_NUMBER() OVER (ORDER BY {$ranking['orderBy']}) AS position
                 FROM ingredient i
                 INNER JOIN ingredient_translation it ON it.ingredient_id = i.id
                 WHERE i.restaurant_id = :restaurant
                   AND it.locale IN (:locales)
                   AND {$ranking['where']}
             )
             SELECT m.id, COALESCE(shown.name, m.matched) AS name
             FROM matches m
             LEFT JOIN ingredient_translation shown
                 ON shown.ingredient_id = m.id AND shown.locale = :display
             WHERE m.best = 1
             ORDER BY m.position
             LIMIT :limit",
            [
                'restaurant' => $restaurant->getId(),
                'locales'    => array_values(array_unique($locales)),
                'display'    => $displayLocale,
                ...$ranking['params'],
                'limit'      => $limit,
            ],
            [
                'restaurant' => ParameterType::INTEGER,
                'locales'    => ArrayParameterType::STRING,
                'display'    => ParameterType::STRING,
                ...$ranking['types'],
                'limit'      => ParameterType::INTEGER,
            ]
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row) => ['id' => (int) $row['id'], 'name' => $row['name']],
            $rows
        );
    }

    /**
     * Case-insensitive exact-name lookup across ALL of the restaurant's
     * ingredient translations, regardless of locale. Used only server-side,
     * at save time, to avoid creating a duplicate Ingredient concept when the
     * same word already exists under a different Admin Panel language —
     * never surfaced in the (locale-scoped) autocomplete results themselves.
     */
    public function findExistingByNameAnyLocale(Restaurant $restaurant, string $name): ?Ingredient
    {
        return $this->createQueryBuilder('i')
            ->innerJoin('i.translations', 't')
            ->andWhere('i.restaurant = :restaurant')
            ->andWhere('LOWER(t.name) = LOWER(:name)')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('name', trim($name))
            ->orderBy('i.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(Ingredient $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Ingredient $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
