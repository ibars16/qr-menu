<?php

namespace App\Service\Upload;

use App\Entity\Product;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Points a dish at a new photo already sitting in uploads/products/ — the
 * one place that does it, for the owner's own upload
 * (MenuAdminController::uploadProductImage()) and an AI photo
 * (App\Service\DishPhoto) alike:
 *
 * - the old photo's file is deleted (orphaned once nothing points to it);
 * - a clip is dropped with its file and framing — a chosen photo always
 *   wins, image and videoClip must never point at unrelated media (see
 *   Product::$videoClip);
 * - imageSource records where the photo came from (null = the owner's own);
 * - the public menu's content version is bumped.
 *
 * remove() is the editor's "Eliminar foto": the dish ends with no media at
 * all (a clip goes too — its poster IS the photo).
 *
 * "Mejorar con IA" (applyEnhanced()) is the one case where the old photo
 * is NOT deleted: the owner's original is moved to app.ai_photo_originals_dir
 * (private), named after the enhanced file — <restaurant>/<image>.<ext of
 * the original> — so "is there an original for this photo?" needs no
 * column: it's whether that file exists. restoreOriginal() puts it back.
 * Any other change of photo (replace(), remove()) deletes it: once the
 * enhanced photo is gone, so is the way back.
 *
 * Doesn't flush.
 */
final class ProductImageReplacer
{
    public function __construct(
        #[Autowire('%app.uploads_dir%')] private readonly string $uploadsDir,
        #[Autowire('%app.ai_photo_originals_dir%')] private readonly string $originalsDir,
    ) {
    }

    public function productsDir(): string
    {
        $dir = $this->uploadsDir . '/products';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    public function replace(Product $product, string $newFilename, ?string $imageSource = null): void
    {
        $dir = $this->productsDir();

        $oldImage = $product->getImage();
        if ($oldImage && $oldImage !== $newFilename && is_file($dir . '/' . $oldImage)) {
            unlink($dir . '/' . $oldImage);
        }
        $this->discardOriginal($product);
        $this->dropClip($product, $dir);

        $this->point($product, $newFilename, $imageSource);
    }

    public function remove(Product $product): void
    {
        $dir = $this->productsDir();

        $oldImage = $product->getImage();
        if ($oldImage && is_file($dir . '/' . $oldImage)) {
            unlink($dir . '/' . $oldImage);
        }
        $this->discardOriginal($product);
        $this->dropClip($product, $dir);

        $this->point($product, null, null);
    }

    /**
     * "Mejorar con IA": $newFilename (the enhanced photo, already in
     * uploads/products) goes on the dish, the photo it was made from is
     * kept as the original — the owner's current photo the first time, or
     * the one already kept aside on "Otra" (the previous enhanced photo is
     * then just deleted).
     */
    public function applyEnhanced(Product $product, string $newFilename): void
    {
        $dir = $this->productsDir();
        $current = (string) $product->getImage();
        $original = $this->originalPath($product);
        $originalsDir = $this->restaurantOriginalsDir($product);

        if ($original !== null) {
            rename($original, $originalsDir . '/' . $newFilename . '.' . pathinfo($original, PATHINFO_EXTENSION));
            if ($current !== '' && is_file($dir . '/' . $current)) {
                unlink($dir . '/' . $current);
            }
        } elseif ($current !== '' && is_file($dir . '/' . $current)) {
            rename($dir . '/' . $current, $originalsDir . '/' . $newFilename . '.' . pathinfo($current, PATHINFO_EXTENSION));
        }
        $this->dropClip($product, $dir);

        $this->point($product, $newFilename, Product::IMAGE_SOURCE_AI_EDIT);
    }

    /**
     * "Volver a la original": the kept original back on the dish (a fresh
     * filename, so no cached copy of the enhanced one can show), the
     * enhanced photo deleted. False when there's none to go back to.
     */
    public function restoreOriginal(Product $product): bool
    {
        $original = $this->originalPath($product);
        if ($original === null) {
            return false;
        }
        $dir = $this->productsDir();
        $filename = bin2hex(random_bytes(16)) . '.' . pathinfo($original, PATHINFO_EXTENSION);
        if (!rename($original, $dir . '/' . $filename)) {
            return false;
        }
        $current = $product->getImage();
        if ($current && is_file($dir . '/' . $current)) {
            unlink($dir . '/' . $current);
        }

        $this->point($product, $filename, null);

        return true;
    }

    /** The owner's original kept for the dish's current (enhanced) photo; null when there's none. */
    public function originalPath(Product $product): ?string
    {
        $image = $product->getImage();
        if (!$image || $image !== basename($image)) {
            return null;
        }
        $restaurantId = (int) $product->getCategory()->getRestaurant()->getId();
        $matches = glob($this->originalsDir . '/' . $restaurantId . '/' . $image . '.*') ?: [];

        return $matches[0] ?? null;
    }

    /** Deletes the kept original (if any) — for a photo change that doesn't go through replace()/remove(), like a clip's poster. */
    public function discardOriginal(Product $product): void
    {
        $original = $this->originalPath($product);
        if ($original !== null) {
            @unlink($original);
        }
    }

    private function restaurantOriginalsDir(Product $product): string
    {
        $dir = $this->originalsDir . '/' . (int) $product->getCategory()->getRestaurant()->getId();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private function point(Product $product, ?string $filename, ?string $imageSource): void
    {
        $product->setImage($filename);
        $product->setImageSource($imageSource);
        $product->getCategory()->getRestaurant()->bumpMenuContentVersion();
    }

    private function dropClip(Product $product, string $dir): void
    {
        $oldClip = $product->getVideoClip();
        if (!$oldClip) {
            return;
        }
        if (is_file($dir . '/' . $oldClip)) {
            unlink($dir . '/' . $oldClip);
        }
        $product->setVideoClip(null);
        $product->setVideoFocus(null, null);
        $product->setVideoZoom(null);
        $product->setVideoDimensions(null, null);
    }
}
