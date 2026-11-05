<?php

namespace App\Tests\Service;

use App\Entity\ProductTagTranslation;
use App\Entity\ProductTranslation;
use App\Service\TranslationSource;
use PHPUnit\Framework\TestCase;

/**
 * TranslationSource::pick(): 1) the owner's text in the main language,
 * 2) else the owner's latest (highest id) in another language, 3) else the
 * main language's AI one; then a preset, then any. Never the target
 * language, never a blank name.
 */
final class TranslationSourceTest extends TestCase
{
    private static function row(int $id, string $locale, string $name, string $source, string $class = ProductTranslation::class): object
    {
        $row = new $class();
        $row->setLocale($locale);
        $row->setName($name);
        $row->setSource($source);
        (new \ReflectionProperty($class, 'id'))->setValue($row, $id);

        return $row;
    }

    private static function pick(array $rows, string $main = 'en', string $target = 'fr'): ?string
    {
        return TranslationSource::pick($rows, $main, $target)?->getName();
    }

    public function testOwnersTextInTheMainLanguageFirst(): void
    {
        self::assertSame('Seafood paella', self::pick([
            self::row(1, 'es', 'Paella', 'human'),
            self::row(9, 'it', 'Paella di mare', 'human'),
            self::row(5, 'en', 'Seafood paella', 'human'),
        ]));
    }

    public function testElseTheOwnersLatestInAnotherLanguage(): void
    {
        self::assertSame('Paella di mare', self::pick([
            self::row(5, 'en', 'Paella (AI)', 'ai'),
            self::row(1, 'es', 'Paella', 'human'),
            self::row(9, 'it', 'Paella di mare', 'human'),
        ]));
        self::assertSame('Paella', self::pick([
            self::row(5, 'en', 'Paella (AI)', 'ai'),
            self::row(1, 'es', 'Paella', 'human'),
        ]), 'over the main language\'s AI text');
    }

    public function testElseTheMainLanguagesAiText(): void
    {
        self::assertSame('Paella (AI)', self::pick([
            self::row(3, 'de', 'Paella (KI)', 'ai'),
            self::row(5, 'en', 'Paella (AI)', 'ai'),
        ]));
        self::assertSame('Vegan', self::pick([
            self::row(3, 'de', 'Vegan (KI)', 'ai', ProductTagTranslation::class),
            self::row(4, 'it', 'Vegan', 'preset', ProductTagTranslation::class),
        ]), 'then a preset');
    }

    public function testNeverTheTargetOrABlankName(): void
    {
        self::assertSame('Paella (AI)', self::pick([
            self::row(9, 'fr', 'Paëlla', 'human'),
            self::row(8, 'es', '  ', 'human'),
            self::row(5, 'en', 'Paella (AI)', 'ai'),
        ]));
        self::assertNull(self::pick([self::row(9, 'fr', 'Paëlla', 'human')]));
    }
}
