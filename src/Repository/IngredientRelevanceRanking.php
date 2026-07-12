<?php

namespace App\Repository;

use Doctrine\DBAL\ParameterType;

/**
 * Builds the WHERE/ORDER BY fragments shared by IngredientRepository and
 * GlobalIngredientRepository's autocomplete search, so both rank results
 * the same way instead of two hand-tuned copies drifting apart.
 *
 * Ranking tiers (lower is better), matching how modern autocomplete
 * (Google, GitHub, VS Code, …) surfaces the most-likely-intended result
 * first:
 *   1. Exact match.
 *   2. Name starts with the search term.
 *   3. A word within the name starts with the search term.
 *   4. Name contains the search term anywhere.
 *   5. No substring match — fuzzy pg_trgm similarity only.
 * Within a tier, results are ordered by similarity (best first), then by
 * shorter names first, so a generic "Tomato" outranks a more specific
 * "Concentrated tomato paste".
 */
final class IngredientRelevanceRanking
{
    /**
     * @param  string $nameColumn   trusted SQL identifier (e.g. "git.name") —
     *                               MUST be a hardcoded literal, never derived
     *                               from user input; it is concatenated
     *                               directly into the query, unparameterized.
     * @param  string $paramPrefix  disambiguates bound-parameter names when a
     *                               query joins in more than one ranked search
     * @return array{where: string, orderBy: string, params: array<string,string>, types: array<string,ParameterType>}
     */
    public static function build(string $nameColumn, string $query, string $paramPrefix = ''): array
    {
        $raw     = trim($query);
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $raw);
        $p       = $paramPrefix;

        // Matches only within the current word (Postgres advanced-regex "\m"
        // = start-of-word), so "tomato" ranks "Cherry tomato" here (tier 3)
        // rather than tier 4, but doesn't falsely promote "Automation".
        $wordStartPattern = '\m' . preg_quote($raw);

        $params = [
            "{$p}exact"     => mb_strtolower($raw),
            "{$p}prefix"    => $escaped . '%',
            "{$p}contains"  => '%' . $escaped . '%',
            "{$p}wordstart" => $wordStartPattern,
            // Unescaped on purpose: pg_trgm's "%" similarity operator and the
            // similarity() function compare raw text, not an ILIKE pattern.
            "{$p}fuzzy"     => $raw,
        ];

        $types = array_fill_keys(array_keys($params), ParameterType::STRING);

        // The "%" operator (not the ILIKE pattern) is what lets Postgres use
        // the gin_trgm_ops index for the fuzzy tier; ILIKE '%...%' uses the
        // same index for the substring tiers. Together this keeps the whole
        // ranked search index-accelerated instead of a sequential scan.
        $where = "({$nameColumn} ILIKE :{$p}contains OR {$nameColumn} % :{$p}fuzzy)";

        $rank = "CASE
            WHEN LOWER({$nameColumn}) = :{$p}exact THEN 1
            WHEN {$nameColumn} ILIKE :{$p}prefix THEN 2
            WHEN {$nameColumn} ~* :{$p}wordstart THEN 3
            WHEN {$nameColumn} ILIKE :{$p}contains THEN 4
            ELSE 5
        END";

        $orderBy = "{$rank} ASC, similarity({$nameColumn}, :{$p}fuzzy) DESC, LENGTH({$nameColumn}) ASC, {$nameColumn} ASC";

        return ['where' => $where, 'orderBy' => $orderBy, 'params' => $params, 'types' => $types];
    }
}
