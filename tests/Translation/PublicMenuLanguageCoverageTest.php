<?php

namespace App\Tests\Translation;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every language a customer can pick on the public menu
 * (config/languages.php) has all of the menu's fixed texts: every
 * menu_public key the English file has (75 today), the name of every
 * allergen (14: config/allergens.yaml, and the rows AllergenSeeder writes
 * from it) and of every preset tag (9: config/preset_tags.yaml). Adding a
 * language without them fails here. (The admin panel's 6 locales,
 * config/admin_locales.php, are a separate list.)
 */
final class PublicMenuLanguageCoverageTest extends KernelTestCase
{
    private static function projectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, array{0: string}> */
    public static function languages(): array
    {
        $languages = require self::projectDir() . '/config/languages.php';

        return array_combine(array_keys($languages), array_map(static fn (string $code) => [$code], array_keys($languages)));
    }

    /** @return array<string, string> dotted key => text */
    private static function menuPublic(string $locale): array
    {
        $file = self::projectDir() . "/translations/menu_public.{$locale}.yaml";
        self::assertFileExists($file);

        $flat = [];
        $walk = static function (array $node, string $prefix) use (&$walk, &$flat): void {
            foreach ($node as $key => $value) {
                is_array($value) ? $walk($value, $prefix . $key . '.') : $flat[$prefix . $key] = (string) $value;
            }
        };
        $walk(Yaml::parseFile($file) ?? [], '');

        return $flat;
    }

    public function testTheListsHaveTheExpectedSize(): void
    {
        self::assertCount(20, self::languages());
        self::assertCount(75, self::menuPublic('en'));
        self::assertCount(14, Yaml::parseFile(self::projectDir() . '/config/allergens.yaml')['allergens']);
        self::assertCount(9, Yaml::parseFile(self::projectDir() . '/config/preset_tags.yaml')['preset_tags']);
    }

    #[DataProvider('languages')]
    public function testEveryMenuPublicKeyIsTranslated(string $locale): void
    {
        $english = self::menuPublic('en');
        $texts = self::menuPublic($locale);

        self::assertSame([], array_values(array_diff(array_keys($english), array_keys($texts))), "missing in menu_public.{$locale}");
        self::assertSame([], array_values(array_diff(array_keys($texts), array_keys($english))), "not in English (a stale key?) in menu_public.{$locale}");
        self::assertSame([], array_keys(array_filter($texts, static fn (string $t) => trim($t) === '')), "blank in menu_public.{$locale}");
    }

    #[DataProvider('languages')]
    public function testEveryAllergenHasItsName(string $locale): void
    {
        foreach (Yaml::parseFile(self::projectDir() . '/config/allergens.yaml')['allergens'] as $allergen) {
            self::assertNotSame('', trim((string) ($allergen['translations'][$locale] ?? '')), "allergen {$allergen['code']} has no {$locale} name");
        }

        // What the menu reads: the rows the seeder wrote (tests/bootstrap.php seeds the test database).
        self::bootKernel();
        $rows = (int) static::getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM allergen_translation WHERE locale = ? AND TRIM(name) <> ''",
            [$locale],
        );
        self::assertSame(14, $rows, "allergen_translation rows in {$locale}");
    }

    #[DataProvider('languages')]
    public function testEveryPresetTagHasItsName(string $locale): void
    {
        foreach (Yaml::parseFile(self::projectDir() . '/config/preset_tags.yaml')['preset_tags'] as $tag) {
            self::assertNotSame('', trim((string) ($tag['translations'][$locale] ?? '')), "preset tag {$tag['code']} has no {$locale} name");
        }
    }
}
