<?php

namespace App\Tests\Service;

use App\Service\SmartWaiterReplyCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The chat bubble shows a reply exactly as typed, so SmartWaiterReplyCleaner
 * strips the Markdown a model may add (bold, headings, bullets) without
 * touching the words, and puts back the capitals a model may leave out —
 * and the chat returns and remembers the cleaned text, whichever provider
 * answered (fake AI: SmartWaiterFakeAiTrait).
 */
final class SmartWaiterReplyCleanerTest extends TestCase
{
    use SmartWaiterFakeAiTrait;

    protected function setUp(): void
    {
        $this->fakeAiSetUp();
    }

    protected function tearDown(): void
    {
        $this->fakeAiTearDown();
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function cleaned(): iterable
    {
        yield 'bold' => ['Te recomiendo la **ensalada de quinoa**.', 'Te recomiendo la ensalada de quinoa.'];
        yield 'underscore bold' => ['Es __muy picante__.', 'Es muy picante.'];
        yield 'two bolds in a line' => ['**Pizza** y **pasta**', 'Pizza y pasta'];
        yield 'a "**" left over' => ['La **pizza lleva gluten.', 'La pizza lleva gluten.'];
        yield 'bullets, text kept' => ["Tenemos:\n- Ensalada\n* Sopa\n• Flan", "Tenemos:\nEnsalada\nSopa\nFlan"];
        yield 'headings' => ["## Postres\nTenemos flan.", "Postres\nTenemos flan."];
        yield 'bold inside a bullet' => ['- **Carpaccio**: lleva salmón.', 'Carpaccio: lleva salmón.'];
    }

    #[DataProvider('cleaned')]
    public function testMarkdownIsStripped(string $reply, string $expected): void
    {
        self::assertSame($expected, (new SmartWaiterReplyCleaner())->clean($reply));
    }

    /** @return iterable<string, array{0: string}> */
    public static function untouched(): iterable
    {
        yield 'plain sentence' => ['La carta no indica si lleva gluten. Confírmalo con el personal antes de pedir.'];
        yield 'snake_case' => ['Ver menu_del_dia en la carta.'];
        yield 'multiplication' => ['Son 2*3 raciones.'];
        yield 'a dash inside a sentence' => ['El flan - casero - es de la casa.'];
        yield 'a lone underscore' => ['Pregunta por el plato _del día.'];
        yield 'a hash inside a line' => ['Es el plato #1 de la casa.'];
        yield 'a negative price' => ['-5 € de descuento no hay, lo siento.'];
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function invisible(): iterable
    {
        // The exact ending Groq gave (CSV run of 2026-11-10): a zero-width space after the last full stop.
        yield 'zero-width space at the end' => ["El plato más barato es la piña, a 5,5 €.\u{200B}", 'El plato más barato es la piña, a 5,5 €.'];
        yield 'zero-width space mid-sentence' => ["Tenemos\u{200B} flan.", 'Tenemos flan.'];
        yield 'BOM and word joiner' => ["\u{FEFF}Tenemos\u{2060} flan.", 'Tenemos flan.'];
        yield 'it no longer hides a lower-case letter' => ["Tenemos flan. \u{200B}también natillas.", 'Tenemos flan. También natillas.'];
        yield 'Thai: one before a Latin letter goes' => ["มีพิซซ่า\u{200B} Margherita", 'มีพิซซ่า Margherita'];
    }

    #[DataProvider('invisible')]
    public function testInvisibleCharactersAreDropped(string $reply, string $expected): void
    {
        self::assertSame($expected, (new SmartWaiterReplyCleaner())->clean($reply));
    }

    /** @return iterable<string, array{0: string}> */
    public static function neededInvisible(): iterable
    {
        yield 'Thai word break between Thai letters' => ["เรามีพิซซ่า\u{200B}และพาสต้า"];
        yield 'ZWJ in an emoji family' => ["Para toda la familia \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}."];
        yield 'ZWJ and ZWNJ in Hindi' => ["क्\u{200D}ष और क्\u{200C}ष"];
        yield 'RLM and LRM in Arabic with a price' => ["السعر \u{200F}5,5 €\u{200E} فقط."];
    }

    #[DataProvider('neededInvisible')]
    public function testInvisibleCharactersTheScriptsNeedStay(string $reply): void
    {
        self::assertSame($reply, (new SmartWaiterReplyCleaner())->clean($reply));
    }

    #[DataProvider('untouched')]
    public function testEverythingElseIsLeftAsWritten(string $reply): void
    {
        self::assertSame($reply, (new SmartWaiterReplyCleaner())->clean($reply));
    }

    /** @return iterable<string, array{0: string, 1: string, 2?: string}> */
    public static function capitals(): iterable
    {
        // The exact replies Gemini Flash-Lite gave in lower case (CSV run of 2026-11-10).
        yield 'Lite, start and after a full stop' => ['el menú no dice que lleve pan. recuerda confirmarlo con el personal antes de pedir.', 'El menú no dice que lleve pan. Recuerda confirmarlo con el personal antes de pedir.'];
        yield 'Lite, two sentences' => ['esa pizza no es adecuada para ti, ya que contiene gluten. si te apetece, tenemos otras opciones.', 'Esa pizza no es adecuada para ti, ya que contiene gluten. Si te apetece, tenemos otras opciones.'];
        yield 'after ? and !, past ¿ ¡ «' => ['¿hay pan? sí. ¡claro! «vale»', '¿Hay pan? Sí. ¡Claro! «Vale»'];
        yield 'each line' => ["Hola.
sin gluten no hay", "Hola.
Sin gluten no hay"];
        yield 'not after an abbreviation' => ['Hay sopas, p. ej. la de cebolla, etc. y más.', 'Hay sopas, p. ej. la de cebolla, etc. y más.'];
        yield 'not after a dotted one' => ['Soups, e.g. onion soup.', 'Soups, e.g. onion soup.'];
        yield 'not after "..."' => ['Pues... no sé.', 'Pues... no sé.'];
        yield 'a price' => ['Cuesta 12.50 euros.', 'Cuesta 12.50 euros.'];
        yield 'Turkish dotted İ' => ['ilk öneri bu. iyi seçim.', 'İlk öneri bu. İyi seçim.', 'tr'];
        yield 'Turkish ı' => ['ızgara balık var.', 'Izgara balık var.', 'tr'];
        yield 'no capitals in Japanese' => ['ご注文の前に。スタッフに', 'ご注文の前に。スタッフに', 'ja'];
        yield 'German nouns stay' => ['das Gericht enthält Gluten. bitte fragen.', 'Das Gericht enthält Gluten. Bitte fragen.', 'de'];
    }

    #[DataProvider('capitals')]
    public function testCapitalsArePutBackNeverTakenAway(string $reply, string $expected, ?string $language = null): void
    {
        self::assertSame($expected, (new SmartWaiterReplyCleaner())->clean($reply, $language));
    }

    public function testNothingIsEverTurnedToLowerCase(): void
    {
        $reply = 'El Menú No Dice. Confírmalo CON el personal, como en iPhone, McDonald y DJ.';

        self::assertSame($reply, (new SmartWaiterReplyCleaner())->clean($reply));
    }

    public function testTheChatReturnsAndRemembersTheCleanedTextFromGeminiGroqAndOpenAi(): void
    {
        foreach ([self::GROQ => 'stop', self::GEMINI => 'STOP', self::OPENAI => 'stop'] as $host => $finish) {
            $this->requests = [];
            $this->logs = [];
            // Only this provider is scripted: every other one gets a 500 and is skipped.
            $this->replies = [$host => [['Te recomiendo la **ensalada**:' . "\n- sin pan", $finish], ['Vale.', $finish]]];
            $service = $this->service();

            $first = $service->reply($this->restaurant(), 'es', 'EUR', null, '¿Qué me recomiendas?');
            self::assertSame("Te recomiendo la ensalada:\nSin pan", $first['reply'], $host);

            $service->reply($this->restaurant(), 'es', 'EUR', $first['conversationId'], '¿Y de postre?');
            $last = end($this->requests);
            self::assertSame($host, $last['host']);
            self::assertSame("Te recomiendo la ensalada:\nSin pan", $this->sentTurns($last)[1][1], $host . ': the history keeps the cleaned text');
        }
    }
}
