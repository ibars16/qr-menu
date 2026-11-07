<?php

namespace App\Service;

use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderException;
use App\Service\AI\AIProviderFactory;
use App\Service\AI\AIRequest;
use App\Service\AI\AIResponse;
use App\Service\AI\ChatMessage;
use Psr\Log\LoggerInterface;

/**
 * One small call: same chain as RouterProductTranslator (free groq-llama
 * first, then AIModelRouter's 'complex' routing), a cut-off or unreadable
 * answer moving on to the next option (see ask()). Every option failing is
 * null — the caller then translates (never assumes).
 */
final class RouterMenuLanguageDetector implements MenuLanguageDetectorInterface
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You identify the language of restaurant menu texts (dish and section names).
        Reply with JSON only: {"language": "<code>"} where <code> is the ISO 639-1
        code nearly all the texts are written in, or "mixed" when a large part of
        them is written in another language. Proper names, brand names and words
        used the same way in many languages (e.g. "pizza", "risotto", "sushi")
        don't count.
        PROMPT;

    public function __construct(
        private readonly AIModelRouter     $router,
        private readonly AIProviderFactory $providerFactory,
        private readonly LoggerInterface   $logger,
    ) {}

    private const EACH_PROMPT = <<<'PROMPT'
        You identify the language of each restaurant menu text (dish, section and
        label names), one by one. The input is a JSON object {"<key>": "<text>"}.
        Reply with JSON only, the same keys: {"<key>": "<code>"} where <code> is
        the ISO 639-1 code the text is written in, or "unsure" when it can't be
        told: proper names, brand names, or words written the same in several
        languages (e.g. "Pizza margarita", "Carpaccios", "Tiramisú").
        PROMPT;

    /** Room for gpt-oss's reasoning (it counts in maxTokens, even at reasoning_effort low — set for groq-llama in ai_providers.yaml). */
    private const DETECT_MAX_TOKENS = 300;
    private const EACH_BASE_TOKENS  = 300;
    private const EACH_TOKENS_PER_TEXT = 20;

    public function detect(array $texts): ?string
    {
        $texts = array_values(array_filter(array_map('trim', $texts), static fn (string $t) => $t !== ''));
        if ($texts === []) {
            return null;
        }

        $answer = $this->ask('detect', self::SYSTEM_PROMPT, json_encode($texts, JSON_UNESCAPED_UNICODE), self::DETECT_MAX_TOKENS,
            static function (array $decoded): ?array {
                $language = strtolower(trim((string) ($decoded['language'] ?? '')));

                return $language === self::MIXED || preg_match('/^[a-z]{2}$/', $language) ? ['language' => $language] : null;
            });

        return $answer['language'] ?? null;
    }

    public function detectEach(array $texts): ?array
    {
        if ($texts === []) {
            return [];
        }

        $keys = array_map('strval', array_keys($texts));

        return $this->ask('detectEach', self::EACH_PROMPT, json_encode((object) $texts, JSON_UNESCAPED_UNICODE), self::EACH_BASE_TOKENS + self::EACH_TOKENS_PER_TEXT * count($texts),
            static function (array $decoded) use ($keys): ?array {
                // Not one of the keys asked for: not an answer to this question.
                if (array_intersect($keys, array_map('strval', array_keys($decoded))) === []) {
                    return null;
                }
                $result = [];
                foreach ($keys as $key) {
                    $language = strtolower(trim((string) ($decoded[$key] ?? '')));
                    $result[$key] = preg_match('/^[a-z]{2}$/', $language) ? $language : null;
                }

                return $result;
            });
    }

    /**
     * Free groq-llama first, then AIModelRouter's 'complex' chain. An answer
     * that didn't end normally (AIResponse::endedNormally(): cut off at
     * maxTokens, or stopped for any other reason, however the provider
     * says so) or that isn't the JSON asked for counts as a failure: logged (reason only, never the texts)
     * and the next option is tried.
     *
     * @param  callable(array): ?array $read  the decoded JSON → the answer, or null when it isn't one
     * @return array|null  null once every option failed
     */
    private function ask(string $call, string $systemPrompt, string $userContent, int $maxTokens, callable $read): ?array
    {
        $request = new AIRequest(
            systemPrompt: $systemPrompt,
            messages: [new ChatMessage('user', $userContent)],
            maxTokens: $maxTokens,
            temperature: 0.0,
        );

        $freeProvider = $this->providerFactory->getProviderById('groq-llama');
        if ($freeProvider !== null) {
            try {
                $answer = $this->usable($freeProvider->complete($request), $read, $call);
                if ($answer !== null) {
                    return $answer;
                }
            } catch (AIProviderException $e) {
                $this->logger->warning('Menu language detection: groq-llama failed, trying AIModelRouter.', [
                    'call'   => $call,
                    'reason' => $e->reason->value,
                ]);
            }
        }

        $result = $this->router->route($request, 'complex');
        if (!$result->isSuccess()) {
            $this->logger->warning('Menu language detection: no provider answered.', ['call' => $call]);

            return null;
        }

        return $this->usable($result->response, $read, $call);
    }

    private function usable(AIResponse $response, callable $read, string $call): ?array
    {
        $reason = null;
        if (!$response->endedNormally()) {
            $reason = sprintf('cut off (finish_reason=%s)', $response->finishReason);
        } else {
            $decoded = $this->decode($response->content);
            $answer  = $decoded !== null ? $read($decoded) : null;
            if ($answer === null) {
                $reason = $decoded === null ? 'not valid JSON' : 'JSON not in the expected shape';
            }
        }

        if ($reason !== null) {
            $this->logger->warning('Menu language detection: unusable answer.', [
                'call'             => $call,
                'provider'         => $response->providerId,
                'reason'           => $reason,
                'completionTokens' => $response->completionTokens,
            ]);

            return null;
        }

        return $answer;
    }

    private function decode(string $content): ?array
    {
        $decoded = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content)), true);

        return is_array($decoded) ? $decoded : null;
    }
}
