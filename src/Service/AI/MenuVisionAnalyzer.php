<?php

namespace App\Service\AI;

/**
 * A vision-capable AI backend that turns a photo of a menu page into
 * structured JSON. Deliberately a separate interface from
 * AIProviderInterface, not an extension of it: this has no conversation
 * history, no system-prompt-plus-messages shape, and a completely different
 * failure/latency profile from a live customer chat reply — forcing it
 * through the chat interface would mean bending that interface's shape to
 * fit a request kind it was never designed for.
 *
 * New providers are added the same way as the chat system: an entry in
 * config/ai_providers.yaml (with supports_vision: true) plus an
 * AIProviderFactory::getAvailableVisionProviders() entry for the adapter
 * type — nothing about this interface changes to add one.
 */
interface MenuVisionAnalyzer
{
    /** The `id` from config/ai_providers.yaml. */
    public function getId(): string;

    public function getModel(): string;

    /**
     * @param string $imageBytes Raw file content — the caller reads it from disk, this never touches the filesystem itself.
     * @param string $instructions The full extraction prompt (see MenuVisionPromptBuilder) — this class has no prompt-content knowledge of its own.
     * @throws AIProviderException on any failure — never returns a partial or fabricated result.
     */
    public function analyzeMenuPage(string $imageBytes, string $mimeType, string $instructions): MenuVisionResult;
}
