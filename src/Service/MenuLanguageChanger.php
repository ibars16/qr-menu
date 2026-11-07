<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Ingredient;
use App\Entity\IngredientTranslation;
use App\Entity\MenuImportBatch;
use App\Entity\OwnerTextTranslation;
use App\Entity\Product;
use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Enum\MenuImportBatchStatus;
use App\Repository\OwnerTextTranslationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Changes the menu's main language (Restaurant::$defaultLanguage). A text the
 * owner wrote (source 'human'), in any language, is never changed by it —
 * only rows the AI made are (re)written, or rows are relabelled.
 *
 * start() asks the AI once which language the owner's texts are in:
 *  a) already the new one (only the label was wrong): the owner's rows are
 *     relabelled to the new locale at once, nothing translated;
 *  b) another one, a mix, or the AI can't tell (or the owner wrote none in
 *     the main language): languageChangeTo is set and the "Convirtiendo tu
 *     carta…" screen calls step() until it's done — each call ONE batch (one
 *     AI call at most), so a closed window or an AI limit just leaves the
 *     change half done, resumed by languageChangeTo.
 *
 * In b every dish (any state — imported unpublished ones too), category,
 * tag and private ingredient gets its text in the new language, translated
 * from the owner's text (TranslationSource::pick()) and stored as an AI
 * translation ('ai') — the owner's until they edit it by hand. An AI row
 * already in the new language is translated again; one the owner wrote is
 * kept. The exception: old data may hold another language under the new
 * language's label (a menu written in Spanish while the label said
 * English), so the owner's rows there are first checked by the AI
 * (detectEach(), a batch per step) — one in another language is replaced
 * by the translation (then 'ai'); one the AI can't tell ("Pizza margarita")
 * stays. The answers, and which rows this conversion already wrote, are kept
 * in the app cache meanwhile (lost = done again, nothing else). The owner's
 * rows in the old language stay theirs. The free texts (tagline, set-menu
 * descriptions and section labels, price labels) are replaced by their
 * translation from the owner's original, which is kept (OwnerTextTranslation,
 * origin 'human') — a round trip brings it back unchanged.
 */
final class MenuLanguageChanger
{
    public const DONE           = 'done';
    public const CONVERTING     = 'converting';
    public const BLOCKED_IMPORT = 'blocked_import';

    /** Dishes per AI call (name + description each). */
    private const PRODUCT_BATCH = 25;
    /** Names per AI call (categories, tags, ingredients). */
    private const NAME_BATCH = 60;
    /** Texts per language check (detectEach()). */
    private const CHECK_BATCH = 30;
    /** Owner names sent to the language detection. */
    private const SAMPLE_SIZE = 20;

    /**
     * During step(): "p:12" / "c:3" / "t:7" => its new-language row stays
     * (checked to be in the new language, or written by this conversion);
     * false: replace it. Not there: an AI row is replaced, any other stays.
     *
     * @var array<string, bool>
     */
    private array $checked = [];

    public function __construct(
        private readonly EntityManagerInterface         $em,
        private readonly MenuLanguageDetectorInterface  $detector,
        private readonly ProductTranslatorInterface     $productTranslator,
        private readonly MenuTextTranslator             $textTranslator,
        private readonly TagTranslatorInterface         $tagTranslator,
        private readonly OwnerTextTranslationRepository $ownerTexts,
        private readonly LoggerInterface                $logger,
        private readonly CacheItemPoolInterface         $cache,
        private readonly OwnerTextTranslationPruner     $ownerTextPruner,
    ) {}

