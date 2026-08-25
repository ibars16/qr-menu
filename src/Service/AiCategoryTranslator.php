<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Restaurant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Coordinates the find-missing → translate → persist cycle for category-name
 * translations. AI provider details are fully encapsulated in
 * CategoryTranslatorInterface; this class is agnostic to which backend is
 * wired in the service container.
 *
 * A restaurant's category list is small (a handful to a few dozen rows), so
 * — like AiTagTranslator — this walks Restaurant::getCategories() in memory
 * rather than issuing a dedicated repository query.
 */
final class AiCategoryTranslator
{
    public function __construct(
        private readonly CategoryTranslatorInterface $translator,
        private readonly EntityManagerInterface       $em,
    ) {}

    public function translateMissing(Restaurant $restaurant, string $locale): void
    {
        $missing = $this->findCategoriesMissingTranslation($restaurant, $locale);

        if (empty($missing)) {
            return;
        }

        $defaultLocale = $restaurant->getDefaultLanguage();
        $names         = $this->buildNameMap($missing, $defaultLocale);

        if (empty($names)) {
            return;
        }

        $translated = $this->translator->translate($names, $locale);

        foreach ($missing as $category) {
            $result = $translated[$category->getId()] ?? null;

            if ($result === null || trim($result) === '') {
                continue;
            }

            // Guard against a duplicate written by a concurrent request.
            if ($category->getTranslation($locale) !== null) {
                continue;
            }

            $t = new CategoryTranslation();
            $t->setCategory($category);
            $t->setLocale($locale);
            $t->setName(trim($result));
            $t->setSource(CategoryTranslation::SOURCE_AI);
            $this->em->persist($t);
        }

        $this->em->flush();
    }

    /** @return Category[] */
    private function findCategoriesMissingTranslation(Restaurant $restaurant, string $locale): array
    {
        $missing = [];
        foreach ($restaurant->getCategories() as $category) {
            if ($category->isActive() && $category->getTranslation($locale) === null) {
                $missing[] = $category;
            }
        }
        return $missing;
    }

    /**
     * @param  Category[] $categories
     * @return array<int, string>  categoryId → source name
     */
    private function buildNameMap(array $categories, string $defaultLocale): array
    {
        $map = [];
        foreach ($categories as $category) {
            $source = $category->getTranslation($defaultLocale);
            if ($source !== null && trim($source->getName()) !== '') {
                $map[$category->getId()] = $source->getName();
            }
        }
        return $map;
    }
}
