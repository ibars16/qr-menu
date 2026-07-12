<?php

namespace App\Repository;

use App\Entity\GlobalIngredient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

class GlobalIngredientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GlobalIngredient::class);
    }

    /**
     * Search for autocomplete: matches only within the given locale, same
     * rule as IngredientRepository::searchByLocale — no restaurant scoping,
     * since the library is shared by every restaurant. Ranked by relevance —
     * see IngredientRelevanceRanking — not alphabetically.
     *
     * @return list<array{id: int, name: string}>
     */
    public function searchByLocale(string $locale, string $query, int $limit = 20): array
    {
        $ranking = IngredientRelevanceRanking::build('git.name', $query);

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "SELECT gi.id, git.name
             FROM global_ingredient gi
             INNER JOIN global_ingredient_translation git ON git.global_ingredient_id = gi.id
             WHERE git.locale = :locale
               AND {$ranking['where']}
             ORDER BY {$ranking['orderBy']}
             LIMIT :limit",
            [
                'locale' => $locale,
                ...$ranking['params'],
                'limit'  => $limit,
            ],
            [
                'locale' => ParameterType::STRING,
                ...$ranking['types'],
                'limit'  => ParameterType::INTEGER,
            ]
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row) => ['id' => (int) $row['id'], 'name' => $row['name']],
            $rows
        );
    }

    /**
     * IDs of every ingredient that has no translation row for $locale yet.
     * Used by GlobalIngredientTranslationBackfiller to find what the AI
     * backfill still needs to cover — a plain LEFT JOIN / IS NULL scan is
     * far cheaper here than hydrating entities just to call getTranslation().
     *
     * @return list<int>
     */
    public function findIdsMissingTranslation(string $locale): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT gi.id
             FROM global_ingredient gi
             LEFT JOIN global_ingredient_translation git
                 ON git.global_ingredient_id = gi.id AND git.locale = :locale
             WHERE git.id IS NULL
             ORDER BY gi.id',
            ['locale' => $locale],
            ['locale' => ParameterType::STRING]
        )->fetchFirstColumn();

        return array_map('intval', $rows);
    }

    /**
     * Case-insensitive exact-name lookup across ALL locales. Used server-side
     * at product-save time: if a restaurant admin types a "new" ingredient
     * that already exists in the global library (just not yet surfaced by
     * their search), associate that instead of creating a redundant
     * restaurant-private duplicate.
     */
    public function findExistingByNameAnyLocale(string $name): ?GlobalIngredient
    {
        return $this->createQueryBuilder('gi')
            ->innerJoin('gi.translations', 't')
            ->andWhere('LOWER(t.name) = LOWER(:name)')
            ->setParameter('name', trim($name))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
