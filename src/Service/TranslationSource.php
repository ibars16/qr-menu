<?php

namespace App\Service;

use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductTagTranslation;
use App\Entity\ProductTranslation;

/**
 * Which existing translation an AI translation into $targetLocale is made
 * from — never assuming the restaurant's text is in any particular language:
 *  1. the one the owner wrote in the main language ($defaultLocale);
 *  2. else the latest one the owner wrote in any other language (highest id:
 *     translations carry no date — usually the old main language's, after a
 *     main-language change);
 *  3. else the main language's AI one;
 *  4. else a preset (tags), else any.
 * Its own locale (getLocale()) is the source language the translator is told.
 * Blank names and the target locale itself are never a source.
 */
final class TranslationSource
{
    /**
     * @template T of ProductTranslation|CategoryTranslation|ProductTagTranslation
     * @param  iterable<T> $translations
     * @return T|null
     */
    public static function pick(iterable $translations, string $defaultLocale, string $targetLocale): ?object
    {
        $latestHuman = $mainAi = $other = $any = null;
        foreach ($translations as $translation) {
            if ($translation->getLocale() === $targetLocale || trim($translation->getName()) === '') {
                continue;
            }
            $source = $translation->getSource();
            if ($source === ProductTranslation::SOURCE_HUMAN) {
                if ($translation->getLocale() === $defaultLocale) {
                    return $translation;
                }
                if ($latestHuman === null || self::order($translation) > self::order($latestHuman)) {
                    $latestHuman = $translation;
                }
            } elseif ($source === ProductTranslation::SOURCE_AI) {
                if ($translation->getLocale() === $defaultLocale) {
                    $mainAi = $translation;
                }
            } else {
                $other ??= $translation;
            }
            $any ??= $translation;
        }

        return $latestHuman ?? $mainAi ?? $other ?? $any;
    }

    /** Creation order: the id, a row not stored yet being the newest. */
    private static function order(object $translation): int
    {
        return $translation->getId() ?? PHP_INT_MAX;
    }

    /**
     * The name of the dish's menu section (its category) in $locale — the
     * language its text is translated from — else in the default
     * language; null when it has none. Sent to the translator as context.
     */
    public static function sectionName(Product $product, string $locale, string $defaultLocale): ?string
    {
        $category = $product->getCategory();
        foreach ([$locale, $defaultLocale] as $candidate) {
            $name = trim((string) $category?->getTranslation($candidate)?->getName());
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }
}
