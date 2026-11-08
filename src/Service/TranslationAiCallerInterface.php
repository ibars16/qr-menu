<?php

namespace App\Service;

/**
 * Who RouterProductTranslator sends its two AI calls to. The menu always
 * uses TranslationAiCaller (Groq first, then the router's chain); the
 * manual translation eval (app:translation:eval) swaps in
 * TranslationEval\PinnedProviderAiCaller — one provider, no fallback — so
 * it runs the very same prompts as the menu without copying them.
 */
interface TranslationAiCallerInterface
{
    /** @return array{content: string, provider: string}|null */
    public function complete(string $systemPrompt, string $userContent, int $maxTokens): ?array;

    public function completeElsewhere(string $systemPrompt, string $userContent, int $maxTokens, string $notProvider): ?string;
}
