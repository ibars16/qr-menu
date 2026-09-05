<?php

namespace App\Service\Upload;

/**
 * Pure set-diff between what's on disk in an uploads folder and what's
 * actually referenced in the database — no filesystem or DB access here on
 * purpose, so the logic that decides "orphan or not" can be unit tested
 * without a kernel. Callers (e.g. a cleanup command) own reading the
 * directory and the DB, and own the actual unlink().
 */
final class OrphanUploadFinder
{
    /**
     * @param list<string> $diskFilenames    filenames present in the upload directory (basenames only)
     * @param list<string> $referencedNames  filenames referenced by the DB (nulls/blanks already excluded by the caller)
     *
     * @return list<string> filenames present on disk but not referenced
     */
    public function findOrphans(array $diskFilenames, array $referencedNames): array
    {
        $referenced = array_flip($referencedNames);

        $orphans = [];
        foreach ($diskFilenames as $filename) {
            // Defense in depth: entries from a real directory listing can't
            // actually contain a path separator or "..", but never treat a
            // filename as safe to act on without checking.
            if ($filename === '' || $filename !== basename($filename) || str_contains($filename, '..')) {
                continue;
            }

            if (!isset($referenced[$filename])) {
                $orphans[] = $filename;
            }
        }

        return $orphans;
    }
}
