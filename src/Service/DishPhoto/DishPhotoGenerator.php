<?php

namespace App\Service\DishPhoto;

use App\Entity\Product;
use App\Entity\Restaurant;
use Psr\Log\LoggerInterface;

/**
 * One AI dish photo: quota reserved first (DishPhotoQuota), then the
 * provider, then the file (DishPhotoStorage). Whenever no photo comes out
 * — refused, timed out, provider down, file rejected — the reservation is
 * given back, so only photos actually produced count.
 */
class DishPhotoGenerator
{
    public function __construct(
        private readonly DishImageGeneratorInterface $generator,
        private readonly DishPhotoPromptBuilder $promptBuilder,
        private readonly DishPhotoQuota $quota,
        private readonly DishPhotoStorage $storage,
        private readonly LoggerInterface $logger,
        private readonly DishPhotoAvailability $availability,
    ) {
    }

    /**
     * The editor's "Generar con IA": a draft the dish only gets on save.
     * $productId null for a dish not created yet.
     *
     * @return string the draft token
     *
     * @throws DishPhotoException
     */
    public function draft(Restaurant $restaurant, string $name, ?string $description, ?int $productId): string
    {
        $dishKey = $productId !== null ? (string) $productId : 'new:' . md5(mb_strtolower(trim($name)));

        return $this->generate($restaurant, $name, $description, $dishKey,
            fn (GeneratedImage $image) => $this->storage->createDraft($restaurant, $image));
    }

    /**
     * The batch after an import: straight into uploads/products.
     *
     * @return string the new filename (not yet set on the product)
     *
     * @throws DishPhotoException
     */
    public function forProduct(Product $product, string $name, ?string $description): string
    {
        return $this->generate($product->getCategory()->getRestaurant(), $name, $description, (string) $product->getId(),
            fn (GeneratedImage $image) => $this->storage->storeForProduct($image));
    }

    /** @param callable(GeneratedImage): string $store */
    private function generate(Restaurant $restaurant, string $name, ?string $description, string $dishKey, callable $store): string
    {
        // No image key: refused before the quota, so nothing is counted.
        if (!$this->availability->isAvailable()) {
            throw new DishPhotoException(DishImageFailure::NotConfigured->value);
        }

        match ($this->quota->reserve($restaurant, $dishKey)) {
            DishPhotoQuotaResult::Reserved => null,
            DishPhotoQuotaResult::TrialLimit => throw new DishPhotoException(DishPhotoException::REASON_TRIAL_LIMIT),
            DishPhotoQuotaResult::CardPending => throw new DishPhotoException(DishPhotoException::REASON_CARD_PENDING),
            DishPhotoQuotaResult::MonthlyLimit => throw new DishPhotoException(DishPhotoException::REASON_MONTHLY_LIMIT),
            DishPhotoQuotaResult::RateLimited => throw new DishPhotoException(DishPhotoException::REASON_RATE_LIMITED),
        };

        try {
            return $store($this->generator->generate($this->promptBuilder->build($name, $description)));
        } catch (DishImageGenerationException $e) {
            $this->quota->refund($restaurant);
            $this->logger->warning('AI dish photo failed', ['restaurant' => $restaurant->getId(), 'reason' => $e->failure->value, 'message' => $e->getMessage()]);

            throw DishPhotoException::fromFailure($e);
        } catch (\Throwable $e) {
            $this->quota->refund($restaurant);

            throw $e;
        }
    }
}
