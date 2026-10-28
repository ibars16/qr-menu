<?php

namespace App\Service;

use App\Entity\GlobalIngredientTranslation;
use App\Repository\GlobalIngredientRepository;
use App\Service\AI\AIFailureReason;
use App\Service\AI\AIProviderException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Translates the Global Ingredient Library's English names into the
 * locales it's missing, in daily batches sized for Groq's free tier — see
 * app:global-ingredients:translate.
 *
 * Goes through RouterLabelTranslator in its groq-llama-only mode, so every
 * name gets both passes (translate, then check) and the run stops at the
 * daily quota instead of moving to a paid provider.
 *
 * Re-runnable: it only ever asks for (ingredient, locale) pairs with no
 * name yet, in a stable order, so a run cut short by the quota resumes
 * where it stopped. Every name it writes is source 'ai' — searchable in
 * the admin autocomplete, never used for automatic linking (see
 * GlobalIngredientRepository::findUnambiguousByName()) — and the INSERT
 * skips any pair that gained a name meanwhile, so a dataset or hand-written
 * name is never overwritten.
 *
 * After each batch it bumps the content version of every restaurant with a
 * dish using one of the ingredients just named (as IngredientAllergenBackfill
 * does), since their menus now show that name.
 */
final class GlobalIngredientLibraryTranslation
{
    /** Errors other than the quota (bad JSON, a 5xx): skip the batch, but stop if they keep coming. */
    private const MAX_CONSECUTIVE_FAILED_BATCHES = 3;
    // Two passes per batch, and Groq reserves max_tokens against the
    // per-minute limit up front: 2,048 leaves room for a 40-name answer
    // plus gpt-oss's (low-effort) reasoning.
    private const MAX_TOKENS = 2048;

    public function __construct(
        private readonly RouterLabelTranslator      $labelTranslator,
        private readonly GlobalIngredientRepository $repository,
        private readonly Connection                 $connection,
    ) {}

    /**
     * @param  list<string>                       $locales
     * @param  list<string>|null                  $codes    only these ingredients (null = all)
     * @param  callable(string, int, int):void|null $onBatch invoked after each batch with (locale, written, failed)
     * @return array{written: int, failed: int, stopped: ?string, tokens: int, samples: list<array{locale: string, en: string, name: string}>}
     *               stopped: null (nothing left), 'quota' or 'errors'
     */
    public function run(array $locales, int $batchSize, ?int $limit = null, ?array $codes = null, bool $dryRun = false, ?callable $onBatch = null): array
    {
        $written = 0;
        $failed = 0;
        $stopped = null;
        $consecutiveFailures = 0;
        $samples = [];
        $tokensBefore = $this->labelTranslator->getFreeProviderTokens();

        foreach ($this->batches($this->repository->findMissingTranslations($locales, $limit, $codes), $batchSize) as [$locale, $englishById]) {
            try {
                $translated = $this->translate($englishById, $locale);
            } catch (AIProviderException $e) {
                if ($e->reason === AIFailureReason::RATE_LIMITED) {
                    $stopped = 'quota';
                    break;
                }
                $failed += count($englishById);
                $onBatch?->__invoke($locale, 0, count($englishById));
                if (++$consecutiveFailures >= self::MAX_CONSECUTIVE_FAILED_BATCHES) {
                    $stopped = 'errors';
                    break;
                }
                continue;
            }
            $consecutiveFailures = 0;

            $batchWritten = 0;
            $batchFailed = 0;
            $namedIds = [];
            foreach ($englishById as $id => $english) {
                $name = $this->clean($translated[$id] ?? null);
                if ($name === null) {
                    // Left for the next run, like a failed batch.
                    $batchFailed++;
                    continue;
                }
                if (count($samples) < 15) {
                    $samples[] = ['locale' => $locale, 'en' => $english, 'name' => $name];
                }
                if ($dryRun) {
                    $batchWritten++;
                } elseif ($this->insert($id, $locale, $name)) {
                    $batchWritten++;
                    $namedIds[] = $id;
                }
            }
            $this->bumpMenusUsing($namedIds);
            $written += $batchWritten;
            $failed += $batchFailed;
            $onBatch?->__invoke($locale, $batchWritten, $batchFailed);
        }

        return [
            'written' => $written,
            'failed'  => $failed,
            'stopped' => $stopped,
            'tokens'  => $this->labelTranslator->getFreeProviderTokens() - $tokensBefore,
            'samples' => $samples,
        ];
    }

