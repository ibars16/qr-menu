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
 * Doesn't flush.
 */
final class ProductImageReplacer
{
    public function __construct(
        #[Autowire('%app.uploads_dir%')] private readonly string $uploadsDir,
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
        $this->dropClip($product, $dir);

        $product->setImage($newFilename);
        $product->setImageSource($imageSource);
        $product->getCategory()->getRestaurant()->bumpMenuContentVersion();
    }

    public function remove(Product $product): void
    {
        $dir = $this->productsDir();

        $oldImage = $product->getImage();
        if ($oldImage && is_file($dir . '/' . $oldImage)) {
            unlink($dir . '/' . $oldImage);
        }
        $this->dropClip($product, $dir);

        $product->setImage(null);
        $product->setImageSource(null);
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