    /** An import still being read (uploaded / processing, under a day old): its dishes would come in the old language. */
    public function hasImportInProgress(Restaurant $restaurant): bool
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(MenuImportBatch::class, 'b')
            ->where('b.restaurant = :restaurant')
            ->andWhere('b.status IN (:statuses)')
            ->andWhere('b.createdAt > :since')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('statuses', [MenuImportBatchStatus::UPLOADED, MenuImportBatchStatus::PROCESSING])
            ->setParameter('since', new \DateTimeImmutable('-1 day'))
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * @return self::DONE|self::CONVERTING|self::BLOCKED_IMPORT
     *         DONE: changed already (case a, or nothing to convert);
     *         CONVERTING: languageChangeTo set, step() does the rest.
     *         Flushes.
     */
    public function start(Restaurant $restaurant, string $to): string
    {
        $from = $restaurant->getDefaultLanguage();
        if ($restaurant->getLanguageChangeTo() !== null) {
            return self::CONVERTING;
        }
        if ($to === $from) {
            return self::DONE;
        }
        if ($this->hasImportInProgress($restaurant)) {
            return self::BLOCKED_IMPORT;
        }

        $sample = $this->sample($restaurant, $from);
        if ($sample === []) {
            if (!$this->hasContent($restaurant)) {
                $this->finish($restaurant, $to);

                return self::DONE;
            }
            // Nothing the owner wrote in the main language (it's all AI, from
            // an earlier change): nothing to relabel — converted.
            $this->cache->deleteItem($this->checkKey($restaurant, $to));
            $restaurant->setLanguageChangeTo($to);
            $this->em->flush();

            return self::CONVERTING;
        }

        $detected = $this->detector->detect($sample);
        $this->logger->info('Menu language change: language of the owner\'s texts.', [
            'restaurantId' => $restaurant->getId(),
            'from'         => $from,
            'to'           => $to,
            'sampleSize'   => count($sample),
            'detected'     => $detected ?? 'unknown',
        ]);
        $this->cache->deleteItem($this->checkKey($restaurant, $to));

        if ($detected === $to) {
            $this->relabel($restaurant, $from, $to);
            $this->finish($restaurant, $to);

            return self::DONE;
        }

        $restaurant->setLanguageChangeTo($to);
        $this->em->flush();

        return self::CONVERTING;
    }

