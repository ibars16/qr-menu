<?php

namespace App\Service\TranslationEval;

use App\Service\AI\AIProviderException;
use App\Service\AI\AIProviderInterface;
use App\Service\AI\AIResponse;
use App\Service\TranslationAiCaller;
use App\Service\TranslationAiCallerInterface;

/**
 * The manual translation eval's caller (app:translation:eval): every call
 * goes to ONE provider, never anywhere else. Where the menu's
 * TranslationAiCaller falls back (Groq → router chain) or keeps going, this
 * throws TranslationEvalStopped — a failure, an exhausted quota, an answer
 * cut off: the eval stops and says why, it never mixes providers.
 *
 * The request is TranslationAiCaller::request(), the menu's own.
 * beforeSend (if set) sees each system prompt before anything is sent —
 * the command checks there that the prompts are still the ones a resumed
 * run started with.
 */
final class PinnedProviderAiCaller implements TranslationAiCallerInterface
{
    private ?AIResponse $lastResponse = null;

    /** @var (\Closure(string): void)|null */
    private ?\Closure $beforeSend = null;

    public function __construct(private readonly AIProviderInterface $provider) {}

    /** @param \Closure(string $systemPrompt): void $beforeSend may throw to stop before the call */
    public function setBeforeSend(\Closure $beforeSend): void
    {
        $this->beforeSend = $beforeSend;
    }

    public function complete(string $systemPrompt, string $userContent, int $maxTokens): array
    {
        if ($this->beforeSend !== null) {
            ($this->beforeSend)($systemPrompt);
        }
        $this->lastResponse = null;
        $id = $this->provider->getId();

        try {
            $response = $this->provider->complete(TranslationAiCaller::request($systemPrompt, $userContent, $maxTokens));
        } catch (AIProviderException $e) {
            throw new TranslationEvalStopped(sprintf('%s falló (%s): %s', $id, $e->reason->value, $e->getMessage()), 0, $e);
        } catch (\Throwable $e) {
            throw new TranslationEvalStopped(sprintf('%s falló (error de transporte): %s', $id, $e->getMessage()), 0, $e);
        }

        if (!$response->endedNormally()) {
            throw new TranslationEvalStopped(sprintf('%s: respuesta cortada o detenida (finish_reason=%s)', $id, $response->finishReason));
        }
        $this->lastResponse = $response;

        return ['content' => $response->content, 'provider' => $response->providerId];
    }

    /** Never used by the eval (no independent review): if it ever is, stop rather than reach another provider. */
    public function completeElsewhere(string $systemPrompt, string $userContent, int $maxTokens, string $notProvider): ?string
    {
        throw new TranslationEvalStopped('La prueba de traducciones no usa revisión por otra IA.');
    }

    /** The provider's answer to the last complete() — its real provider id and model. */
    public function lastResponse(): ?AIResponse
    {
        return $this->lastResponse;
    }
}
