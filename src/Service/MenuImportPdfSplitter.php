<?php

namespace App\Service;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * "Importar carta con IA" with a PDF: the PDF never reaches the AI. Each of
 * its pages becomes a JPEG here (poppler's pdfinfo + pdftoppm, installed in
 * the app image next to ffmpeg — production needs poppler-utils too), and
 * each JPEG then follows exactly the photo path: 1 image = 1 page = 1 call,
 * same prompt. Its text layer (pdftotext, same package) is kept per page
 * for MenuImportAssembler's checks — see extractPageTexts().
 *
 * Safety against a trap PDF: both tools run with a hard timeout (the
 * process is killed), only the pages that fit are rendered (-f/-l), and
 * the long side is capped at 2000 px whatever the page's declared size, so
 * a "2-metre poster" page can't become a gigantic bitmap.
 */
class MenuImportPdfSplitter
{
    /** Long side of each page image: an A4 ≈ 1414×2000 (~170 dpi), plenty for the AI to read. */
    public const LONG_SIDE_PX = 2000;
    public const JPEG_QUALITY = 85;
    /** Longest text kept per page — a real menu page is a few thousand characters. */
    public const MAX_TEXT_CHARS = 20000;

    public function __construct(
        private readonly string $pdfinfoBinary = 'pdfinfo',
        private readonly string $pdftoppmBinary = 'pdftoppm',
        private readonly float $countTimeoutSeconds = 10,
        private readonly float $renderTimeoutSeconds = 60,
        private readonly string $pdftotextBinary = 'pdftotext',
        private readonly float $textTimeoutSeconds = 20,
    ) {
    }

    /**
     * The PDF's own text, page by page (poppler's pdftotext -layout), for
     * MenuImportAssembler only: it checks against it what the AI read
     * (MenuImportPage::$sourceText). Never sent to the AI.
     *
     * Never fails the import: a scanned page (no text layer) is null, and
     * if the tool fails or hangs every page is null — the import then
     * works exactly as for a photo.
     *
     * @return list<?string> one entry per page, in page order ($pageCount entries)
     */
    public function extractPageTexts(string $pdfPath, int $pageCount): array
    {
        $none = array_fill(0, $pageCount, null);

        $process = new Process([
            $this->pdftotextBinary,
            '-layout', '-enc', 'UTF-8',
            '-f', '1', '-l', (string) $pageCount,
            $pdfPath, '-',
        ]);
        $process->setTimeout($this->textTimeoutSeconds);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return $none;
        }
        if (!$process->isSuccessful()) {
            return $none;
        }

        // pdftotext ends every page with a form feed.
        $chunks = explode("\f", $process->getOutput());
        $texts = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $text = trim((string) ($chunks[$i] ?? ''));
            if ($text === '' || !mb_check_encoding($text, 'UTF-8')) {
                $texts[] = null;
                continue;
            }
            $texts[] = mb_substr($text, 0, self::MAX_TEXT_CHARS);
        }

        return $texts;
    }

    /** @throws MenuImportPdfException */
    public function countPages(string $pdfPath): int
    {
        $process = new Process([$this->pdfinfoBinary, $pdfPath]);
        $process->setTimeout($this->countTimeoutSeconds);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new MenuImportPdfException(MenuImportPdfError::Unreadable);
        }

        $stderr = $process->getErrorOutput();
        if (!$process->isSuccessful()) {
            if (stripos($stderr, 'password') !== false) {
                throw new MenuImportPdfException(MenuImportPdfError::PasswordProtected);
            }
            if (stripos($stderr, 'page count 0') !== false) {
                throw new MenuImportPdfException(MenuImportPdfError::NoPages);
            }
            throw new MenuImportPdfException(MenuImportPdfError::Unreadable);
        }

        if (!preg_match('/^Pages:\s+(\d+)\s*$/m', $process->getOutput(), $m)) {
            throw new MenuImportPdfException(MenuImportPdfError::Unreadable);
        }
        $pages = (int) $m[1];
        if ($pages === 0) {
            throw new MenuImportPdfException(MenuImportPdfError::NoPages);
        }

        return $pages;
    }

    /**
     * Renders pages 1..$pageCount into $workDir as JPEGs.
     *
     * @return list<string> the JPEG paths, in page order
     *
     * @throws MenuImportPdfException
     */
    public function renderPages(string $pdfPath, int $pageCount, string $workDir): array
    {
        $prefix = $workDir . '/page';
        $process = new Process([
            $this->pdftoppmBinary,
            '-jpeg', '-jpegopt', 'quality=' . self::JPEG_QUALITY,
            '-scale-to', (string) self::LONG_SIDE_PX,
            '-f', '1', '-l', (string) $pageCount,
            $pdfPath, $prefix,
        ]);
        $process->setTimeout($this->renderTimeoutSeconds);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new MenuImportPdfException(MenuImportPdfError::Unreadable);
        }
        if (!$process->isSuccessful()) {
            throw new MenuImportPdfException(MenuImportPdfError::Unreadable);
        }

        // pdftoppm zero-pads the page number to the page count's width
        // (page-1.jpg, page-01.jpg…): order by the number, not the name.
        $paths = [];
        foreach (glob($prefix . '-*.jpg') ?: [] as $path) {
            if (preg_match('/-(\d+)\.jpg$/', $path, $m)) {
                $paths[(int) $m[1]] = $path;
            }
        }
        ksort($paths);
        $paths = array_values(array_filter($paths, static fn (string $p) => filesize($p) > 0));

        if (count($paths) !== $pageCount) {
            throw new MenuImportPdfException(MenuImportPdfError::Unreadable);
        }

        return $paths;
    }
}
