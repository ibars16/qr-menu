<?php

namespace App\Tests\Service;

use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderFactory;
use App\Service\RouterCategoryTranslator;
use App\Service\RouterLabelTranslator;
use App\Service\RouterProductTranslator;
use App\Service\RouterTagTranslator;
use App\Service\TranslationGuidelines;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The translation rules (TranslationGuidelines) reach every AI the same
 * way: Groq, OpenAI and Gemini get the very same instructions — translate
 * and review, for dishes, categories and tags — ask for the same JSON and
 * have their answers read the same. The dish review also gets the original
 * description ("source_description"), so it can check the translated one.
 *
 * Real provider factory and router (config/ai_providers.yaml) against a
 * MockHttpClient answering in each provider's wire format; only the
 * provider under test has a (fake) key — no request can reach a real API.
 */
final class TranslationPromptRulesTest extends TestCase
{
    /** provider id => [key env, host] */
    private const PROVIDERS = [
        'groq-llama'        => ['GROQ_API_KEY', 'api.groq.com'],
        'openai-mini'       => ['OPENAI_API_KEY', 'api.openai.com'],
        'gemini-flash-lite' => ['GEMINI_API_KEY', 'generativelanguage.googleapis.com'],
    ];
    private const KEYS = ['GROQ_API_KEY', 'GEMINI_API_KEY', 'OPENAI_API_KEY', 'MISTRAL_API_KEY', 'DEEPSEEK_API_KEY', 'OPENROUTER_API_KEY'];

    private const DISHES = [
        7 => ['name' => 'Pata de pulpo braseada', 'description' => 'Con patata y pimentón', 'section' => 'Pescados'],
        8 => ['name' => 'Magnum (almendrado o frac)', 'description' => null],
    ];

    private array $previousEnv = [];
    /** @var list<array{host: string, system: string, user: string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $env) {
            $this->previousEnv[$env] = $_ENV[$env] ?? null;
            $_ENV[$env] = '';
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $env => $value) {
            if ($value === null) {
                unset($_ENV[$env]);
            } else {
                $_ENV[$env] = $value;
            }
        }
    }

    public static function providers(): iterable
    {
        foreach (array_keys(self::PROVIDERS) as $id) {
            yield $id => [$id];
        }
    }

    /** @return array{0: AIModelRouter, 1: AIProviderFactory} only $provider configured */
    private function chain(string $provider): array
    {
        $_ENV[self::PROVIDERS[$provider][0]] = 'test-key-never-sent';
        $http = new MockHttpClient(fn (string $method, string $url, array $options) => $this->answer($url, $options));
        $factory = new AIProviderFactory($http, dirname(__DIR__, 2));

        return [new AIModelRouter($factory, new NullLogger()), $factory];
    }

    /** "[fr] <name>" for a translation; a review returns its candidate unchanged. */
    private function answer(string $url, array $options): MockResponse
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $body = json_decode((string) $options['body'], true);
        $gemini = $host === 'generativelanguage.googleapis.com';
        $system = $gemini ? $body['systemInstruction']['parts'][0]['text'] : $body['messages'][0]['content'];
        $user = $gemini ? $body['contents'][0]['parts'][0]['text'] : end($body['messages'])['content'];
        $this->requests[] = ['host' => $host, 'system' => $system, 'user' => $user];

        [$label, $json] = explode(': ', $user, 2);
        $result = [];
        foreach (json_decode($json, true) as $item) {
            $name = $item['translated_name'] ?? ('[fr] ' . $item['name']);
            $result[$item['id']] = $label === 'Dishes'
                ? ['name' => $name, 'description' => $item['translated_description'] ?? (($item['description'] ?? '') !== '' ? '[fr] ' . $item['description'] : '')]
                : $name;
        }
        $content = json_encode($result, JSON_UNESCAPED_UNICODE);

        return $gemini
            ? new MockResponse(json_encode(['candidates' => [['content' => ['parts' => [['text' => $content]], 'role' => 'model'], 'finishReason' => 'STOP']]]))
            : new MockResponse(json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']]]));
    }

    /** @return array{prompts: list<string>, users: list<string>, result: array} dishes, then categories, then tags */
    private function translateWith(string $provider): array
    {
        [$router, $factory] = $this->chain($provider);
        $labels = new RouterLabelTranslator($router, $factory, new NullLogger());

        $result = [
            'dishes'     => (new RouterProductTranslator($router, $factory, new NullLogger()))->translate(self::DISHES, 'fr', [7 => 'es', 8 => 'es']),
            'categories' => (new RouterCategoryTranslator($labels))->translate([3 => 'Asados'], 'fr', [3 => 'es']),
            'tags'       => (new RouterTagTranslator($labels))->translate([4 => 'Casero'], 'fr', [4 => 'es']),
        ];
        self::assertSame(array_fill(0, 6, self::PROVIDERS[$provider][1]), array_column($this->requests, 'host'), 'translate + review, three times, all to that provider');

        return ['prompts' => array_column($this->requests, 'system'), 'users' => array_column($this->requests, 'user'), 'result' => $result];
    }

