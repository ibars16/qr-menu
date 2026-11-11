<?php

namespace App\Tests\Support;

use App\Service\DishPhoto\DishImageFailure;
use App\Service\DishPhoto\DishImageGenerationException;
use App\Service\DishPhoto\DishImageGeneratorInterface;
use App\Service\DishPhoto\GeneratedImage;

/**
 * The only image "provider" tests ever see (aliased in services.yaml's
 * when@test). By default returns a real 1200×896 PNG fixture; queue
 * failures or other images with willFail() / willReturn(). Static state,
 * since WebTestCase clients reboot the kernel between requests — reset()
 * in setUp.
 */
final class FakeDishImageGenerator implements DishImageGeneratorInterface
{
    /** @var array<DishImageFailure|string> next results, in order: a failure, or a fixture path */
    private static array $queue = [];
    /** @var string[] */
    public static array $prompts = [];

    public static function reset(): void
    {
        self::$queue = [];
        self::$prompts = [];
    }

    public static function willFail(DishImageFailure $failure): void
    {
        self::$queue[] = $failure;
    }

    public static function willReturn(string $fixturePath): void
    {
        self::$queue[] = $fixturePath;
    }

    public static function defaultFixture(): string
    {
        return dirname(__DIR__) . '/fixtures/dish_photo/generated_1200x896.png';
    }

    public function generate(string $prompt): GeneratedImage
    {
        self::$prompts[] = $prompt;
        $next = array_shift(self::$queue) ?? self::defaultFixture();
        if ($next instanceof DishImageFailure) {
            throw new DishImageGenerationException($next, 'fake failure: ' . $next->value);
        }

        return new GeneratedImage((string) file_get_contents($next));
    }
}
