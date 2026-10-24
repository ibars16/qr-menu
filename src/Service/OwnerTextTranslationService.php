<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\OwnerTextTranslation;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Repository\OwnerTextTranslationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Translates the owner's free texts that have no translation table of their
 * own: the tagline, multiprice labels (base + variants), set-menu
 * descriptions and set-menu section labels. Never the restaurant's name or
 * address — those are data, not copy.
 *
 * - translateMissing(): ONE batch per locale with every text still missing,
 *   through the dish translator (ProductTranslatorInterface — same model
 *   chain and verifyAndFix() second pass). Only ever called from
 *   MenuController::warmTranslations(), i.e. behind the loading screen —
 *   never from a menu render, never through the queue.
 * - primeForMenu() + text(): the render side. One query loads every
 *   translation the menu needs; text() never calls the AI.
 *
 * Rows are keyed by the source text's hash (see OwnerTextTranslation), so an
 * edited text simply counts as missing again.
 *
 * Ingredient names ride in the same batch (collectIngredientSources()): the
 * names of the visible dishes' ingredients, global and private, that have
 * no native name in the locale (Product::ingredientTranslationSource()).
 * Their translations live ONLY here — never in ingredient_translation /
 * global_ingredient_translation, which the admin's name matching
 * (findExistingByNameAnyLocale) and therefore allergen assignment read.
 * Unlike the owner texts, an ingredient's source may be English, and an
 * English-only one is translated into the default language too.
 */
final class OwnerTextTranslationService implements ResetInterface
{
    /** @var array<string, string> source hash => translation, for the menu being rendered */
    private array $primed = [];

    private ?string $primedSourceLocale = null;

    public function __construct(
        private readonly OwnerTextTranslationRepository $repository,
        private readonly ProductTranslatorInterface     $translator,
        private readonly LoggerInterface                $logger,
        private readonly EntityManagerInterface         $em,
    ) {}

    /**
     * Every distinct owner text the public menu shows, in the restaurant's
     * default language — same visibility rules as standard/show.html.twig
     * and _set_menu.html.twig (active categories, active dishes, a base
     * label only where the price-stack renders it).
     *
     * @return string[]
     */
    public function collectTexts(Restaurant $restaurant): array
    {
        $texts = [$restaurant->getTagline()];

        foreach ($restaurant->getCategories() as $category) {
            /** @var Category $category */
            if (!$category->isActive()) {
                continue;
            }

            if ($category->isFixedPriceMenu()) {
                if (!$category->hasActiveMenuDishes()) {
                    continue;
                }
                $texts[] = $category->getMenuDescription();
                foreach ($category->getActiveSectionsWithProducts() as $entry) {
                    if (count($entry['products']) > 0) {
                        $texts[] = $entry['section']->getLabel();
                    }
                }
                continue;
            }

            foreach ($category->getActiveProductsSorted() as $product) {
                if (!$product->hasPriceVariants()) {
                    continue;
                }
                $texts[] = $product->getBasePriceLabel();
                foreach ($product->getPriceVariants() as $variant) {
                    $texts[] = $variant->getLabel();
                }
            }
        }

        $texts = array_map(static fn (?string $t) => trim((string) $t), $texts);

        return array_values(array_unique(array_filter($texts, static fn (string $t) => $t !== '')));
    }

    /**
     * The [sourceLocale, text] pairs the visible dishes' ingredients need
     * translated into $locale (see Product::ingredientTranslationSource()),
     * deduplicated.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function collectIngredientSources(Restaurant $restaurant, string $locale): array
    {
        return $this->ingredientSources($this->visibleProducts($restaurant), $locale, $restaurant->getDefaultLanguage());
    }

    /** True when at least one owner text or ingredient name has no translation into $locale yet. */
    public function hasAnyMissing(Restaurant $restaurant, string $locale): bool
    {
        return $this->missingSources($restaurant, $locale) !== [];
    }

    /**
     * Translates every missing text into $locale in one batch, stores it and
     * bumps the menu content version if anything was stored. A failed or
     * partial AI answer just leaves those texts missing — the menu shows the
     * original meanwhile, and the next loading screen tries again.
     */
    public function translateMissing(Restaurant $restaurant, string $locale): void
    {
        $missing = $this->missingSources($restaurant, $locale);
        if ($missing === []) {
            return;
        }

        $source = [];
        foreach ($missing as $i => [, $text]) {
            $source[$i] = ['name' => $text, 'description' => null];
        }

        try {
            $translated = $this->translator->translate($source, $locale);
        } catch (\Throwable $e) {
            $this->logger->warning('Owner text translation failed; originals are shown meanwhile.', [
                'restaurantId' => $restaurant->getId(),
                'locale'       => $locale,
                'exception'    => $e,
            ]);

            return;
        }

        // ON CONFLICT DO NOTHING (see the repository): a row a concurrent
        // loading screen already stored for the same text is just skipped.
        $stored = false;
        foreach ($missing as $i => [$sourceLocale, $text]) {
            $result = trim((string) ($translated[$i]['name'] ?? ''));
            if ($result !== '') {
                $stored = $this->repository->insertIfMissing($restaurant, $locale, $sourceLocale, $text, $result) || $stored;
            }
        }

        if ($stored) {
            $restaurant->bumpMenuContentVersion();
            $this->em->flush();
        }
    }

