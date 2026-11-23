<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The product's name lives only in config/services.yaml (app.brand_name):
 * the old name must not come back anywhere in the code, templates, texts,
 * config, deploy notes or tests. Normal phrases ("menú QR", "QR menu",
 * "QR-Menü", "código QR") are fine: only the old name as written is caught.
 * Skips third-party and generated folders, uploads and .claude/ (other
 * sessions' worktrees).
 */
final class BrandNameGuardTest extends TestCase
{
    /** Built in two halves so this file doesn't match itself. */
    private const OLD_NAME = 'QR' . ' Menu';

    private const DIRS = ['src', 'templates', 'translations', 'config', 'deploy', 'tests', 'public', 'assets', 'migrations', 'bin'];
    private const FILES = ['.env.example', 'README.md', 'compose.yaml', 'composer.json'];
    private const SKIP = ['public/uploads', 'public/vendor', 'public/fonts'];
    private const EXTENSIONS = ['php', 'twig', 'yaml', 'yml', 'xlf', 'md', 'js', 'css', 'svg', 'json', 'txt', 'xml', 'html', 'webmanifest'];

    public function testOldNameIsGone(): void
    {
        $root = dirname(__DIR__);
        $hits = [];

        foreach ($this->files($root) as $relative) {
            foreach (file($root . '/' . $relative) as $i => $line) {
                if (str_contains($line, self::OLD_NAME)) {
                    $hits[] = sprintf('%s:%d', $relative, $i + 1);
                }
            }
        }

        self::assertSame([], $hits, sprintf('"%s" is back; use brand_name / %%brand%% / app.brand_name instead.', self::OLD_NAME));
    }

    /** @return iterable<string> paths relative to the project root */
    private function files(string $root): iterable
    {
        foreach (self::FILES as $file) {
            if (is_file($root . '/' . $file)) {
                yield $file;
            }
        }

        foreach (self::DIRS as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                foreach (self::SKIP as $skip) {
                    if (str_starts_with($relative, $skip . '/')) {
                        continue 2;
                    }
                }
                if ($file->isFile() && in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                    yield $relative;
                }
            }
        }
    }
}
