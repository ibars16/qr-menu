<?php

namespace App\Tests\Service;

use App\Service\CustomerNeeds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * SmartWaiterStaffNote: the fixed closing sentence about the staff, written
 * by our code from translations/menu_public.*.yaml (the real files, all 20
 * languages) and config/allergens.yaml's names — no AI involved.
 */
final class SmartWaiterStaffNoteTest extends TestCase
{
    use SmartWaiterFakeAiTrait;

    private const STAFF_KEYS = ['confirm', 'none_confirmed', 'none_confirmed_allergy', 'and'];

    private const CONFIRM_ES = 'Antes de pedir, conviene confirmarlo con el personal.';

    /** @return list<string> config/languages.php's codes */
    private static function languages(): array
    {
        return array_keys(require dirname(__DIR__, 2) . '/config/languages.php');
    }

    /** @return iterable<string, array{0: string}> */
    public static function everyLanguage(): iterable
    {
        foreach (self::languages() as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('everyLanguage')]
    public function testEveryPublicMenuLanguageHasTheFourKeys(string $language): void
    {
        $staff = Yaml::parseFile(dirname(__DIR__, 2) . "/translations/menu_public.{$language}.yaml")['chat']['staff'] ?? null;

        self::assertIsArray($staff, $language);
        self::assertSame(self::STAFF_KEYS, array_keys($staff), $language);
        foreach ($staff as $key => $text) {
            self::assertIsString($text);
            self::assertNotSame('', trim($text), "{$language}.{$key}");
        }
        self::assertStringContainsString('%allergens%', $staff['none_confirmed'], $language);
        self::assertStringNotContainsString('%', $staff['confirm'] . $staff['none_confirmed_allergy'], $language);
    }

    #[DataProvider('everyLanguage')]
    public function testEveryLanguageGivesAWholeSentenceWithTheAllergensNamed(string $language): void
    {
        $note = $this->staffNote()->note(new CustomerNeeds(['gluten', 'milk', 'nuts'], [], true), false, $language);

        self::assertStringNotContainsString('%', $note, $language);
        self::assertStringNotContainsString('chat.staff', $note, $language . ': never the bare key');
        $names = Yaml::parseFile(dirname(__DIR__, 2) . '/config/allergens.yaml')['allergens'];
        foreach ($names as $allergen) {
            if (in_array($allergen['code'], ['gluten', 'milk', 'nuts'], true)) {
                self::assertStringContainsStringIgnoringCase($allergen['translations'][$language], $note, $language . ': ' . $allergen['code']);
            }
        }
    }

    public function testEveryAllergenHasANameInEveryLanguage(): void
    {
        $names = [];
        foreach (Yaml::parseFile(dirname(__DIR__, 2) . '/config/allergens.yaml')['allergens'] as $allergen) {
            $names[$allergen['code']] = $allergen['translations'];
        }
        foreach (CustomerNeeds::ALLERGENS as $code) {
            foreach (self::languages() as $language) {
                self::assertNotEmpty($names[$code][$language] ?? null, "{$code}/{$language}");
            }
        }
    }

    public function testSomeDishSuitable(): void
    {
        self::assertSame(self::CONFIRM_ES, $this->staffNote()->note(new CustomerNeeds(['gluten'], [], true), true, 'es'));
    }

    public function testNoDishSuitable(): void
    {
        self::assertSame(
            'Ningún plato de nuestra carta está confirmado sin gluten. ' . self::CONFIRM_ES,
            $this->staffNote()->note(new CustomerNeeds(['gluten'], [], true), false, 'es'),
        );
    }

    /** Spanish and French join them with "ni": the sentence is negative ("sin gluten ni leche"). */
    public function testSeveralAllergensJoinedInThatLanguage(): void
    {
        $note = $this->staffNote();

        self::assertStringStartsWith('Ningún plato de nuestra carta está confirmado sin gluten ni leche.', $note->note(new CustomerNeeds(['gluten', 'milk'], [], true), false, 'es'));
        self::assertStringStartsWith('Ningún plato de nuestra carta está confirmado sin gluten, huevos ni frutos secos.', $note->note(new CustomerNeeds(['gluten', 'eggs', 'nuts'], [], true), false, 'es'));
        self::assertStringStartsWith('Aucun plat de notre carte n\'est confirmé sans gluten ni lait.', $note->note(new CustomerNeeds(['gluten', 'milk'], [], true), false, 'fr'), 'negative: "ni" in French too');
        self::assertStringStartsWith('No dish on our menu is confirmed free of gluten and milk.', $note->note(new CustomerNeeds(['gluten', 'milk'], [], true), false, 'en'));
    }

    public function testNoSpaceAfterAChineseOrJapaneseFullStop(): void
    {
        $note = $this->staffNote();

        self::assertSame('我们菜单上没有任何菜品被确认不含麸质和牛奶。点餐前，建议向工作人员确认。', $note->note(new CustomerNeeds(['gluten', 'milk'], [], true), false, 'zh'));
        self::assertStringNotContainsString('。 ', $note->note(new CustomerNeeds(['gluten'], [], true), false, 'ja'));
    }

    public function testGermanNounsKeepTheirCapital(): void
    {
        self::assertStringStartsWith(
            'Für kein Gericht auf unserer Karte ist bestätigt, dass es ohne Gluten und Milch zubereitet wird.',
            $this->staffNote()->note(new CustomerNeeds(['gluten', 'milk'], [], true), false, 'de'),
        );
    }

    public function testTurkishLowerCaseIsTurkish(): void
    {
        self::assertStringContainsString('kabuklu deniz ürünleri', $this->staffNote()->note(new CustomerNeeds(['crustaceans'], [], true), false, 'tr'));
    }

    public function testAnAllergyWithoutANameableAllergen(): void
    {
        self::assertSame(
            'Ningún plato de nuestra carta está confirmado para esta alergia. ' . self::CONFIRM_ES,
            $this->staffNote()->note(new CustomerNeeds([], [], true, true), false, 'es'),
        );
    }

    public function testNoAllergyNoSentence(): void
    {
        $note = $this->staffNote();

        foreach ([new CustomerNeeds(), new CustomerNeeds([], ['vegan']), new CustomerNeeds(['gluten']), new CustomerNeeds([], [], false, true)] as $needs) {
            self::assertNull($note->note($needs, false, 'es'));
        }
        self::assertSame('Hola.', $note->append('Hola.', null));
    }

    public function testAParagraphOfItsOwnAtTheEnd(): void
    {
        self::assertSame("No tenemos vino tinto.\n\n" . self::CONFIRM_ES, $this->staffNote()->append('No tenemos vino tinto.', self::CONFIRM_ES));
    }

    public function testTheDetectedLanguageWinsWhenItIsAPublicMenuOne(): void
    {
        $note = $this->staffNote();

        self::assertSame('en', $note->language('en', 'es'));
        self::assertSame('es', $note->language(null, 'es'), 'missing: the page\'s');
        self::assertSame('es', $note->language('xx', 'es'), 'not one of the 20: the page\'s');
        self::assertSame('ja', $note->language('ja', 'es'));
    }
}
