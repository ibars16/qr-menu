<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes a menu text in another language from the row it's translated from,
 * one AI call per batch, as an AI translation ('ai') — the translation that
 * becomes the menu's main text, so its review pass goes to another AI
 * (IndependentReviewTranslator). Shared by the main-language change
 * (MenuLanguageChanger) and the "translate this import" notice
 * (MenuImportLanguageNotice). Does not flush.
 */
final class MenuTextTranslator
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly ProductTranslatorInterface  $productTranslator,
        private readonly CategoryTranslatorInterface $categoryTranslator,
        private readonly LoggerInterface             $logger,
    ) {}

    /**
     * @param  array<int, ProductTranslation> $sources  id => the row to translate from
     * @return list<Product>  the dishes whose $to text it wrote (none when the AI call failed)
     */
    public function translateProducts(array $sources, string $to): array
    {
        $input = $locales = [];
        foreach ($sources as $id => $source) {
            $product      = $source->getProduct();
            $input[$id]   = [
                'name'        => $source->getName(),
                'description' => $source->getDescription(),
                'section'     => TranslationSource::sectionName($product, $source->getLocale(), $product->getCategory()->getRestaurant()->getDefaultLanguage()),
            ];
            $locales[$id] = $source->getLocale();
        }
        $result = $this->call(fn () => $this->reviewed($this->productTranslator)->translate($input, $to, $locales), 'dishes');

        $written = [];
        foreach ($sources as $id => $source) {
            $name = trim((string) ($result[$id]['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $description = trim((string) ($result[$id]['description'] ?? ''));
            $product = $source->getProduct();
            $translation = $product->getTranslation($to) ?? $this->added($product, new ProductTranslation(), $to);
            $translation->setName($name);
            $translation->setDescription($description !== '' && $source->getDescription() !== null ? $description : null);
            $translation->setSource(ProductTranslation::SOURCE_AI);
            $written[] = $product;
        }

        return $written;
    }

    /**
     * @param  array<int, CategoryTranslation> $sources  id => the row to translate from
     * @return list<Category>  the categories whose $to name it wrote (none when the AI call failed)
     */
    public function translateCategories(array $sources, string $to): array
    {
        $result = $this->call(fn () => $this->reviewed($this->categoryTranslator)->translate(
            array_map(static fn (CategoryTranslation $s) => $s->getName(), $sources),
            $to,
            array_map(static fn (CategoryTranslation $s) => $s->getLocale(), $sources),
        ), 'categories');

        $written = [];
        foreach ($sources as $id => $source) {
            $name = trim((string) ($result[$id] ?? ''));
            if ($name === '') {
                continue;
            }
            $category = $source->getCategory();
            $translation = $category->getTranslation($to) ?? $this->added($category, new CategoryTranslation(), $to);
            $translation->setName($name);
            $translation->setSource(CategoryTranslation::SOURCE_AI);
            $written[] = $category;
        }

        return $written;
    }

    /**
     * @template T of object
     * @param  T $translator
     * @return T
     */
    public static function reviewed(object $translator): object
    {
        return $translator instanceof IndependentReviewTranslator ? $translator->withIndependentReview() : $translator;
    }

    /**
     * @template T of ProductTranslation|CategoryTranslation
     * @param  T $translation
     * @return T
     */
    private function added(Product|Category $entity, object $translation, string $locale): object
    {
        $translation->setLocale($locale);
        $entity->addTranslation($translation);
        $this->em->persist($translation);

        return $translation;
    }

    /** The translator's answer, or [] when it threw (logged). */
    private function call(callable $translate, string $what): array
    {
        try {
            return $translate();
        } catch (\Throwable $e) {
            $this->logger->warning('Menu translation: ' . $what . ' translation failed; retried on the next step.', ['exception' => $e]);

            return [];
        }
    }
}
