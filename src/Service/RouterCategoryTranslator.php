<?php

namespace App\Service;

use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderException;
use App\Service\AI\AIProviderFactory;
use App\Service\AI\AIRequest;
use App\Service\AI\ChatMessage;
use Psr\Log\LoggerInterface;

/**
 * Translates menu category names (e.g. "Pizzas", "Postres") — see
 * RouterProductTranslator's docblock for why this prefers the free
 * groq-llama provider before falling back to AIModelRouter's normal
 * 'complex' routing, and why translate() always runs a second
 * verify-and-fix pass afterwards. Keyed by category ID (not name text) so
 * two restaurants' — or even one restaurant's own — identically-named
 * categories never collide.
 */
final class RouterCategoryTranslator implements CategoryTranslatorInterface
{
    public function __construct(
        private readonly AIModelRouter     $router,
        private readonly AIProviderFactory $providerFactory,
        private readonly LoggerInterface   $logger,
    ) {}

    public function translate(array $names, string $targetLocale): array
    {
        if (empty($names)) {
            return [];
        }

        $items = [];
        foreach ($names as $id => $name) {
            $items[] = ['id' => (string) $id, 'name' => $name];
        }
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);

        $systemPrompt = 'You are a restaurant menu translator. '
            . "Translate the \"name\" of each menu category below into the language with ISO 639-1 code \"{$targetLocale}\". "
            . 'Keep names short, natural for a menu section heading (1-3 words). '
            . 'Return ONLY a valid JSON object mapping each "id" to its translated name (a plain string, not an object) — '
            . 'no other text, no markdown code fences, no explanations.';

        $content = $this->complete($systemPrompt, "Categories: {$itemsJson}", 1024);
        if ($content === null) {
            return [];
        }

        $translated = $this->parse($content);
        if (empty($translated)) {
            return [];
        }

        return $this->verifyAndFix($translated, $names, $targetLocale);
    }

    /**
     * Same reasoning as RouterProductTranslator::verifyAndFix() — shows the
     * model its own translation next to the Spanish source and asks it to
     * catch fabricated/garbled/merged words. Keeps the first-pass result if
     * this second pass can't run at all.
     *
     * @param array<int, string> $translated
     * @param array<int, string> $sourceNames
     * @return array<int, string>
     */
    private function verifyAndFix(array $translated, array $sourceNames, string $targetLocale): array
    {
        $items = [];
        foreach ($translated as $id => $name) {
            $items[] = [
                'id'               => (string) $id,
                'source_name'      => $sourceNames[$id] ?? '',
                'translated_name'  => $name,
            ];
        }
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);

        $systemPrompt = 'You are a translation quality checker for a restaurant menu. '
            . "You'll see, for each menu category, its original Spanish name and a candidate translation into the language with ISO 639-1 code \"{$targetLocale}\". "
            . 'Check the candidate carefully: is it made of real, correctly-spelled words that actually exist in that language — not a fabricated string, and not two real words merged together with no space? Is it a natural, faithful translation? '
            . 'If a candidate is already correct, return it completely unchanged. If it has a problem, replace it with a corrected translation. '
            . 'Return ONLY a valid JSON object mapping each "id" to its translated name (a plain string, not an object) — no other text, no markdown code fences, no explanations.';

        $content = $this->complete($systemPrompt, "Categories: {$itemsJson}", 1024);
        if ($content === null) {
            return $translated;
        }

        $fixed = $this->parse($content);

        return !empty($fixed) ? $fixed : $translated;
    }

    /**
     * Tries the free groq-llama provider first, falling back to
     * AIModelRouter's normal chain — shared by both the translate and
     * verify passes. Returns null only once every option has failed.
     */
    private function complete(string $systemPrompt, string $userContent, int $maxTokens): ?string
    {
        $request = new AIRequest(
            systemPrompt: $systemPrompt,
            messages: [new ChatMessage('user', $userContent)],
            maxTokens: $maxTokens,
            temperature: 0.1,
        );

        $freeProvider = $this->providerFactory->getProviderById('groq-llama');
        if ($freeProvider !== null) {
            try {
                return $freeProvider->complete($request)->content;
            } catch (AIProviderException $e) {
                $this->logger->warning('groq-llama call failed, falling back to AIModelRouter', [
                    'reason'  => $e->reason->value,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $result = $this->router->route($request, 'complex');

        return $result->isSuccess() ? $result->response->content : null;
    }

    /** @return array<int, string> */
    private function parse(string $text): array
    {
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/', '', trim($text));

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $id => $name) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $result[(int) $id] = trim($name);
        }

        return $result;
    }
}
