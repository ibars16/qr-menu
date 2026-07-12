<?php

namespace App\Service;

use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\Restaurant;
use App\Repository\ProductTagTranslationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Coordinates the find-missing → translate → persist cycle for tag translations.
 *
 * AI provider details are fully encapsulated in TagTranslatorInterface; this
 * class is agnostic to which backend is wired in the service container.
 */
final class AiTagTranslator
{
    public function __construct(
        private readonly TagTranslatorInterface           $translator,
        private readonly EntityManagerInterface           $em,
        private readonly ProductTagTranslationRepository  $translationRepo,
    ) {}

    /**
     * Finds all tags for the restaurant that lack a translation in $locale,
     * generates them in a single batch API call, and persists the results.
     */
    public function translateMissing(Restaurant $restaurant, string $locale): void
    {
        $missing = $this->findTagsMissingTranslation($restaurant, $locale);

        if (empty($missing)) {
            return;
        }

        $defaultLocale = $restaurant->getDefaultLanguage();
        $names         = $this->buildNameMap($missing, $defaultLocale);

        if (empty($names)) {
            return;
        }

        $translated = $this->translator->translate($names, $locale);

        foreach ($missing as $tag) {
            $sourceName = $names[$tag->getId()] ?? null;
            $result     = $translated[$sourceName] ?? null;

            if ($result === null || trim($result) === '') {
                continue;
            }

            // Guard against a duplicate inserted by a concurrent worker.
            $exists = $this->translationRepo->findOneBy(['tag' => $tag, 'locale' => $locale]);
            if ($exists !== null) {
                continue;
            }

            $t = new ProductTagTranslation();
            $t->setTag($tag);
            $t->setLocale($locale);
            $t->setName(trim($result));
            $this->em->persist($t);
        }

        $this->em->flush();
    }

    /** @return ProductTag[] */
    private function findTagsMissingTranslation(Restaurant $restaurant, string $locale): array
    {
        $missing = [];
        foreach ($restaurant->getProductTags() as $tag) {
            if ($tag->getTranslation($locale) === null) {
                $missing[] = $tag;
            }
        }
        return $missing;
    }

    /**
     * @return array<int, string>  tagId → source name
     */
    private function buildNameMap(array $tags, string $defaultLocale): array
    {
        $map = [];
        foreach ($tags as $tag) {
            $source = $tag->getTranslation($defaultLocale);
            if ($source !== null) {
                $map[$tag->getId()] = $source->getName();
            }
        }
        return $map;
    }
}
