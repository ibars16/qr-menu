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
     * generates them in one batch (plus its check pass), and persists the results.
     */
    public function translateMissing(Restaurant $restaurant, string $locale): void
    {
        $missing = $this->findTagsMissingTranslation($restaurant, $locale);

        if (empty($missing)) {
            return;
        }

        [$names, $sourceLocales] = $this->buildNameMap($missing, $restaurant->getDefaultLanguage(), $locale);

        if (empty($names)) {
            return;
        }

        $translated = $this->translator->translate($names, $locale, $sourceLocales);

        foreach ($missing as $tag) {
            $result = $translated[$tag->getId()] ?? null;

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
            $t->setSource(ProductTagTranslation::SOURCE_AI);
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
     * Source name per tag — see TranslationSource::pick() — with its real
     * language.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}  [tagId → source name, tagId → source locale]
     */
    private function buildNameMap(array $tags, string $defaultLocale, string $targetLocale): array
    {
        $map     = [];
        $locales = [];
        foreach ($tags as $tag) {
            $source = TranslationSource::pick($tag->getTranslations(), $defaultLocale, $targetLocale);
            if ($source !== null) {
                $map[$tag->getId()]     = $source->getName();
                $locales[$tag->getId()] = $source->getLocale();
            }
        }
        return [$map, $locales];
    }
}
