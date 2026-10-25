<?php

namespace App\Tests\Twig;

use App\Twig\ImageSizeExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ImageSizeExtensionTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/image_size_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/heroes', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/heroes/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir . '/heroes');
        rmdir($this->dir);
    }

    private function extension(): ImageSizeExtension
    {
        return new ImageSizeExtension(new ArrayAdapter(), $this->dir);
    }

    public function testReadsTheRealSizeRelativeToTheUploadsDir(): void
    {
        copy(self::FIXTURES . '/upload_hero_ok.jpg', $this->dir . '/heroes/a.jpg');

        self::assertSame(['width' => 800, 'height' => 400], $this->extension()->imageSize('heroes/a.jpg'));
    }

    public function testMissingFileOrNonImageIsNull(): void
    {
        file_put_contents($this->dir . '/heroes/not-an-image.jpg', 'plain text');

        self::assertNull($this->extension()->imageSize('heroes/missing.jpg'));
        self::assertNull($this->extension()->imageSize('heroes/not-an-image.jpg'));
        self::assertNull($this->extension()->imageSize(''));
    }

    public function testCannotLeaveTheUploadsDir(): void
    {
        copy(self::FIXTURES . '/upload_hero_ok.jpg', $this->dir . '/outside.jpg');

        try {
            self::assertNull($this->extension()->imageSize('heroes/../outside.jpg'));
        } finally {
            unlink($this->dir . '/outside.jpg');
        }
    }

    public function testAReplacedFileIsReadAgainNotServedFromCache(): void
    {
        $extension = $this->extension();
        $file = $this->dir . '/heroes/a.jpg';
        copy(self::FIXTURES . '/upload_hero_ok.jpg', $file);
        self::assertSame(800, $extension->imageSize('heroes/a.jpg')['width']);

        copy(self::FIXTURES . '/upload_hero_wide.jpg', $file);
        touch($file, time() + 10); // a new mtime, as a real replacement would have

        self::assertSame(['width' => 1600, 'height' => 800], $extension->imageSize('heroes/a.jpg'));
    }
}