    /**
     * Groups the missing pairs into per-locale batches, taken from each
     * locale in turn, so every language moves forward each day rather
     * than one finishing before the next starts.
     *
     * @param  list<array{id: int, en: string, locale: string}> $pairs
     * @return list<array{string, array<int, string>}> [locale, id → English name]
     */
    private function batches(array $pairs, int $batchSize): array
    {
        $byLocale = [];
        foreach ($pairs as $pair) {
            $byLocale[$pair['locale']][$pair['id']] = $pair['en'];
        }

        $chunks = array_map(static fn (array $names) => array_chunk($names, $batchSize, true), $byLocale);
        $batches = [];
        for ($i = 0; $chunks !== []; $i++) {
            foreach ($chunks as $locale => $localeChunks) {
                if (!isset($localeChunks[$i])) {
                    unset($chunks[$locale]);
                    continue;
                }
                $batches[] = [$locale, $localeChunks[$i]];
            }
        }

        return $batches;
    }

    /**
     * @param  array<int, string> $englishById
     * @return array<int, string>
     * @throws AIProviderException
     */
    private function translate(array $englishById, string $locale): array
    {
        $translatePrompt = 'You are a culinary translator for a library of food ingredients that restaurant owners pick from when describing their dishes. '
            . "Translate the English \"name\" of each ingredient below into the language with ISO 639-1 code \"{$locale}\". "
            . 'Use the term a cook or a restaurant menu in that language would normally use for that ingredient. '
            . 'Keep every detail of the original (cut, preparation, variety, fat percentage…) and add nothing: no explanations, no alternatives, no brand names. '
            . 'Only keep a word in English when that language really uses it as-is (e.g. "tofu"). '
            . 'Start each name with a capital letter, the rest as the language writes it. '
            . 'Return ONLY a valid JSON object mapping each "id" to its translated name (a plain string, not an object) — '
            . 'no other text, no markdown code fences, no explanations.';

        $verifyPrompt = 'You are a translation quality checker for a library of food ingredients used on restaurant menus. '
            . "You'll see, for each ingredient, its English name and a candidate translation into the language with ISO 639-1 code \"{$locale}\". "
            . 'Check the candidate carefully. Is it actually in that language, not left in English (unless that language really uses the English word)? '
            . 'Is it the same ingredient — not a different food, a broader or narrower one, or one that lost or gained a detail such as the cut, preparation, variety or fat percentage? '
            . 'Is it made of real, correctly-spelled words, the term a cook in that language would use — not a fabricated or word-for-word calque? '
            . 'If a candidate is already correct, return it completely unchanged. If it has a problem, replace it with a corrected translation. '
            . 'Return ONLY a valid JSON object mapping each "id" to its translated name (a plain string, not an object), including every id — no other text, no markdown code fences, no explanations.';

        return $this->labelTranslator->translate(
            $englishById,
            $translatePrompt,
            $verifyPrompt,
            'Ingredients',
            self::MAX_TOKENS,
            array_fill_keys(array_keys($englishById), 'en'),
            freeProviderOnly: true,
        );
    }

    private function clean(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' || mb_strlen($name) > 255 || preg_match('/[\r\n]/', $name) ? null : $name;
    }

    /** @param list<int> $globalIngredientIds */
    private function bumpMenusUsing(array $globalIngredientIds): void
    {
        if ($globalIngredientIds === []) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE restaurant SET menu_content_version = menu_content_version + 1
             WHERE id IN (
                 SELECT c.restaurant_id
                 FROM product_global_ingredient pgi
                 INNER JOIN product p ON p.id = pgi.product_id
                 INNER JOIN category c ON c.id = p.category_id
                 WHERE pgi.global_ingredient_id IN (:ids)
             )',
            ['ids' => $globalIngredientIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    /** False when the pair already has a name — which always wins over this one. */
    private function insert(int $ingredientId, string $locale, string $name): bool
    {
        return $this->connection->executeStatement(
            'INSERT INTO global_ingredient_translation (global_ingredient_id, locale, name, source)
             VALUES (:id, :locale, :name, :source)
             ON CONFLICT (global_ingredient_id, locale) DO NOTHING',
            ['id' => $ingredientId, 'locale' => $locale, 'name' => $name, 'source' => GlobalIngredientTranslation::SOURCE_AI],
        ) === 1;
    }
}
