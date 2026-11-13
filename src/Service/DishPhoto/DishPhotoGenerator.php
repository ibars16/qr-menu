<?php

namespace App\Service\DishPhoto;

use App\Entity\Product;
use App\Entity\Restaurant;
use App\Service\Upload\ProductImageReplacer;
use Psr\Log\LoggerInterface;

/**
 * One AI dish photo: quota reserved first (DishPhotoQuota), then the
 * provider, then the file (DishPhotoStorage). Whenever no photo comes out
 * — refused, timed out, provider down, file rejected — the reservation is
 * given back, so only photos actually produced count.
 *
 * "Mejorar con IA" (enhance()) is one more photo for all of that:
 * same quota, same brakes, same messages.
 */
class DishPhotoGenerator
{
    /** Longest side of the owner's photo as sent to "Mejorar con IA" (~150–300 KB as JPEG). */
    public const ENHANCE_INPUT_MAX_SIDE = 1024;

    /** "Mejorar con IA" always comes out like the menu's cards (and generated photos): 4:3, whatever the original's shape. */
    public const ENHANCE_ASPECT_RATIO = '4:3';

    public function __construct(
        private readonly DishImageGeneratorInterface $generator,
        private readonly DishPhotoPromptBuilder $promptBuilder,
        private readonly DishPhotoQuota $quota,
        private readonly DishPhotoStorage $storage,
        private readonly LoggerInterface $logger,
        private readonly DishPhotoAvailability $availability,
        private readonly DishPhotoJpegEncoder $encoder,
        private readonly ProductImageReplacer $replacer,
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
        $prompt = $this->promptBuilder->build($name, $description);

        return $this->generate($restaurant, $dishKey,
            fn () => $this->generator->generate($prompt),
            fn (GeneratedImage $image) => $this->storage->createDraft($restaurant, $image));
    }

    /**
     * The editor's "Mejorar con IA" (always a saved dish): the owner's own
     * photo (Product::hasOwnerPhoto()) — or, on "Otra", the original kept
     * aside by the last one (ProductImageReplacer::applyEnhanced()) —
     * reduced to ENHANCE_INPUT_MAX_SIDE, reframed to ENHANCE_ASPECT_RATIO.
     * Counts like a generated photo, on the same per-dish brake.
     *
     * @return string the new filename in uploads/products — the caller puts
     *                it on the dish with ProductImageReplacer::applyEnhanced()
     *
     * @throws DishPhotoException
     */
    public function enhance(Product $product): string
    {
        $restaurant = $product->getCategory()->getRestaurant();
        $this->assertAvailable();
        $original = $this->replacer->originalPath($product);
        $source = $original ?? $this->replacer->productsDir() . '/' . $product->getImage();
        $eligible = $product->hasOwnerPhoto()
            || ($original !== null && $product->getImageSource() === Product::IMAGE_SOURCE_AI_EDIT && $product->getVideoClip() === null);
        if (!$eligible || !is_file($source)) {
            throw new DishPhotoException(DishPhotoException::REASON_NO_OWNER_PHOTO);
        }

        // Reduced before anything is counted: if that fails, nothing was spent.
        $input = $this->reducedInput($source);
        $prompt = $this->promptBuilder->enhance();

        try {
            return $this->generate($restaurant, (string) $product->getId(),
                fn () => $this->generator->edit($prompt, $input, 'image/jpeg', self::ENHANCE_ASPECT_RATIO),
                fn (GeneratedImage $image) => $this->storage->storeForProduct($image));
        } catch (DishPhotoException $e) {
            // "Change the description" makes no sense here: its own message.
            throw $e->reason === DishImageFailure::Refused->value
                ? new DishPhotoException(DishPhotoException::REASON_ENHANCE_REFUSED, $e)
                : $e;
        }
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
        $prompt = $this->promptBuilder->build($name, $description);

        return $this->generate($product->getCategory()->getRestaurant(), (string) $product->getId(),
            fn () => $this->generator->generate($prompt),
            fn (GeneratedImage $image) => $this->storage->storeForProduct($image));
    }

    /**
     * @param callable(): GeneratedImage       $call  the provider
     * @param callable(GeneratedImage): string $store
     */
    private function generate(Restaurant $restaurant, string $dishKey, callable $call, callable $store): string
    {
        $this->assertAvailable();

        match ($this->quota->reserve($restaurant, $dishKey)) {
            DishPhotoQuotaResult::Reserved => null,
            DishPhotoQuotaResult::TrialLimit => throw new DishPhotoException(DishPhotoException::REASON_TRIAL_LIMIT),
            DishPhotoQuotaResult::CardPending => throw new DishPhotoException(DishPhotoException::REASON_CARD_PENDING),
            DishPhotoQuotaResult::MonthlyLimit => throw new DishPhotoException(DishPhotoException::REASON_MONTHLY_LIMIT),
            DishPhotoQuotaResult::RateLimited => throw new DishPhotoException(DishPhotoException::REASON_RATE_LIMITED),
        };

        try {
            return $store($call());
        } catch (DishImageGenerationException $e) {
            $this->quota->refund($restaurant);
            $this->logger->warning('AI dish photo failed', ['restaurant' => $restaurant->getId(), 'reason' => $e->failure->value, 'message' => $e->getMessage()]);

            throw DishPhotoException::fromFailure($e);
        } catch (\Throwable $e) {
            $this->quota->refund($restaurant);

            throw $e;
        }
    }

    /** No image key: refused before the quota, so nothing is counted. */
    private function assertAvailable(): void
    {
        if (!$this->availability->isAvailable()) {
            throw new DishPhotoException(DishImageFailure::NotConfigured->value);
        }
    }

    /**
     * The owner's photo as a JPEG of at most ENHANCE_INPUT_MAX_SIDE.
     *
     * @throws DishPhotoException unavailable when FFmpeg can't read it
     */
    private function reducedInput(string $source): GeneratedImage
    {
        $target = tempnam(sys_get_temp_dir(), 'dish-enhance-') . '.jpg';
        try {
            $size = $this->encoder->encode($source, $target, self::ENHANCE_INPUT_MAX_SIDE) ? @getimagesize($target) : false;
            if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
                $this->logger->warning('AI dish photo: could not reduce the owner photo', ['source' => basename($source)]);
                throw new DishPhotoException(DishImageFailure::Unavailable->value);
            }

            return new GeneratedImage((string) file_get_contents($target));
        } finally {
            @unlink($target);
            @unlink(substr($target, 0, -4));
        }
    }
}