    /**
     * One batch of the conversion. Flushes.
     *
     * @return array{done: bool, remaining: int, error: bool}
     *         error: the AI gave nothing usable this time (retry later)
     */
    public function step(Restaurant $restaurant): array
    {
        $to = $restaurant->getLanguageChangeTo();
        if ($to === null) {
            return ['done' => true, 'remaining' => 0, 'error' => false];
        }
        $from = $restaurant->getDefaultLanguage();

        $products    = $this->products($restaurant);
        $categories  = $this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant], ['id' => 'ASC']);
        $tags        = $this->em->getRepository(ProductTag::class)->findBy(['restaurant' => $restaurant], ['id' => 'ASC']);
        $ingredients = $this->em->getRepository(Ingredient::class)->findBy(['restaurant' => $restaurant], ['id' => 'ASC']);

        $checkItem = $this->cache->getItem($this->checkKey($restaurant, $to));
        $this->checked = $checkItem->isHit() ? (array) $checkItem->get() : [];

        // First: is each text the owner already wrote for the new language in it?
        $unchecked = $this->unchecked($products, $categories, $tags, $from, $to);
        if ($unchecked !== []) {
            $error = !$this->check($restaurant, array_slice($unchecked, 0, self::CHECK_BATCH, true), $to);
            $this->cache->save($checkItem->set($this->checked));

            return ['done' => false, 'remaining' => max(1, count($unchecked) - ($error ? 0 : self::CHECK_BATCH)), 'error' => $error];
        }

        $pendingProducts    = $this->pendingRows($products, $from, $to);
        $pendingCategories  = $this->pendingRows($categories, $from, $to);
        $pendingTags        = $this->pendingRows($tags, $from, $to);
        $pendingIngredients = $this->pendingIngredients($ingredients, $from, $to);

        $stored = match (true) {
            $pendingProducts !== []    => $this->translateProducts(array_slice($pendingProducts, 0, self::PRODUCT_BATCH, true), $to),
            $pendingCategories !== []  => $this->translateCategories(array_slice($pendingCategories, 0, self::NAME_BATCH, true), $to),
            $pendingTags !== []        => $this->translateTags(array_slice($pendingTags, 0, self::NAME_BATCH, true), $to),
            $pendingIngredients !== [] => $this->translateIngredients($restaurant, array_slice($pendingIngredients, 0, self::NAME_BATCH, true), $to),
            default                    => null,
        };

        if ($stored === null) {
            // Everything else converted: the free texts, all at once, then done.
            if (!$this->convertOwnerTexts($restaurant, $from, $to)) {
                $this->em->flush();

                return ['done' => false, 'remaining' => 1, 'error' => true];
            }
            $this->finish($restaurant, $to);

            return ['done' => true, 'remaining' => 0, 'error' => false];
        }

        if ($stored > 0) {
            $restaurant->bumpMenuContentVersion();
        }
        $this->em->flush();
        $this->cache->save($checkItem->set($this->checked));

        $remaining = count($pendingProducts) + count($pendingCategories) + count($pendingTags) + count($pendingIngredients) - $stored + 1;

        return ['done' => false, 'remaining' => $remaining, 'error' => $stored === 0];
    }

    // ── Case a ──

    /**
     * The owner's old-language rows ARE the new language: they move to it.
     * An AI row already in the new language goes first (it was translated
     * from these very texts); one the owner wrote there stays, and so does
     * the old row next to it.
     */
    private function relabel(Restaurant $restaurant, string $from, string $to): void
    {
        $moves = [];
        $owned = array_merge(
            $this->products($restaurant),
            $this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]),
            $this->em->getRepository(ProductTag::class)->findBy(['restaurant' => $restaurant]),
        );
        foreach ($owned as $entity) {
            $old = $entity->getTranslation($from);
            if ($old === null || $old->getSource() !== ProductTranslation::SOURCE_HUMAN) {
                continue;
            }
            $new = $entity->getTranslation($to);
            if ($new !== null) {
                if ($new->getSource() === ProductTranslation::SOURCE_HUMAN) {
                    continue;
                }
                $entity->removeTranslation($new);
                $this->em->remove($new);
            }
            $moves[] = $old;
        }

        foreach ($this->em->getRepository(Ingredient::class)->findBy(['restaurant' => $restaurant]) as $ingredient) {
            $old = $ingredient->getTranslation($from);
            if ($old !== null && $ingredient->getTranslation($to) === null) {
                $moves[] = $old;
            }
        }

        // Deletes first: Doctrine runs updates before deletes, and (entity, locale) is unique.
        $this->em->flush();
        foreach ($moves as $translation) {
            $translation->setLocale($to);
        }
    }

    // ── Case b ──

    /**
     * The entities with no row in the new language, an AI one not written
     * by this conversion yet, or one in another language (see check()),
     * keyed by id, with the row their translation is made from.
     *
     * @param  array<int, Product|Category|ProductTag> $entities
     * @return array<int, ProductTranslation|CategoryTranslation|ProductTagTranslation>
     */
    private function pendingRows(array $entities, string $from, string $to): array
    {
        $pending = [];
        foreach ($entities as $entity) {
            $row = $entity->getTranslation($to);
            if ($row !== null && ($this->checked[$this->key($entity)] ?? !$this->isAi($row))) {
                continue;
            }
            if ($this->blankMainText($entity, $from)) {
                continue;
            }
            $source = TranslationSource::pick($entity->getTranslations(), $from, $to);
            if ($source !== null) {
                $pending[$entity->getId()] = $source;
            }
        }

        return $pending;
    }

    /**
     * @param  Ingredient[] $ingredients
     * @return array<int, array{0: string, 1: string}> id => [sourceLocale, name]
     */
    private function pendingIngredients(array $ingredients, string $from, string $to): array
    {
        $pending = [];
        foreach ($ingredients as $ingredient) {
            $source = Product::ingredientTranslationSource($ingredient, $to, $from);
            if ($source !== null) {
                $pending[$ingredient->getId()] = $source;
            }
        }

        return $pending;
    }

    /** @param array<int, ProductTranslation> $sources */
    private function translateProducts(array $sources, string $to): int
    {
        return $this->markWritten($this->textTranslator->translateProducts($sources, $to));
    }

    /** @param array<int, CategoryTranslation> $sources */
    private function translateCategories(array $sources, string $to): int
    {
        return $this->markWritten($this->textTranslator->translateCategories($sources, $to));
    }

    /** What this conversion wrote stays (see $checked). */
    private function markWritten(array $entities): int
    {
        foreach ($entities as $entity) {
            $this->checked[$this->key($entity)] = true;
        }

        return count($entities);
    }

    /** @param array<int, ProductTagTranslation> $sources */
    private function translateTags(array $sources, string $to): int
    {
        $result = $this->call(fn () => $this->reviewed($this->tagTranslator)->translate(
            array_map(static fn (ProductTagTranslation $s) => $s->getName(), $sources),
            $to,
            array_map(static fn (ProductTagTranslation $s) => $s->getLocale(), $sources),
        ), 'tags');

        $stored = 0;
        foreach ($sources as $id => $source) {
            $name = trim((string) ($result[$id] ?? ''));
            if ($name === '') {
                continue;
            }
            $tag = $source->getTag();
            $translation = $tag->getTranslation($to) ?? $this->added($tag, new ProductTagTranslation(), $to);
            $translation->setName($name);
            $translation->setSource(ProductTagTranslation::SOURCE_AI);
            $this->checked[$this->key($tag)] = true;
            $stored++;
        }

        return $stored;
    }

    /**
     * A private ingredient's name in the new language: the menu's own
     * translation of it if there is one already, else the AI's.
     *
     * @param array<int, array{0: string, 1: string}> $sources
     */
    private function translateIngredients(Restaurant $restaurant, array $sources, string $to): int
    {
        $names = $this->translateTexts($restaurant, $sources, $to, 'ingredients');

        $stored = 0;
        foreach ($sources as $id => $source) {
            if (!isset($names[$id])) {
                continue;
            }
            $translation = new IngredientTranslation();
            $translation->setLocale($to);
            $translation->setName($names[$id]);
            $this->em->find(Ingredient::class, $id)->addTranslation($translation);
            $this->em->persist($translation);
            $stored++;
        }

        return $stored;
    }

    /**
     * The tagline, set-menu descriptions and section labels and price
     * labels, in every dish and category (any state): replaced by their
     * translation. Made from the owner's original when the text there is
     * an AI translation of it (an earlier change) — and it's the original
     * itself when that is in the new language. Every text is then stored as
     * its translation into the old language, and the owner's original kept
     * as its translation into its own (origin 'human') — replacing an older
     * row for the same text (saveFromConversion()). All or nothing — a
     * half-converted set would be translated twice on the retry.
     */
    private function convertOwnerTexts(Restaurant $restaurant, string $from, string $to): bool
    {
        /** @var list<array{0: callable(): ?string, 1: callable(string): void}> $fields */
        $fields = [[$restaurant->getTagline(...), $restaurant->setTagline(...)]];
        foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
            $fields[] = [$category->getMenuDescription(...), $category->setMenuDescription(...)];
            foreach ($category->getMenuSections() as $section) {
                $fields[] = [$section->getLabel(...), $section->setLabel(...)];
            }
        }
        foreach ($this->products($restaurant) as $product) {
            $fields[] = [$product->getBasePriceLabel(...), $product->setBasePriceLabel(...)];
            foreach ($product->getPriceVariants() as $variant) {
                $fields[] = [$variant->getLabel(...), $variant->setLabel(...)];
            }
        }

        $texts = [];
        foreach ($fields as [$get]) {
            $text = trim((string) $get());
            if ($text !== '') {
                $texts[$text] = true;
            }
        }
        $texts = array_keys($texts);
        if ($texts === []) {
            return true;
        }

        // text => [locale, owner's text] when the text is an AI translation of it.
        $originals = [];
        $found = $this->ownerTexts->findOwnerOriginals($restaurant, array_map(static fn (string $t) => OwnerTextTranslation::hash($from, $t), $texts));
        foreach ($texts as $text) {
            if (isset($found[OwnerTextTranslation::hash($from, $text)])) {
                $originals[$text] = $found[OwnerTextTranslation::hash($from, $text)];
            }
        }

        $map = $sources = [];
        foreach ($texts as $i => $text) {
            [$locale, $original] = $originals[$text] ?? [$from, $text];
            if ($locale === $to) {
                $map[$text] = $original;
            } else {
                $sources[$i] = [$locale, $original];
            }
        }
        $translated = $this->translateWithAi($sources, $to, 'owner texts');
        if (count($translated) !== count($sources)) {
            return false;
        }
        foreach ($translated as $i => $translation) {
            $map[$texts[$i]] = $translation;
        }

        foreach ($fields as [$get, $set]) {
            $text = trim((string) $get());
            if ($text !== '' && $map[$text] !== $text) {
                $set($map[$text]);
            }
        }
        foreach ($map as $text => $translation) {
            if ($text === $translation) {
                continue;
            }
            if (!isset($originals[$text])) {
                $this->ownerTexts->saveFromConversion($restaurant, $from, $to, $translation, $text, OwnerTextTranslation::ORIGIN_HUMAN);
                continue;
            }
            [$locale, $original] = $originals[$text];
            $this->ownerTexts->saveFromConversion($restaurant, $from, $to, $translation, $text, OwnerTextTranslation::ORIGIN_AI);
            if ($locale !== $to) {
                $this->ownerTexts->saveFromConversion($restaurant, $locale, $to, $translation, $original, OwnerTextTranslation::ORIGIN_HUMAN);
            }
        }

        return true;
    }

    /**
     * Texts into $to: the menu's own translation of each one when it has
     * it already (OwnerTextTranslation), else the AI's.
     *
     * @param  array<int, array{0: string, 1: string}> $sources  key => [sourceLocale, text]
     * @return array<int, string> key => translation (the ones it got)
     */
    private function translateTexts(Restaurant $restaurant, array $sources, string $to, string $what): array
    {
        $known = $this->ownerTexts->findTranslatedByHash($restaurant, $to, array_map(
            static fn (array $s) => OwnerTextTranslation::hash($s[0], $s[1]),
            $sources,
        ));

        $result = $missing = [];
        foreach ($sources as $key => [$locale, $text]) {
            $hit = trim((string) ($known[OwnerTextTranslation::hash($locale, $text)] ?? ''));
            if ($hit !== '') {
                $result[$key] = $hit;
            } else {
                $missing[$key] = [$locale, $text];
            }
        }

        return $result + $this->translateWithAi($missing, $to, $what);
    }

    /**
     * Texts into $to by the AI (dish translator, name only).
     *
     * @param  array<int, array{0: string, 1: string}> $sources  key => [sourceLocale, text]
     * @return array<int, string> key => translation (the ones it got)
     */
    private function translateWithAi(array $sources, string $to, string $what): array
    {
        if ($sources === []) {
            return [];
        }
        $items = array_map(static fn (array $s) => ['name' => $s[1], 'description' => null], $sources);
        $locales = array_map(static fn (array $s) => $s[0], $sources);
        $answer = $this->call(fn () => $this->reviewed($this->productTranslator)->translate($items, $to, $locales), $what);

        $result = [];
        foreach (array_keys($sources) as $key) {
            $name = trim((string) ($answer[$key]['name'] ?? ''));
            if ($name !== '') {
                $result[$key] = $name;
            }
        }

        return $result;
    }

    /**
     * The new-language texts the owner wrote, not checked yet (see check()),
     * "p:12" => name. AI ones aren't checked: they're translated again
     * anyway (pendingRows()). Nothing to translate it from (or a blank
     * main-language text): kept, unchecked. Blank itself: replaced.
     *
     * @return array<string, string>
     */
    private function unchecked(array $products, array $categories, array $tags, string $from, string $to): array
    {
        $unchecked = [];
        foreach ([$products, $categories, $tags] as $group) {
            foreach ($group as $entity) {
                $row = $entity->getTranslation($to);
                $key = $this->key($entity);
                if ($row === null || isset($this->checked[$key]) || $row->getSource() !== ProductTranslation::SOURCE_HUMAN) {
                    continue;
                }
                if ($this->blankMainText($entity, $from) || TranslationSource::pick($entity->getTranslations(), $from, $to) === null) {
                    $this->checked[$key] = true;
                } elseif (trim($row->getName()) === '') {
                    $this->checked[$key] = false;
                } else {
                    $unchecked[$key] = trim($row->getName());
                }
            }
        }

        return $unchecked;
    }

    /**
     * Asks the AI the language of each text (one call). Another language
     * than $to: to be replaced. Can't tell: kept. Logs the languages found
     * (counts only, no texts).
     *
     * @param array<string, string> $texts
     * @return bool false when the AI gave no answer (retry later)
     */
    private function check(Restaurant $restaurant, array $texts, string $to): bool
    {
        try {
            $detected = $this->detector->detectEach($texts);
        } catch (\Throwable $e) {
            $this->logger->warning('Menu language change: language check failed; retried on the next step.', ['exception' => $e]);
            $detected = null;
        }
        if ($detected === null) {
            return false;
        }

        $counts = [];
        foreach (array_keys($texts) as $key) {
            $language = $detected[$key] ?? null;
            $counts[$language ?? 'unsure'] = ($counts[$language ?? 'unsure'] ?? 0) + 1;
            $this->checked[$key] = $language === null || $language === $to;
        }
        ksort($counts);
        $this->logger->info('Menu language change: languages of the texts already stored for the new language.', [
            'restaurantId' => $restaurant->getId(),
            'to'           => $to,
            'detected'     => $counts,
        ]);

        return true;
    }

    /**
     * Its text in the main language is there but empty: nothing to
     * translate from — never another language instead; the new-language
     * text (if any) stays as it is.
     */
    private function blankMainText(Product|Category|ProductTag $entity, string $from): bool
    {
        $main = $entity->getTranslation($from);

        return $main !== null && trim($main->getName()) === '';
    }

    private function isAi(ProductTranslation|CategoryTranslation|ProductTagTranslation $row): bool
    {
        return $row->getSource() === ProductTranslation::SOURCE_AI;
    }

    private function key(Product|Category|ProductTag $entity): string
    {
        return match (true) {
            $entity instanceof Product  => 'p:',
            $entity instanceof Category => 'c:',
            default                     => 't:',
        } . $entity->getId();
    }

    /**
     * @template T of ProductTranslation|CategoryTranslation|ProductTagTranslation
     * @param  T $translation
     * @return T
     */
    private function added(Product|Category|ProductTag $entity, object $translation, string $locale): object
    {
        $translation->setLocale($locale);
        $entity->addTranslation($translation);
        $this->em->persist($translation);

        return $translation;
    }

    private function checkKey(Restaurant $restaurant, string $to): string
    {
        return 'menu_language_check_' . $restaurant->getId() . '_' . $to;
    }

    private function finish(Restaurant $restaurant, string $to): void
    {
        $this->cache->deleteItem($this->checkKey($restaurant, $to));
        $restaurant->setDefaultLanguage($to);
        $restaurant->setLanguageChangeTo(null);
        $restaurant->bumpMenuContentVersion();
        $this->em->flush();
        // The old language's translations of the texts are unused now (the
        // texts are in the new one, and so are the owner's originals' keys).
        $this->ownerTextPruner->prune($restaurant);
    }

    // ── Helpers ──

    /** @return Product[] every dish of the restaurant, in any state */
    private function products(Restaurant $restaurant): array
    {
        return $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->join('p.category', 'c')
            ->where('c.restaurant = :restaurant')
            ->setParameter('restaurant', $restaurant)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Any dish, category or tag (the free texts and ingredients alone don't make a menu to convert). */
    private function hasContent(Restaurant $restaurant): bool
    {
        return $this->products($restaurant) !== []
            || $this->em->getRepository(Category::class)->count(['restaurant' => $restaurant]) > 0
            || $this->em->getRepository(ProductTag::class)->count(['restaurant' => $restaurant]) > 0;
    }

    /** @return list<string> up to SAMPLE_SIZE names the owner wrote in $from (dishes first, then categories) */
    private function sample(Restaurant $restaurant, string $from): array
    {
        $names = [];
        $entities = array_merge($this->products($restaurant), $this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant], ['id' => 'ASC']));
        foreach ($entities as $entity) {
            $translation = $entity->getTranslation($from);
            if ($translation !== null && $translation->getSource() === ProductTranslation::SOURCE_HUMAN && trim($translation->getName()) !== '') {
                $names[] = trim($translation->getName());
            }
        }
        $names = array_values(array_unique($names));
        $tagline = trim((string) $restaurant->getTagline());
        // The tagline, unless it's an AI translation of the owner's (an earlier change).
        if (count($names) < self::SAMPLE_SIZE && $tagline !== ''
            && $this->ownerTexts->findOwnerOriginals($restaurant, [OwnerTextTranslation::hash($from, $tagline)]) === []
        ) {
            $names[] = $tagline;
        }

        return array_slice($names, 0, self::SAMPLE_SIZE);
    }

    /**
     * The translation here becomes the menu's main text: its review pass
     * goes to another AI than the one that translated, when the translator
     * can (IndependentReviewTranslator).
     *
     * @template T of object
     * @param  T $translator
     * @return T
     */
    private function reviewed(object $translator): object
    {
        return MenuTextTranslator::reviewed($translator);
    }

    /** The translator's answer, or [] when it threw (logged). */
    private function call(callable $translate, string $what): array
    {
        try {
            return $translate();
        } catch (\Throwable $e) {
            $this->logger->warning('Menu language change: ' . $what . ' translation failed; retried on the next step.', ['exception' => $e]);

            return [];
        }
    }
}
