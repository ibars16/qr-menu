<?php

namespace App\Tests\Entity;

use App\Entity\Product;
use PHPUnit\Framework\TestCase;

/**
 * Product::$videoFocusX/Y — the owner's framing of a dish clip inside its
 * fixed box, rendered as CSS object-position. Null means centred, so every
 * clip saved before this field existed renders exactly as it did.
 */
final class ProductVideoFocusTest extends TestCase
{
    public function testDefaultsToCentred(): void
    {
        $product = new Product();

        self::assertNull($product->getVideoFocusX());
        self::assertNull($product->getVideoFocusY());
        self::assertSame('50% 50%', $product->getVideoObjectPosition());
    }

    public function testStoresFramingAsObjectPosition(): void
    {
        $product = new Product();
        $product->setVideoFocus(20, 75);

        self::assertSame('20% 75%', $product->getVideoObjectPosition());
    }

    public function testClampsOutOfRangeValues(): void
    {
        $product = new Product();
        $product->setVideoFocus(-30, 180);

        self::assertSame(0, $product->getVideoFocusX());
        self::assertSame(100, $product->getVideoFocusY());
    }

    public function testNullResetsToCentred(): void
    {
        $product = new Product();
        $product->setVideoFocus(10, 90);
        $product->setVideoFocus(null, null);

        self::assertSame('50% 50%', $product->getVideoObjectPosition());
    }

    public function testZoomIsClampedAndNullMeansFullCover(): void
    {
        $product = new Product();
        self::assertNull($product->getVideoZoom());

        $product->setVideoZoom(140);
        self::assertSame(100, $product->getVideoZoom());
        $product->setVideoZoom(-5);
        self::assertSame(0, $product->getVideoZoom());
    }

    public function testAspectRatioNeedsBothDimensions(): void
    {
        $product = new Product();
        self::assertNull($product->getVideoAspectRatio(), 'unknown dimensions fall back to plain cover in the carta');

        $product->setVideoDimensions(1080, 1920);
        self::assertSame(0.5625, $product->getVideoAspectRatio());

        $product->setVideoDimensions(1080, null);
        self::assertNull($product->getVideoAspectRatio());
    }
}
