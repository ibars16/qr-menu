<?php

namespace App\Service;

use App\Service\AI\AIFailureReason;
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
 *
 * $freeProviderOnly (the Global Ingredient Library's daily batches — see
 * GlobalIngredientLibraryTranslation) uses groq-llama and nothing else:
 * per-minute 429s are retried after a pause, and any failure that remains
 * — the daily quota above all — is thrown instead of falling back to a
 * paid provider or keeping an unchecked first pass.
 */
final class RouterLabelTranslator
{
    private const FREE_PROVIDER_ID = 'groq-llama';
    private const FREE_PROVIDER_RETRIES = 3;

    private int $freeProviderTokens = 0;

    /** @var callable(int): void */
    private $sleeper;

    private readonly TranslationAiCaller $caller;

    public function __construct(
        AIModelRouter                      $router,
        private readonly AIProviderFactory $providerFactory,
        LoggerInterface                    $logger,
        ?callable                          $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static fn (int $seconds) => sleep($seconds);
        $this->caller  = new TranslationAiCaller($router, $providerFactory, $logger);
    }

    /**
     * @param  array<int, string> $names         id → source name
     * @param  string             $translatePrompt  system prompt for the first pass
     *                                              (items: {"id", "name"})
     * @param  string             $verifyPrompt     system prompt for the check pass
     *                                              (items: {"id", "source_name", "translated_name"})
     * @param  string             $payloadLabel     e.g. "Categories" — prefixes the JSON item list
     * @param  array<int, string> $sourceLocales    id → ISO 639-1 code the name is written in,
     *                                              sent as each item's "source_locale"
     * @param  bool               $freeProviderOnly groq-llama only, both passes or nothing (see class docblock)
     * @param  bool               $independentReview the check pass by another AI than the translator
     *                                              (see IndependentReviewTranslator); ignored with $freeProviderOnly
     * @return array<int, string>                id → translated name
     * @throws AIProviderException               only with $freeProviderOnly
     */
    public function translate(array $names, string $translatePrompt, string $verifyPrompt, string $payloadLabel, int $maxTokens = 1024, array $sourceLocales = [], bool $freeProviderOnly = false, bool $independentReview = false): array
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

        $answer = $freeProviderOnly
            ? ['content' => $this->completeWithFreeProviderOnly($translatePrompt, "{$payloadLabel}: {$itemsJson}", $maxTokens), 'provider' => self::FREE_PROVIDER_ID]
            : $this->caller->complete($translatePrompt, "{$payloadLabel}: {$itemsJson}", $maxTokens);
        if ($answer === null) {
            return [];
        }

        $translated = $this->parse($answer['content']);
        if (empty($translated)) {
            return [];
        }

        $reviewBy = $independentReview && !$freeProviderOnly ? $answer['provider'] : null;

        return $this->verifyAndFix($translated, $names, $verifyPrompt, $payloadLabel, $maxTokens, $sourceLocales, $freeProviderOnly, $reviewBy);
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
    private function verifyAndFix(array $translated, array $sourceNames, string $verifyPrompt, string $payloadLabel, int $maxTokens, array $sourceLocales, bool $freeProviderOnly, ?string $notProvider): array
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

        $content = match (true) {
            $freeProviderOnly     => $this->completeWithFreeProviderOnly($verifyPrompt, "{$payloadLabel}: {$itemsJson}", $maxTokens),
            $notProvider !== null => $this->caller->completeElsewhere($verifyPrompt, "{$payloadLabel}: {$itemsJson}", $maxTokens, $notProvider),
            default               => $this->caller->complete($verifyPrompt, "{$payloadLabel}: {$itemsJson}", $maxTokens)['content'] ?? null,
        };
        if ($content === null) {
            return $translated;
        }

        // Strict mode keeps only what the check actually returned: a name
        // it skipped stays untranslated for the next run, never unchecked.
        $merged = $freeProviderOnly ? [] : $translated;
        foreach ($this->parse($content) as $id => $name) {
            if (isset($translated[$id])) {
                $merged[$id] = $name; // parse() already dropped blank names
            }
        }

        return $merged;
    }

    /** Tokens groq-llama reported for $freeProviderOnly calls so far (both passes). */
    public function getFreeProviderTokens(): int
    {
        return $this->freeProviderTokens;
    }

    /**
     * groq-llama only. Groq's free tier limits tokens per minute as well as
     * per day, and answers both with a 429: a short pause clears the
     * per-minute one, so only a 429 that outlasts every retry (the daily
     * quota) — or any other failure — reaches the caller.
     *
     * @throws AIProviderException
     */
    private function completeWithFreeProviderOnly(string $systemPrompt, string $userContent, int $maxTokens): string
    {
        $provider = $this->providerFactory->getProviderById(self::FREE_PROVIDER_ID)
            ?? throw new AIProviderException(self::FREE_PROVIDER_ID, AIFailureReason::HTTP_ERROR, self::FREE_PROVIDER_ID . ' is not configured (GROQ_API_KEY)');

        $request = new AIRequest(
            systemPrompt: $systemPrompt,
            messages: [new ChatMessage('user', $userContent)],
            maxTokens: $maxTokens,
            temperature: 0.1,
        );

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $provider->complete($request);
                break;
            } catch (AIProviderException $e) {
                if (!in_array($e->reason, [AIFailureReason::RATE_LIMITED, AIFailureReason::TIMEOUT], true) || $attempt >= self::FREE_PROVIDER_RETRIES) {
                    throw $e;
                }
                ($this->sleeper)(20 * ($attempt + 1));
            }
        }

        $this->freeProviderTokens += ($response->promptTokens ?? 0) + ($response->completionTokens ?? 0);

        return $response->content;
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
