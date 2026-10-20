<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\OwnerTextTranslation;
use App\Entity\Restaurant;
use App\Repository\OwnerTextTranslationRepository;
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

    /** True when $locale isn't the source language and at least one text has no translation yet. */
    public function hasAnyMissing(Restaurant $restaurant, string $locale): bool
    {
        return $this->missingTexts($restaurant, $locale) !== [];
    }

    /**
     * Translates every missing text into $locale in one batch and stores
     * it. A failed or partial AI answer just leaves those texts missing —
     * the menu shows the original meanwhile, and the next loading screen
     * tries again.
     */
    public function translateMissing(Restaurant $restaurant, string $locale): void
    {
        $missing = $this->missingTexts($restaurant, $locale);
        if ($missing === []) {
            return;
        }

        $source = [];
        foreach ($missing as $i => $text) {
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
        $sourceLocale = $restaurant->getDefaultLanguage();
        foreach ($missing as $i => $text) {
            $result = trim((string) ($translated[$i]['name'] ?? ''));
            if ($result !== '') {
                $this->repository->insertIfMissing($restaurant, $locale, $sourceLocale, $text, $result);
            }
        }
    }

    /** Loads, in one query, every translation the menu about to render needs. Never calls the AI. */
    public function primeForMenu(Restaurant $restaurant, string $locale): void
    {
        $this->primedSourceLocale = $restaurant->getDefaultLanguage();
        $this->primed = $locale === $this->primedSourceLocale
            ? []
            : $this->repository->findTranslatedByHash($restaurant, $locale, array_map(
                fn (string $text) => OwnerTextTranslation::hash($this->primedSourceLocale, $text),
                $this->collectTexts($restaurant),
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

    /** @return array<int, string> the texts with no translation into $locale yet */
    private function missingTexts(Restaurant $restaurant, string $locale): array
    {
        $sourceLocale = $restaurant->getDefaultLanguage();
        if ($locale === $sourceLocale) {
            return [];
        }

        $texts = $this->collectTexts($restaurant);
        $found = $this->repository->findTranslatedByHash($restaurant, $locale, array_map(
            static fn (string $text) => OwnerTextTranslation::hash($sourceLocale, $text),
            $texts,
        ));

        return array_values(array_filter(
            $texts,
            static fn (string $text) => !isset($found[OwnerTextTranslation::hash($sourceLocale, $text)]),
        ));
    }
}
