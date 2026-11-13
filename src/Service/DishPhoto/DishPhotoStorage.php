<?php

namespace App\Service\DishPhoto;

use App\Entity\Restaurant;
use App\Service\Upload\ProductImageReplacer;
use App\Service\Upload\UploadProfile;
use App\Service\Upload\UploadValidator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Where a generated photo lives, from the provider's bytes to the dish:
 *
 * 1. prepare(): converted to a light JPEG (DishPhotoJpegEncoder; the
 *    original is kept if FFmpeg fails), then run through the very same
 *    UploadValidator profile as an owner's upload (real type, weight,
 *    400–5000px, moderation) and given its random safe filename.
 * 2. The editor: kept as a DRAFT under var/ (never public), per
 *    restaurant, until the owner saves the dish (promoteDraft()). Closing
 *    without saving leaves the dish untouched; drafts older than a day
 *    are deleted on the restaurant's next generation.
 * 3. The batch after an import and "Mejorar con IA": straight into
 *    uploads/products (storeForProduct()) — the batch's dishes have no
 *    photo to protect, and an enhanced photo keeps its original aside
 *    (ProductImageReplacer::applyEnhanced()).
 *
 * A file that doesn't pass the validator throws Refused: for the owner
 * it's the same as the AI not making a photo.
 */
class DishPhotoStorage
{
    private const DRAFT_MAX_AGE_SECONDS = 86400;
    private const TOKEN_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(
        private readonly DishPhotoJpegEncoder $encoder,
        private readonly UploadValidator $validator,
        private readonly ProductImageReplacer $replacer,
        #[Autowire('%app.ai_photo_drafts_dir%')] private readonly string $draftsDir,
    ) {
    }

    /** @return string the draft token */
    public function createDraft(Restaurant $restaurant, GeneratedImage $image): string
    {
        $dir = $this->restaurantDraftsDir($restaurant);
        $this->deleteOldDrafts($dir);

        [$path, $filename] = $this->prepare($image, $dir);
        $token = bin2hex(random_bytes(16));
        rename($path, $dir . '/' . $token . '.' . pathinfo($filename, PATHINFO_EXTENSION));

        return $token;
    }

    /** The draft's file, only for its own restaurant; null when unknown, expired or someone else's. */
    public function draftPath(Restaurant $restaurant, string $token): ?string
    {
        if (!preg_match(self::TOKEN_PATTERN, $token)) {
            return null;
        }
        $matches = glob($this->draftsDir . '/' . (int) $restaurant->getId() . '/' . $token . '.*') ?: [];

        return $matches[0] ?? null;
    }

    /** Moves the draft into uploads/products; returns its new filename, null if the draft is gone. */
    public function promoteDraft(Restaurant $restaurant, string $token): ?string
    {
        $path = $this->draftPath($restaurant, $token);
        if ($path === null) {
            return null;
        }
        $filename = bin2hex(random_bytes(16)) . '.' . pathinfo($path, PATHINFO_EXTENSION);
        if (!rename($path, $this->replacer->productsDir() . '/' . $filename)) {
            return null;
        }

        return $filename;
    }

    /** @return string the new filename in uploads/products */
    public function storeForProduct(GeneratedImage $image): string
    {
        $dir = $this->replacer->productsDir();
        [$path, $filename] = $this->prepare($image, $dir);
        rename($path, $dir . '/' . $filename);

        return $filename;
    }

    /**
     * @return array{0: string, 1: string} a validated temp file inside $dir and its safe filename
     *
     * @throws DishImageGenerationException Refused when the image doesn't pass the dish-photo validator
     */
    private function prepare(GeneratedImage $image, string $dir): array
    {
        $raw = $dir . '/.raw-' . bin2hex(random_bytes(8));
        file_put_contents($raw, $image->bytes);
        $jpeg = $raw . '.jpg';
        $path = $this->encoder->encode($raw, $jpeg) ? $jpeg : $raw;
        if ($path === $jpeg) {
            unlink($raw);
        }

        $result = $this->validator->validate(new UploadedFile($path, basename($path), null, null, true), UploadProfile::DishImage);
        if (!$result->isValid) {
            @unlink($path);
            throw new DishImageGenerationException(DishImageFailure::Refused, 'Generated image rejected by the validator: ' . $result->error?->name);
        }

        return [$path, $result->safeFilename];
    }

    private function restaurantDraftsDir(Restaurant $restaurant): string
    {
        $dir = $this->draftsDir . '/' . (int) $restaurant->getId();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private function deleteOldDrafts(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - self::DRAFT_MAX_AGE_SECONDS) {
                @unlink($file);
            }
        }
    }
}
