<?php

namespace App\Repository;

use App\Entity\Ingredient;
use App\Entity\Restaurant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

class IngredientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ingredient::class);
    }

    /**
     * Search for autocomplete: matches only within the given locale, so the
     * admin never sees ingredient names from a language other than the one
     * their Admin Panel is currently displayed in. Ranked by relevance — see
     * IngredientRelevanceRanking — not alphabetically.
     *
     * Uses a raw query (rather than DQL) so the ranking's ILIKE/similarity
     * matches can hit the pg_trgm GIN index on ingredient_translation.name.
     *
     * @return list<array{id: int, name: string}>
     */
    public function searchByLocale(Restaurant $restaurant, string $locale, string $query, int $limit = 20): array
    {
        $ranking = IngredientRelevanceRanking::build('it.name', $query);

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT i.id, it.name
             FROM ingredient i
             INNER JOIN ingredient_translation it ON it.ingredient_id = i.id
             WHERE i.restaurant_id = :restaurant
               AND it.locale = :locale
               AND {$ranking['where']}
             ORDER BY {$ranking['orderBy']}
             LIMIT :limit",
            [
                'restaurant' => $restaurant->getId(),
                'locale'     => $locale,
                ...$ranking['params'],
                'limit'      => $limit,
            ],
            [
                'restaurant' => ParameterType::INTEGER,
                'locale'     => ParameterType::STRING,
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
