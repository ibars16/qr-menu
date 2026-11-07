<?php

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Every public-menu template translates its 'menu_public' texts into the
 * menu's language: |trans({...}, 'menu_public', locale), never just
 * |trans({...}, 'menu_public'). Without the locale the text falls back to
 * the request's locale — the app's default English on /r/… — whatever
 * language the menu is shown in (the bug the language/currency window had).
 */
final class MenuTemplatesPassTheLocaleTest extends TestCase
{
    /** @return list<string> */
    private static function templates(): array
    {
        $dir = dirname(__DIR__, 2) . '/templates/menu';
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.twig')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    public function testEveryMenuPublicTranslationPassesTheLocale(): void
    {
        $offenders = [];
        foreach (self::templates() as $file) {
            // Twig comments don't render: a mention there is fine.
            $source = preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($file));
            if (preg_match_all('/([\'"])menu_public\1\s*\)/', $source, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [, $offset]) {
                    $offenders[] = sprintf('%s:%d', substr($file, strlen(dirname(__DIR__, 2)) + 1), substr_count(substr($source, 0, $offset), "\n") + 1);
                }
            }
            if (preg_match('/\{%-?\s*trans_default_domain\b/', $source)) {
                $offenders[] = substr($file, strlen(dirname(__DIR__, 2)) + 1) . ': trans_default_domain (pass the domain and the locale on each call)';
            }
        }

        self::assertSame([], $offenders, "'menu_public' translated without the menu's locale");
    }

    /** The check itself catches the mistake. */
    public function testTheCheckSpotsACallWithoutTheLocale(): void
    {
        self::assertSame(1, preg_match('/([\'"])menu_public\1\s*\)/', "{{ 'dialog.title'|trans({}, 'menu_public') }}"));
        self::assertSame(0, preg_match('/([\'"])menu_public\1\s*\)/', "{{ 'dialog.title'|trans({}, 'menu_public', locale) }}"));
        self::assertNotEmpty(self::templates());
    }
}
