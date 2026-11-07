<?php

namespace App\Service;

use App\Entity\Product;
use App\Entity\Restaurant;
use App\Repository\ProductRepository;
use App\Repository\ProductTranslationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Coordinates the find-missing → translate → persist cycle for dish
 * translations. AI provider details are fully encapsulated in
 * ProductTranslatorInterface; this class is agnostic to which backend is
 * wired in the service container.
 *
 * Every row this writes is tagged ProductTranslation::SOURCE_AI so it can be
 * told apart from admin-entered text and safely wiped when the source-locale
 * translation changes — see ProductTranslationService::invalidateStale().
 *
 * In batches of BATCH dishes, one AI call (plus its check pass) each: a
 * batch that works is stored at once, one that fails or comes back cut off
 * stores nothing and the next one still runs. No batch is started once
 * $deadline has passed — what's left is still missing, so the next loading
 * screen asks only for that. Each row goes in with "insert if missing"
 * (ProductTranslationRepository::insertAiIfMissing()): a row another visit
 * stored meanwhile is kept. Every batch that stored something bumps the
 * menu content version, so the public menu's cache stops serving the copy
 * read before it (see MenuController::menuCacheKeyPrefix()).
 */
final class AiProductTranslator
{
    /** Dishes per AI call (name + description each) — same as MenuLanguageChanger. */
    public const BATCH = 25;

    public function __construct(
        private readonly ProductTranslatorInterface   $translator,
        private readonly EntityManagerInterface       $em,
        private readonly ProductRepository            $productRepo,
        private readonly ProductTranslationRepository $translationRepo,
        private readonly LoggerInterface              $logger,
    ) {}

    /** @return int how many translations it stored */
    public function translateMissing(Restaurant $restaurant, string $locale, ?TranslationDeadline $deadline = null): int
    {
        $missing = $this->findProductsMissingTranslation($restaurant, $locale);
        if (empty($missing)) {
            return 0;
        }

        [$source, $sourceLocales] = $this->buildSourceMap($missing, $restaurant->getDefaultLanguage(), $locale);

        $stored = 0;
        foreach (array_chunk($source, self::BATCH, true) as $batch) {
            if ($deadline?->isPassed()) {
                $this->logger->info('Dish translation: out of time, the rest is left for the next visit.', [
                    'restaurantId' => $restaurant->getId(),
                    'locale'       => $locale,
                ]);
                break;
            }

            try {
                $translated = $this->translator->translate($batch, $locale, array_intersect_key($sourceLocales, $batch));
            } catch (\Throwable $e) {
                $this->logger->warning('Dish translation batch failed; it stays missing.', [
                    'restaurantId' => $restaurant->getId(),
                    'locale'       => $locale,
                    'exception'    => $e,
                ]);
                continue;
            }

            $storedNow = 0;
            foreach (array_keys($batch) as $productId) {
                $result = $translated[$productId] ?? null;
                if ($result === null || trim($result['name'] ?? '') === '') {
                    continue;
                }
                $description = isset($result['description']) && trim((string) $result['description']) !== ''
                    ? trim($result['description'])
                    : null;
                if ($this->translationRepo->insertAiIfMissing($productId, $locale, trim($result['name']), $description)) {
                    $storedNow++;
                }
            }

            if ($storedNow > 0) {
                $restaurant->bumpMenuContentVersion();
                $this->em->flush();
                $stored += $storedNow;
            }
        }

        return $stored;
    }

    /** @return Product[] */
    private function findProductsMissingTranslation(Restaurant $restaurant, string $locale): array
    {
        $missing = [];
        foreach ($this->productRepo->findActiveForRestaurant($restaurant) as $product) {
            // Only dishes the menu shows — a nameless or €0 dish is never translated.
            if ($product->isShownOnMenu() && $product->getTranslation($locale) === null) {
                $missing[] = $product;
            }
        }

        return $missing;
    }

    /**
     * Source text per product — see TranslationSource::pick() (the owner's
     * in the main language, else the owner's latest in another one, else
     * the main language's AI one), with its real language.
     *
     * @param  Product[] $products
     * @return array{0: array<int, array{name: string, description: ?string}>, 1: array<int, string>}
     *         [productId → source name/description, productId → source locale]
     */
    private function buildSourceMap(array $products, string $defaultLocale, string $targetLocale): array
    {
        $map     = [];
        $locales = [];
        foreach ($products as $product) {
            $source = TranslationSource::pick($product->getTranslations(), $defaultLocale, $targetLocale);
            if ($source !== null) {
                $map[$product->getId()] = [
                    'name'        => $source->getName(),
                    'description' => $source->getDescription(),
                    'section'     => TranslationSource::sectionName($product, $source->getLocale(), $defaultLocale),
                ];
                $locales[$product->getId()] = $source->getLocale();
            }
        }

        return [$map, $locales];
    }
}
