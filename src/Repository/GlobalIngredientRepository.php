<?php

namespace App\Repository;

use App\Entity\GlobalIngredient;
use App\Entity\GlobalIngredientTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

class GlobalIngredientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GlobalIngredient::class);
    }

    /**
     * Autocomplete search across several locales at once — the Admin Panel
     * language, the menu language, en and es — so an owner whose panel is in
     * French still finds "Tomate" or "Tomato". AI-translated names are
     * included on purpose: here the owner picks explicitly, unlike
     * findUnambiguousByName(). Each ingredient appears once, ranked by its
     * best-matching name (IngredientRelevanceRanking), and is shown in
     * $displayLocale, or in English when it has no name there.
     *
     * @param  list<string> $locales
     * @return list<array{id: int, name: string}>
     */
    public function searchByLocales(array $locales, string $displayLocale, string $query, int $limit = 20): array
    {
        $ranking = IngredientRelevanceRanking::build('git.name', $query);

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            "WITH matches AS (
                 SELECT git.global_ingredient_id AS id,
                        git.name AS matched,
                        ROW_NUMBER() OVER (PARTITION BY git.global_ingredient_id ORDER BY {$ranking['orderBy']}) AS best,
                        ROW_NUMBER() OVER (ORDER BY {$ranking['orderBy']}) AS position
                 FROM global_ingredient_translation git
                 WHERE git.locale IN (:locales)
                   AND {$ranking['where']}
             )
             SELECT m.id, COALESCE(shown.name, en.name, m.matched) AS name
             FROM matches m
             LEFT JOIN global_ingredient_translation shown
                 ON shown.global_ingredient_id = m.id AND shown.locale = :display
             LEFT JOIN global_ingredient_translation en
                 ON en.global_ingredient_id = m.id AND en.locale = 'en'
             WHERE m.best = 1
             ORDER BY m.position
             LIMIT :limit",
            [
                'locales' => array_values(array_unique($locales)),
                'display' => $displayLocale,
                ...$ranking['params'],
                'limit'   => $limit,
            ],
            [
                'locales' => ArrayParameterType::STRING,
                'display' => ParameterType::STRING,
                ...$ranking['types'],
                'limit'   => ParameterType::INTEGER,
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
     * The (ingredient, locale) pairs still missing a name in any of
     * $locales, with the English name to translate from — what
     * GlobalIngredientLibraryTranslation sends next. Ingredients some menu
     * already uses come first, then by id, so every run resumes in the same
     * order. Ingredients without an English name are left out.
     *
     * @param  list<string>      $locales  validated ISO 639-1 codes
     * @param  list<string>|null $codes    only these ingredients (null = all)
     * @return list<array{id: int, en: string, locale: string}>
     */
    public function findMissingTranslations(array $locales, ?int $limit = null, ?array $codes = null): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT gi.id, en.name AS en, wanted.locale
             FROM global_ingredient gi
             INNER JOIN global_ingredient_translation en
                 ON en.global_ingredient_id = gi.id AND en.locale = \'en\'
             CROSS JOIN (SELECT UNNEST(CAST(:locales AS TEXT[])) AS locale) wanted
             LEFT JOIN global_ingredient_translation existing
                 ON existing.global_ingredient_id = gi.id AND existing.locale = wanted.locale
             WHERE existing.id IS NULL
               AND (CAST(:allCodes AS BOOLEAN) OR gi.code IN (:codes))
             ORDER BY EXISTS (SELECT 1 FROM product_global_ingredient pgi WHERE pgi.global_ingredient_id = gi.id) DESC,
                      gi.id, wanted.locale
             LIMIT :limit',
            [
                'locales'  => '{' . implode(',', $locales) . '}',
                'allCodes' => $codes === null ? 'true' : 'false',
                'codes'    => $codes ?? [''],
                'limit'    => $limit ?? PHP_INT_MAX,
            ],
            [
                'locales'  => ParameterType::STRING,
                'allCodes' => ParameterType::STRING,
                'codes'    => ArrayParameterType::STRING,
                'limit'    => ParameterType::INTEGER,
            ]
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row) => ['id' => (int) $row['id'], 'en' => $row['en'], 'locale' => $row['locale']],
            $rows
        );
    }

    /**
     * How many names each of $locales is still missing (see
     * findMissingTranslations()).
     *
     * @param  list<string> $locales
     * @return array<string, int>
     */
    public function countMissingTranslations(array $locales): array
    {
        $counts = array_fill_keys($locales, 0);
        foreach ($this->getEntityManager()->getConnection()->executeQuery(
            'SELECT wanted.locale, COUNT(*) AS missing
             FROM global_ingredient gi
             INNER JOIN global_ingredient_translation en
                 ON en.global_ingredient_id = gi.id AND en.locale = \'en\'
             CROSS JOIN (SELECT UNNEST(CAST(:locales AS TEXT[])) AS locale) wanted
             LEFT JOIN global_ingredient_translation existing
                 ON existing.global_ingredient_id = gi.id AND existing.locale = wanted.locale
             WHERE existing.id IS NULL
             GROUP BY wanted.locale',
            ['locales' => '{' . implode(',', $locales) . '}'],
        )->fetchAllAssociative() as $row) {
            $counts[$row['locale']] = (int) $row['missing'];
        }

        return $counts;
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
     *
     * Machine-translated names (source 'ai') never take part: an unverified
     * translation must not decide which allergens a dish inherits. They're
     * only ever offered in autocomplete, where the owner picks explicitly.
     */
    public function findUnambiguousByName(string $name, string $locale): ?GlobalIngredient
    {
        $matches = $this->createQueryBuilder('gi')
            ->innerJoin('gi.translations', 't')
            ->andWhere('t.locale = :locale')
            ->andWhere('LOWER(t.name) = LOWER(:name)')
            ->andWhere('t.source <> :ai')
            ->setParameter('locale', $locale)
            ->setParameter('name', trim($name))
            ->setParameter('ai', GlobalIngredientTranslation::SOURCE_AI)
            ->orderBy('gi.id', 'ASC')
            ->setMaxResults(2)
            ->getQuery()
            ->getResult();

        return count($matches) === 1 ? $matches[0] : null;
    }
}