    #[DataProvider('providers')]
    public function testEveryPromptCarriesTheRules(string $provider): void
    {
        [$dish, $dishReview, $category, $categoryReview, $tag, $tagReview] = $this->translateWith($provider)['prompts'];

        self::assertStringContainsString(TranslationGuidelines::DISH, $dish);
        self::assertStringContainsString(TranslationGuidelines::DISH_REVIEW, $dishReview);
        foreach ([$category, $tag] as $prompt) {
            self::assertStringContainsString(TranslationGuidelines::LABEL, $prompt);
        }
        foreach ([$categoryReview, $tagReview] as $prompt) {
            self::assertStringContainsString(TranslationGuidelines::LABEL_REVIEW, $prompt);
        }
        foreach (['1. Meaning first', '3. Cuts and parts', '4. Fixed expressions', '6. Keep exactly as written', '7. A local dish with no equivalent', '9. The same source wording'] as $rule) {
            self::assertStringContainsString($rule, $dish);
        }
        foreach ([$dish, $dishReview, $category, $categoryReview, $tag, $tagReview] as $prompt) {
            self::assertStringNotContainsString('Ternasco', $prompt, 'no example a model would copy');
            // Same JSON asked of every AI.
            self::assertStringContainsString('Return ONLY a valid JSON object mapping each "id" to', $prompt);
        }
        self::assertStringNotContainsString('proper nouns', $dish, 'replaced by rule 6');
        // Rule 6: only names are kept; ordinary food words are always translated.
        foreach ([$dish, $category, $tag] as $prompt) {
            self::assertStringContainsString('Keep exactly as written ONLY', $prompt);
            self::assertStringContainsString('ingredients, cheeses, sauces, cuts, cooking methods', $prompt);
            self::assertStringContainsString('always translated by their meaning, even when written in a regional or dialect form', $prompt);
            self::assertStringContainsString('Never replace a word with a guess', $prompt);
        }
        foreach ([$dish, $dishReview, $category, $categoryReview, $tag, $tagReview] as $prompt) {
            self::assertStringNotContainsString('most likely a name', $prompt, 'unknown words are no longer kept as names');
            self::assertStringNotContainsString('words you don\'t recognise', $prompt);
            self::assertStringNotContainsString('unknown word', $prompt);
        }
        foreach ([$dishReview, $categoryReview, $tagReview] as $prompt) {
            self::assertStringContainsString('an ordinary food word (an ingredient, cheese, sauce, cut or cooking method) left untranslated', $prompt);
        }
    }

    #[DataProvider('providers')]
    public function testTheDishReviewGetsTheOriginalDescription(string $provider): void
    {
        $review = $this->translateWith($provider)['users'][1];
        $items = json_decode(explode(': ', $review, 2)[1], true);

        self::assertSame('Con patata y pimentón', $items[0]['source_description']);
        self::assertSame('Pata de pulpo braseada', $items[0]['source_name']);
        self::assertSame('Pescados', $items[0]['menu_section']);
        self::assertSame('', $items[1]['source_description'], 'no description: empty, still sent');
        self::assertStringContainsString('"source_description"', $this->requests[1]['system']);
    }

    /** Groq, OpenAI and Gemini: the same instructions, the same items sent, the same result read. */
    public function testTheThreeProvidersGetTheSameInstructionsAndGiveTheSameResult(): void
    {
        $runs = [];
        foreach (array_keys(self::PROVIDERS) as $provider) {
            $this->requests = [];
            foreach (self::KEYS as $env) {
                $_ENV[$env] = '';
            }
            $runs[$provider] = $this->translateWith($provider);
        }

        [$groq, $openai, $gemini] = array_values($runs);
        self::assertSame($groq['prompts'], $openai['prompts']);
        self::assertSame($groq['prompts'], $gemini['prompts']);
        self::assertSame($groq['users'], $openai['users']);
        self::assertSame($groq['users'], $gemini['users']);
        self::assertSame($groq['result'], $openai['result']);
        self::assertSame($groq['result'], $gemini['result']);
        self::assertSame([
            7 => ['name' => '[fr] Pata de pulpo braseada', 'description' => '[fr] Con patata y pimentón'],
            8 => ['name' => '[fr] Magnum (almendrado o frac)', 'description' => null],
        ], $groq['result']['dishes']);
    }
}
