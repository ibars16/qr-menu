<?php

namespace App\Twig;

use Symfony\Contracts\Cache\CacheInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * image_size('heroes/<file>') → ['width' => …, 'height' => …] read from the
 * uploaded file itself, or null when it can't be read (missing, not an
 * image) — callers then render exactly as they would without it.
 *
 * getimagesize() only parses the file header (no GD needed, none here) and
 * the result is cached keyed by path + mtime: uploads get a fresh random
 * name, so an entry never goes stale in practice, and a replaced file
 * changes its mtime anyway. EXIF orientation is deliberately ignored — a
 * rotated phone photo just reports its stored (unrotated) size.
 *
 * The path is relative to app.uploads_dir and can't leave it.
 */
class ImageSizeExtension extends AbstractExtension
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $uploadsDir,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('image_size', [$this, 'imageSize']),
        ];
    }

    /** @return array{width: int, height: int}|null */
    public function imageSize(string $path): ?array
    {
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        $file = $this->uploadsDir . '/' . ltrim($path, '/');
        $mtime = @filemtime($file);
        if ($mtime === false) {
            return null;
        }

        return $this->cache->get('image_size.' . md5($file . '|' . $mtime), static function () use ($file): ?array {
            $size = @getimagesize($file);
            if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
                return null;
            }

            return ['width' => $size[0], 'height' => $size[1]];
        });
    }
}
