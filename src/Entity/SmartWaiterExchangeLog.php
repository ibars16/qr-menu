<?php

namespace App\Entity;

use App\Service\AI\AIFailureReason;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per chat exchange (one customer message + one reply) — never the
 * message content itself. This is the "aggregated analytics" the platform
 * keeps instead of conversation transcripts: enough to see conversation
 * volume, language mix, provider health, latency, and token/cost usage per
 * restaurant, nothing a customer typed or the assistant said.
 *
 * `conversationId` groups exchanges belonging to the same chat session (the
 * same opaque token SmartWaiterConversationStore uses for the cache) so
 * "number of conversations" can be counted as distinct ids rather than rows
 * — it is not a foreign key, since the conversation itself is never
 * persisted anywhere.
 */
#[ORM\Entity]
class SmartWaiterExchangeLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Restaurant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Restaurant $restaurant;

    #[ORM\Column(length: 64)]
    private string $conversationId;

    #[ORM\Column(length: 10)]
    private string $locale;

    #[ORM\Column(length: 10)]
    private string $complexity;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $providerId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $model = null;

    #[ORM\Column]
    private bool $success = false;

    #[ORM\Column(length: 20, nullable: true, enumType: AIFailureReason::class)]
    private ?AIFailureReason $failureReason = null;

    #[ORM\Column]
    private int $attemptsCount = 0;

    #[ORM\Column(nullable: true)]
    private ?int $latencyMs = null;

    #[ORM\Column(nullable: true)]
    private ?int $promptTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $completionTokens = null;

    /** USD. Null (not 0) whenever the winning provider has no pricing configured in ai_providers.yaml — never estimated by guessing. */
    #[ORM\Column(nullable: true)]
    private ?float $estimatedCostUsd = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Restaurant $restaurant, string $conversationId, string $locale, string $complexity)
    {
        $this->restaurant = $restaurant;
        $this->conversationId = $conversationId;
        $this->locale = $locale;
        $this->complexity = $complexity;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function recordSuccess(
        string $providerId,
        string $model,
        int $attemptsCount,
        int $latencyMs,
        ?int $promptTokens,
        ?int $completionTokens,
        ?float $estimatedCostUsd,
    ): void {
        $this->success = true;
        $this->providerId = $providerId;
        $this->model = $model;
        $this->attemptsCount = $attemptsCount;
        $this->latencyMs = $latencyMs;
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;
        $this->estimatedCostUsd = $estimatedCostUsd;
    }

    public function recordFailure(int $attemptsCount, ?AIFailureReason $lastReason): void
    {
        $this->success = false;
        $this->attemptsCount = $attemptsCount;
        $this->failureReason = $lastReason;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRestaurant(): Restaurant
    {
        return $this->restaurant;
    }

    public function getConversationId(): string
    {
        return $this->conversationId;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getComplexity(): string
    {
        return $this->complexity;
    }

    public function getProviderId(): ?string
    {
        return $this->providerId;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getFailureReason(): ?AIFailureReason
    {
        return $this->failureReason;
    }

    public function getAttemptsCount(): int
    {
        return $this->attemptsCount;
    }

    public function getLatencyMs(): ?int
    {
        return $this->latencyMs;
    }

    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }

    public function getEstimatedCostUsd(): ?float
    {
        return $this->estimatedCostUsd;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
