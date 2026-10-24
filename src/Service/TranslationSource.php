<?php

namespace App\Service;

use App\Entity\CategoryTranslation;
use App\Entity\ProductTagTranslation;
use App\Entity\ProductTranslation;

/**
 * Which existing translation an AI translation into $targetLocale is made
 * from — never assuming the restaurant's text is in any particular language:
 * the default-language one, else one a person wrote (or a preset), else any.
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
        $candidates = [];
        foreach ($translations as $translation) {
            if ($translation->getLocale() !== $targetLocale && trim($translation->getName()) !== '') {
                $candidates[] = $translation;
            }
        }

        foreach ($candidates as $translation) {
            if ($translation->getLocale() === $defaultLocale) {
                return $translation;
            }
        }
        foreach ($candidates as $translation) {
            if ($translation->getSource() !== ProductTranslation::SOURCE_AI) {
                return $translation;
            }
        }

        return $candidates[0] ?? null;
    }
}
