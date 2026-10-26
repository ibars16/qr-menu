<?php

namespace App\Service;

use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Coordinates the find-missing → translate → persist cycle for dish
 * translations. AI provider details are fully encapsulated in
 * ProductTranslatorInterface; this class is agnostic to which backend is
 * wired in the service container.
 *
 * Every row this writes is tagged ProductTranslation::SOURCE_AI so it can be
 * told apart from admin-entered text and safely wiped when the source-locale
 * translation changes — see ProductTranslationService::invalidateStale().
 */
final class AiProductTranslator
{
    public function __construct(
        private readonly ProductTranslatorInterface $translator,
        private readonly EntityManagerInterface     $em,
        private readonly ProductRepository          $productRepo,
    ) {}

    public function translateMissing(Restaurant $restaurant, string $locale): void
    {
        $missing = $this->findProductsMissingTranslation($restaurant, $locale);

        if (empty($missing)) {
            return;
        }

        [$source, $sourceLocales] = $this->buildSourceMap($missing, $restaurant->getDefaultLanguage(), $locale);

        if (empty($source)) {
            return;
        }

        $translated = $this->translator->translate($source, $locale, $sourceLocales);

        foreach ($missing as $product) {
            $result = $translated[$product->getId()] ?? null;

            if ($result === null || trim($result['name'] ?? '') === '') {
                continue;
            }

            // Guard against a duplicate inserted by a concurrent worker.
            if ($product->getTranslation($locale) !== null) {
                continue;
            }

            $t = new ProductTranslation();
            $t->setLocale($locale);
            $t->setName(trim($result['name']));
            $t->setDescription(
                isset($result['description']) && trim((string) $result['description']) !== ''
                    ? trim($result['description'])
                    : null
            );
            $t->setSource(ProductTranslation::SOURCE_AI);
            $product->addTranslation($t);
            $this->em->persist($t);
        }

        $this->em->flush();
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
     * Source text per product — see TranslationSource::pick() (default
     * language, else one a person wrote, else any), with its real language.
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
                ];
                $locales[$product->getId()] = $source->getLocale();
            }
        }
        return [$map, $locales];
    }
}
