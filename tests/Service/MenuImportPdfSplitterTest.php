<?php

namespace App\Tests\Service;

use App\Service\MenuImportPdfError;
use App\Service\MenuImportPdfException;
use App\Service\MenuImportPdfSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MenuImportPdfSplitter against real poppler (pdfinfo/pdftoppm, in the app
 * image) and the small PDFs in tests/fixtures/pdf/. No AI involved anywhere.
 */
final class MenuImportPdfSplitterTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/pdf';

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/pdf-splitter-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->workDir);
    }

    public function testCountsPages(): void
    {
        $splitter = new MenuImportPdfSplitter();

        self::assertSame(1, $splitter->countPages(self::FIXTURES . '/one-page.pdf'));
        self::assertSame(3, $splitter->countPages(self::FIXTURES . '/three-pages.pdf'));
    }

    /** @return iterable<string, array{string, MenuImportPdfError}> */
    public static function badPdfs(): iterable
    {
        yield 'password' => ['password-protected.pdf', MenuImportPdfError::PasswordProtected];
        yield 'broken' => ['broken.pdf', MenuImportPdfError::Unreadable];
        yield 'zero pages' => ['zero-pages.pdf', MenuImportPdfError::NoPages];
    }

    #[DataProvider('badPdfs')]
    public function testBadPdfIsReportedWithItsReason(string $fixture, MenuImportPdfError $expected): void
    {
        try {
            (new MenuImportPdfSplitter())->countPages(self::FIXTURES . '/' . $fixture);
            self::fail('Expected a MenuImportPdfException');
        } catch (MenuImportPdfException $e) {
            self::assertSame($expected, $e->error);
        }
    }

    public function testRendersOneJpegPerPageInOrderCappedAt2000px(): void
    {
        $paths = (new MenuImportPdfSplitter())->renderPages(self::FIXTURES . '/three-pages.pdf', 3, $this->workDir);

        self::assertCount(3, $paths);
        foreach ($paths as $i => $path) {
            self::assertStringEndsWith('-' . ($i + 1) . '.jpg', $path);
            self::assertSame('image/jpeg', mime_content_type($path));
            [$width, $height] = getimagesize($path);
            self::assertSame(MenuImportPdfSplitter::LONG_SIDE_PX, max($width, $height));
        }
    }

    public function testRendersOnlyThePagesAskedFor(): void
    {
        $paths = (new MenuImportPdfSplitter())->renderPages(self::FIXTURES . '/three-pages.pdf', 2, $this->workDir);

        self::assertCount(2, $paths);
        self::assertCount(2, glob($this->workDir . '/*.jpg'));
    }

    public function testRenderingABrokenPdfFails(): void
    {
        $this->expectExceptionObject(new MenuImportPdfException(MenuImportPdfError::Unreadable));

        (new MenuImportPdfSplitter())->renderPages(self::FIXTURES . '/broken.pdf', 1, $this->workDir);
    }

    public function testExtractsEachPagesOwnText(): void
    {
        $texts = (new MenuImportPdfSplitter())->extractPageTexts(self::FIXTURES . '/three-pages.pdf', 3);

        self::assertCount(3, $texts);
        foreach ($texts as $i => $text) {
            self::assertStringStartsWith('Carta - pagina ' . ($i + 1), (string) $text);
            self::assertStringContainsString('Croquetas 9,50', (string) $text);
            self::assertStringNotContainsString("\f", (string) $text);
        }
    }

    public function testExtractsOnlyThePagesAskedFor(): void
    {
        $texts = (new MenuImportPdfSplitter())->extractPageTexts(self::FIXTURES . '/three-pages.pdf', 2);

        self::assertCount(2, $texts);
        self::assertStringStartsWith('Carta - pagina 2', (string) $texts[1]);
    }

    public function testTextNeverFailsTheImportOnlyComesBackEmpty(): void
    {
        self::assertSame([null], (new MenuImportPdfSplitter())->extractPageTexts(self::FIXTURES . '/broken.pdf', 1));

        $slowTool = $this->workDir . '/slow-text.sh';
        file_put_contents($slowTool, "#!/bin/sh\nsleep 5\n");
        chmod($slowTool, 0755);
        $splitter = new MenuImportPdfSplitter(pdftotextBinary: $slowTool, textTimeoutSeconds: 0.5);

        $started = microtime(true);
        self::assertSame([null, null], $splitter->extractPageTexts(self::FIXTURES . '/three-pages.pdf', 2));
        self::assertLessThan(4, microtime(true) - $started, 'killed at the timeout, not waited for');
    }

    public function testAHangingToolIsKilledAndReportedAsUnreadable(): void
    {
        $slowTool = $this->workDir . '/slow-tool.sh';
        file_put_contents($slowTool, "#!/bin/sh\nsleep 5\n");
        chmod($slowTool, 0755);
        $splitter = new MenuImportPdfSplitter($slowTool, $slowTool, 0.5, 0.5);

        $started = microtime(true);
        foreach (['count' => fn () => $splitter->countPages(self::FIXTURES . '/one-page.pdf'),
                  'render' => fn () => $splitter->renderPages(self::FIXTURES . '/one-page.pdf', 1, $this->workDir)] as $step => $call) {
            try {
                $call();
                self::fail("Expected the {$step} step to time out");
            } catch (MenuImportPdfException $e) {
                self::assertSame(MenuImportPdfError::Unreadable, $e->error);
            }
        }
        self::assertLessThan(4, microtime(true) - $started, 'the tool must be killed at the timeout, not waited for');
    }
}
