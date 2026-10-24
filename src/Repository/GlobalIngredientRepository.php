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
     * The one library ingredient a typed (product save) or imported name
     * links to with nobody picking it — the ingredient's allergens come with
     * it, so this is deliberately strict: a case-insensitive exact match in
     * $locale (the restaurant's menu language) only, and none at all when
     * two different ingredients share that name there (e.g. "Egg" vs
     * "Chicken egg" both translating to one word, only one carrying the egg
     * allergen). A name in another language — "salsa" is Spanish for sauce
     * but English for the dip — never matches. Null means "create a private
     * ingredient instead".
     */
    public function findUnambiguousByName(string $name, string $locale): ?GlobalIngredient
    {
        $matches = $this->createQueryBuilder('gi')
            ->innerJoin('gi.translations', 't')
            ->andWhere('t.locale = :locale')
            ->andWhere('LOWER(t.name) = LOWER(:name)')
            ->setParameter('locale', $locale)
            ->setParameter('name', trim($name))
            ->orderBy('gi.id', 'ASC')
            ->setMaxResults(2)
            ->getQuery()
            ->getResult();

        return count($matches) === 1 ? $matches[0] : null;
    }
}
