<?php

namespace App\Service;

use App\Entity\Product;
use App\Entity\Restaurant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps AI-generated dish translations (ProductTranslation::SOURCE_AI) in
 * sync with the admin-entered source text.
 *
 * Missing translations are filled synchronously, but only behind the
 * loading screen (MenuController::warmTranslations()) — the menu render
 * itself never calls the AI. Each dish+locale pair is only ever translated
 * once in its lifetime (the result is cached forever — see the class
 * docblock on ProductTranslation), so paying for one extra AI call on the
 * rare first visit to a given locale is cheaper than running a permanent
 * background worker for something that fires this rarely. A batch that
 * fails, comes back cut off or isn't reached in time (AiProductTranslator)
 * stays missing: the page renders via the default-language fallback
 * already built into the templates (see standard/show.html.twig), and the
 * next visit in that locale goes through the loading screen again.
 *
 * Once written, an AI translation is never regenerated on its own — it would
 * defeat the point of caching it. It's only ever invalidated explicitly, by
 * invalidateStale(), when the admin edits the default-locale name/description
 * that AI translations are derived from.
 */
final class ProductTranslationService
{
    public function __construct(
        private readonly AiProductTranslator    $aiTranslator,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * Translates every given product missing a $locale translation, batch
     * by batch (AiProductTranslator), before returning — a no-op if none
     * are missing. No batch starts once $deadline has passed.
     *
     * @param Product[] $products
     */
    public function resolveForMenu(Restaurant $restaurant, array $products, string $locale, ?TranslationDeadline $deadline = null): void
    {
        if ($locale === $restaurant->getDefaultLanguage()) {
            return;
        }

        $hasMissing = false;
        foreach ($products as $product) {
            if ($product->getTranslation($locale) === null) {
                $hasMissing = true;
                break;
            }
        }

        if (!$hasMissing) {
            return;
        }

        try {
            $this->aiTranslator->translateMissing($restaurant, $locale, $deadline);
        } catch (\Throwable $e) {
            $this->logger->warning('Dish translation failed; falling back to default language for this request.', [
                'restaurantId' => $restaurant->getId(),
                'locale'       => $locale,
                'exception'    => $e,
            ]);
        }
    }

    /**
     * Deletes every AI-generated translation of $product other than
     * $sourceLocale itself, so they get regenerated from the new source text
     * the next time each locale is requested.
     */
    public function invalidateStale(Product $product, string $sourceLocale): void
    {
        foreach ($product->getTranslations()->toArray() as $translation) {
            if ($translation->isAiGenerated() && $translation->getLocale() !== $sourceLocale) {
                $product->removeTranslation($translation);
                $this->em->remove($translation);
            }
        }
    }
}
