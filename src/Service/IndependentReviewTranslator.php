<?php

namespace App\Service;

/**
 * A translator whose review pass can be done by a different AI than the
 * one that translated (TranslationAiCaller::completeElsewhere()) — used
 * only by the menu's main-language change (MenuLanguageChanger), where the
 * translation becomes the owner's own text. No other AI available, or it
 * fails: the translation is kept as it is.
 */
interface IndependentReviewTranslator
{
    /** A copy whose review pass goes to another AI; this one is unchanged. */
    public function withIndependentReview(): static;
}
