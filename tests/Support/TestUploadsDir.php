<?php

namespace App\Tests\Support;

/**
 * For WebTestCases whose requests store files: app.uploads_dir in the test
 * env (config/services.yaml, when@test → var/test-uploads). Refuses to run
 * if it ever resolves under public/, so a misconfigured parameter can never
 * make a test write into (or empty) the real uploads folder.
 */
trait TestUploadsDir
{
    /** The test uploads folder, with each given subfolder created (scandir() needs it to exist). */
    private static function testUploadsDir(string ...$subdirs): string
    {
        $dir = static::getContainer()->getParameter('app.uploads_dir');
        $public = static::getContainer()->getParameter('kernel.project_dir') . '/public';
        if (str_starts_with($dir, $public)) {
            throw new \LogicException("app.uploads_dir points under public/ in the test env ({$dir}); refusing to use it.");
        }

        foreach ($subdirs as $subdir) {
            if (!is_dir($dir . '/' . $subdir)) {
                mkdir($dir . '/' . $subdir, 0755, true);
            }
        }

        return $dir;
    }

    /** Deletes everything inside the test uploads folder (the folder itself stays). */
    private static function emptyTestUploadsDir(): void
    {
        $dir = self::testUploadsDir();
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
    }
}
