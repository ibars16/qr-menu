<?php

namespace App\Service\AI;

use App\Service\AI\Adapter\GeminiAdapter;
use App\Service\AI\Adapter\GeminiVisionAdapter;
use App\Service\AI\Adapter\OpenAICompatibleAdapter;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds AIProviderInterface instances from config/ai_providers.yaml —
 * the single source of truth for which providers exist, in what order, and
 * which env var holds each one's key. A provider whose key isn't actually
 * set (empty, or the literal Gemini placeholder already committed to .env)
 * is simply left out rather than being handed to the router to fail on
 * every request.
 *
 * Same "config file read at runtime by a plain service" shape as
 * DefaultTagSeeder / config/preset_tags.yaml.
 */
final class AIProviderFactory
{
    private const PLACEHOLDER_VALUES = ['your-gemini-api-key-here'];

    /** @var array<string, list<array<string, mixed>>>|null */
    private ?array $providersByTier = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $projectDir,
    ) {}

    /** @return array{fast: AIProviderInterface[], capable: AIProviderInterface[]} */
    public function getAvailableProviders(): array
    {
        $byTier = ['fast' => [], 'capable' => []];

        foreach ($this->loadConfig() as $tier => $entries) {
            foreach ($entries as $entry) {
                if (!$entry['enabled']) {
                    continue;
                }
                $apiKey = $this->resolveEnv($entry['api_key_env']);
                if ($apiKey === null || $apiKey === '' || in_array($apiKey, self::PLACEHOLDER_VALUES, true)) {
                    continue; // not configured — silently unavailable, not a failure
                }

                $byTier[$tier][] = $this->build($entry, $apiKey);
            }
        }

        return $byTier;
    }

    /**
     * Same availability logic as getAvailableProviders() — enabled, key
     * actually configured — filtered to entries marked supports_vision,
     * ordered by priority within tier exactly like the chat list (tier
     * itself is irrelevant here; menu-import extraction doesn't route by
     * complexity, there's just one ordered list to try).
     *
     * @return MenuVisionAnalyzer[]
     */
    public function getAvailableVisionProviders(): array
    {
        $providers = [];

        foreach ($this->loadConfig() as $entries) {
            foreach ($entries as $entry) {
                if (!$entry['enabled'] || !($entry['supports_vision'] ?? false)) {
                    continue;
                }
                $apiKey = $this->resolveEnv($entry['api_key_env']);
                if ($apiKey === null || $apiKey === '' || in_array($apiKey, self::PLACEHOLDER_VALUES, true)) {
                    continue;
                }

                $providers[] = $this->buildVision($entry, $apiKey);
            }
        }

        // loadConfig() already sorts each tier's entries by priority, and
        // this loop walks tiers in a fixed order — sufficient ordering for
        // today's single vision provider. Revisit if a second one is ever
        // added to a different tier than "fast".
        return $providers;
    }

    /**
     * USD per 1M tokens for the given provider, as configured in
     * config/ai_providers.yaml — null for either side (or both) whenever
     * that provider hasn't declared pricing, e.g. every free-tier entry
     * today. Used only to compute an estimated cost for analytics; a null
     * here means SmartWaiterExchangeLog stores null rather than a guess.
     *
     * @return array{input: ?float, output: ?float}|null null if the provider id is unknown
     */
    public function getPricing(string $providerId): ?array
    {
        foreach ($this->loadConfig() as $entries) {
            foreach ($entries as $entry) {
                if ($entry['id'] === $providerId) {
                    return [
                        'input' => $entry['cost_per_1m_input'] ?? null,
                        'output' => $entry['cost_per_1m_output'] ?? null,
                    ];
                }
            }
        }

        return null;
    }

    private function build(array $entry, string $apiKey): AIProviderInterface
    {
        return match ($entry['adapter']) {
            'gemini' => new GeminiAdapter($this->httpClient, $entry['id'], $entry['model'], $apiKey),
            'openai_compatible' => new OpenAICompatibleAdapter($this->httpClient, $entry['id'], $entry['model'], $entry['base_url'], $apiKey),
            default => throw new \RuntimeException(sprintf('Unknown AI provider adapter "%s" for provider "%s"', $entry['adapter'], $entry['id'])),
        };
    }

    private function buildVision(array $entry, string $apiKey): MenuVisionAnalyzer
    {
        return match ($entry['adapter']) {
            'gemini' => new GeminiVisionAdapter($this->httpClient, $entry['id'], $entry['model'], $apiKey),
            default => throw new \RuntimeException(sprintf('Adapter "%s" (provider "%s") has supports_vision: true but no vision implementation exists for it yet', $entry['adapter'], $entry['id'])),
        };
    }

    /** @return array{fast: list<array<string, mixed>>, capable: list<array<string, mixed>>} */
    private function loadConfig(): array
    {
        if ($this->providersByTier !== null) {
            return $this->providersByTier;
        }

        $data = Yaml::parseFile($this->projectDir . '/config/ai_providers.yaml');

        $byTier = ['fast' => [], 'capable' => []];
        foreach ($data['providers'] as $entry) {
            $byTier[$entry['tier']][] = $entry;
        }

        foreach ($byTier as $tier => $entries) {
            usort($entries, static fn (array $a, array $b) => $a['priority'] <=> $b['priority']);
            $byTier[$tier] = $entries;
        }

        return $this->providersByTier = $byTier;
    }

    private function resolveEnv(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : null;
    }
}
