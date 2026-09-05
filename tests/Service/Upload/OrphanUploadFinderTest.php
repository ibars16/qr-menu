<?php

namespace App\Tests\Service\Upload;

use App\Service\Upload\OrphanUploadFinder;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for the disk-vs-DB diff behind app:logos:clean-orphans
 * — no filesystem or DB needed, this only exercises the set comparison.
 */
final class OrphanUploadFinderTest extends TestCase
{
    private OrphanUploadFinder $finder;

    protected function setUp(): void
    {
        $this->finder = new OrphanUploadFinder();
    }

    public function testFileNotReferencedIsAnOrphan(): void
    {
        $orphans = $this->finder->findOrphans(['a.png', 'b.png'], ['b.png']);

        self::assertSame(['a.png'], $orphans);
    }

    public function testAllFilesReferencedYieldsNoOrphans(): void
    {
        $orphans = $this->finder->findOrphans(['a.png', 'b.png'], ['a.png', 'b.png']);

        self::assertSame([], $orphans);
    }

    public function testEmptyDiskYieldsNoOrphansRegardlessOfReferences(): void
    {
        self::assertSame([], $this->finder->findOrphans([], ['a.png']));
    }

    public function testEmptyReferencedSetMakesEveryDiskFileAnOrphan(): void
    {
        $orphans = $this->finder->findOrphans(['a.png', 'b.png'], []);

        self::assertSame(['a.png', 'b.png'], $orphans);
    }

    public function testFilenameWithPathTraversalIsNeverTreatedAsAnOrphan(): void
    {
        $orphans = $this->finder->findOrphans(['../../etc/passwd', 'a.png'], []);

        self::assertSame(['a.png'], $orphans);
    }

    public function testFilenameWithPathSeparatorIsNeverTreatedAsAnOrphan(): void
    {
        $orphans = $this->finder->findOrphans(['subdir/a.png', 'a.png'], []);

        self::assertSame(['a.png'], $orphans);
    }
}