    /** Loads, in one query, every translation the menu about to render needs. Never calls the AI. */
    public function primeForMenu(Restaurant $restaurant, string $locale): void
    {
        $this->primedSourceLocale = $restaurant->getDefaultLanguage();
        $this->primed = $this->repository->findTranslatedByHash($restaurant, $locale, array_map(
            static fn (array $source) => OwnerTextTranslation::hash($source[0], $source[1]),
            $this->allSources($restaurant, $locale),
        ));
    }

    /** The primed translation of $text, or null (not primed, source locale, or not translated yet). */
    public function translationOf(string $text): ?string
    {
        if ($this->primedSourceLocale === null) {
            return null;
        }

        return $this->primed[OwnerTextTranslation::hash($this->primedSourceLocale, trim($text))] ?? null;
    }

    /**
     * The primed menu's ingredient dictionary, for Product::getIngredientNames().
     *
     * @return \Closure(string, string): ?string
     */
    public function primedIngredientDictionary(): \Closure
    {
        return fn (string $sourceLocale, string $text): ?string => $this->primed[OwnerTextTranslation::hash($sourceLocale, $text)] ?? null;
    }

    /**
     * An ingredient dictionary for $products in $locale, loaded in one query
     * — for callers outside the menu render (Smart Waiter). Never calls the AI.
     *
     * @param  Product[] $products
     * @return \Closure(string, string): ?string
     */
    public function ingredientDictionary(Restaurant $restaurant, string $locale, array $products): \Closure
    {
        $found = $this->repository->findTranslatedByHash($restaurant, $locale, array_map(
            static fn (array $source) => OwnerTextTranslation::hash($source[0], $source[1]),
            $this->ingredientSources($products, $locale, $restaurant->getDefaultLanguage()),
        ));

        return static fn (string $sourceLocale, string $text): ?string => $found[OwnerTextTranslation::hash($sourceLocale, $text)] ?? null;
    }

    /** The source language of the primed menu (what an untranslated text is written in). */
    public function sourceLocale(): ?string
    {
        return $this->primedSourceLocale;
    }

    public function reset(): void
    {
        $this->primed = [];
        $this->primedSourceLocale = null;
    }

    /**
     * Owner texts (only when $locale isn't the default language) plus
     * ingredient names, as [sourceLocale, text] pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function allSources(Restaurant $restaurant, string $locale): array
    {
        $defaultLocale = $restaurant->getDefaultLanguage();
        $sources = $locale === $defaultLocale
            ? []
            : array_map(static fn (string $text) => [$defaultLocale, $text], $this->collectTexts($restaurant));

        $seen = [];
        foreach ($sources as [$sourceLocale, $text]) {
            $seen[OwnerTextTranslation::hash($sourceLocale, $text)] = true;
        }
        foreach ($this->collectIngredientSources($restaurant, $locale) as $source) {
            if (!isset($seen[OwnerTextTranslation::hash($source[0], $source[1])])) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /** @return list<array{0: string, 1: string}> the [sourceLocale, text] pairs with no translation into $locale yet */
    private function missingSources(Restaurant $restaurant, string $locale): array
    {
        $sources = $this->allSources($restaurant, $locale);
        $found = $this->repository->findTranslatedByHash($restaurant, $locale, array_map(
            static fn (array $source) => OwnerTextTranslation::hash($source[0], $source[1]),
            $sources,
        ));

        return array_values(array_filter(
            $sources,
            static fn (array $source) => !isset($found[OwnerTextTranslation::hash($source[0], $source[1])]),
        ));
    }

    /**
     * @param  Product[] $products
     * @return list<array{0: string, 1: string}>
     */
    private function ingredientSources(array $products, string $locale, string $defaultLocale): array
    {
        $sources = [];
        foreach ($products as $product) {
            foreach ($product->getOrderedIngredients() as $ingredient) {
                $source = Product::ingredientTranslationSource($ingredient, $locale, $defaultLocale);
                if ($source !== null) {
                    $sources[OwnerTextTranslation::hash($source[0], $source[1])] = $source;
                }
            }
        }

        return array_values($sources);
    }

    /**
     * The dishes the public menu shows — same visibility rules as
     * collectTexts() (active categories, active dishes, a set menu only
     * with active dishes).
     *
     * @return Product[]
     */
    private function visibleProducts(Restaurant $restaurant): array
    {
        $products = [];

        foreach ($restaurant->getCategories() as $category) {
            /** @var Category $category */
            if (!$category->isActive()) {
                continue;
            }

            if ($category->isFixedPriceMenu()) {
                if (!$category->hasActiveMenuDishes()) {
                    continue;
                }
                foreach ($category->getActiveSectionsWithProducts() as $entry) {
                    foreach ($entry['products'] as $product) {
                        $products[] = $product;
                    }
                }
                continue;
            }

            foreach ($category->getActiveProductsSorted() as $product) {
                $products[] = $product;
            }
        }

        return $products;
    }
}
