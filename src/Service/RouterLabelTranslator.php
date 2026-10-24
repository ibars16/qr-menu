<?php

namespace App\Service;

use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderException;
use App\Service\AI\AIProviderFactory;
use App\Service\AI\AIRequest;
use App\Service\AI\ChatMessage;
use Psr\Log\LoggerInterface;

/**
 * Shared engine for translating short menu labels keyed by ID — category
 * names (RouterCategoryTranslator) and tag names (RouterTagTranslator).
 * See RouterProductTranslator's docblock for why this prefers the free
 * groq-llama provider before falling back to AIModelRouter's normal
 * 'complex' routing, and why translate() always runs a second
 * verify-and-fix pass afterwards. Callers supply only their own prompts;
 * the provider chain, the merge and the parsing live here once.
 */
final class RouterLabelTranslator
{
    public function __construct(
        private readonly AIModelRouter     $router,
        private readonly AIProviderFactory $providerFactory,
        private readonly LoggerInterface   $logger,
    ) {}

    /**
     * @param  array<int, string> $names         id → source name
     * @param  string             $translatePrompt  system prompt for the first pass
     *                                              (items: {"id", "name"})
     * @param  string             $verifyPrompt     system prompt for the check pass
     *                                              (items: {"id", "source_name", "translated_name"})
     * @param  string             $payloadLabel     e.g. "Categories" — prefixes the JSON item list
     * @param  array<int, string> $sourceLocales    id → ISO 639-1 code the name is written in,
     *                                              sent as each item's "source_locale"
     * @return array<int, string>                id → translated name
     */
    public function translate(array $names, string $translatePrompt, string $verifyPrompt, string $payloadLabel, int $maxTokens = 1024, array $sourceLocales = []): array
    {
        if (empty($names)) {
            return [];
        }

        $items = [];
        foreach ($names as $id => $name) {
            $item = ['id' => (string) $id, 'name' => $name];
            if (isset($sourceLocales[$id])) {
                $item['source_locale'] = $sourceLocales[$id];
            }
            $items[] = $item;
        }
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);

        $content = $this->complete($translatePrompt, "{$payloadLabel}: {$itemsJson}", $maxTokens);
        if ($content === null) {
            return [];
        }

        $translated = $this->parse($content);
        if (empty($translated)) {
            return [];
        }

        return $this->verifyAndFix($translated, $names, $verifyPrompt, $payloadLabel, $maxTokens, $sourceLocales);
    }

    /**
     * Same reasoning as RouterProductTranslator::verifyAndFix() — shows the
     * model its own translation next to the source and asks it to catch
     * fabricated/garbled/merged words. Keeps the first-pass result if this
     * second pass can't run at all.
     *
     * Its answer is MERGED into the first pass, never substituted for it
     * (same fix as the dish translator): a label the check omits or blanks
     * keeps its first-pass name, and an id it invents is ignored.
     *
     * @param array<int, string> $translated
     * @param array<int, string> $sourceNames
     * @param array<int, string> $sourceLocales
     * @return array<int, string>
     */
    private function verifyAndFix(array $translated, array $sourceNames, string $verifyPrompt, string $payloadLabel, int $maxTokens, array $sourceLocales): array
    {
        $items = [];
        foreach ($translated as $id => $name) {
            $item = [
                'id'               => (string) $id,
                'source_name'      => $sourceNames[$id] ?? '',
                'translated_name'  => $name,
            ];
            if (isset($sourceLocales[$id])) {
                $item['source_locale'] = $sourceLocales[$id];
            }
            $items[] = $item;
        }
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);

        $content = $this->complete($verifyPrompt, "{$payloadLabel}: {$itemsJson}", $maxTokens);
        if ($content === null) {
            return $translated;
        }

        $merged = $translated;
        foreach ($this->parse($content) as $id => $name) {
            if (isset($merged[$id])) {
                $merged[$id] = $name; // parse() already dropped blank names
            }
        }

        return $merged;
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
