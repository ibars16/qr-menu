<?php

namespace App\Tests\Service;

use App\Service\SmartWaiterDishRefs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The DISHES line (SmartWaiterPromptBuilder rule 11) never shows, however
 * the AI writes it; only ids that were sent survive, at most three.
 */
final class SmartWaiterDishRefsTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: list<int>}> reply as the AI wrote it, text left, ids found */
    public static function replies(): iterable
    {
        $text = 'Te recomiendo la paella y las croquetas.';

        yield 'the expected line' => [$text . "\nDISHES: 12, 45", $text, [12, 45]];
        yield 'blank line before it' => [$text . "\n\nDISHES: 12", $text, [12]];
        yield 'empty line' => [$text . "\nDISHES:", $text, []];
        yield 'lower case' => [$text . "\ndishes: 12", $text, [12]];
        yield 'bold keyword' => [$text . "\n**DISHES:** 12, 45", $text, [12, 45]];
        yield 'as a list item' => [$text . "\n- DISHES: [12, 45]", $text, [12, 45]];
        yield 'with #' => [$text . "\nDISHES: #12, #45", $text, [12, 45]];
        yield 'no colon' => [$text . "\nDISHES 12 45", $text, [12, 45]];
        yield 'singular' => [$text . "\nDISH: 12", $text, [12]];
        yield 'with names' => [$text . "\nDISHES: 12 (paella), 45 (croquetas)", $text, [12, 45]];
        yield 'inline, end of sentence' => [$text . ' DISHES: 12, 45', $text, [12, 45]];
        yield 'inline, bracketed' => [$text . ' [DISHES: 12]', $text, [12]];
        yield 'first line' => ["DISHES: 12\n" . $text, $text, [12]];
        yield 'cut off: DISH' => [$text . "\nDISH", $text, []];
        yield 'cut off: DI' => [$text . "\nDI", $text, []];
        yield 'cut off mid-list' => [$text . "\nDISHES: 12, 4", $text, [12, 4]];
        yield 'translated keyword, sent ids' => [$text . "\nPLATOS: 12, 45", $text, [12, 45]];
        yield 'no line at all' => [$text, $text, []];
    }

    /** @param list<int> $ids */
    #[DataProvider('replies')]
    public function testTheLineNeverShows(string $reply, string $expectedText, array $ids): void
    {
        [$text, $found] = SmartWaiterDishRefs::split($reply, [12, 45]);

        self::assertSame($expectedText, $text);
        self::assertSame($ids, $found);
        self::assertStringNotContainsStringIgnoringCase('dishes', $text);
    }

    /** @return iterable<string, array{0: string, 1: string}> reply as the AI wrote it, text the customer sees */
    public static function inlineIds(): iterable
    {
        yield '(id 6546)' => ['Te sugiero el gazpacho (id 6546).', 'Te sugiero el gazpacho.'];
        yield '(ID: 6546)' => ['Te sugiero el gazpacho (ID: 6546), muy fresco.', 'Te sugiero el gazpacho, muy fresco.'];
        yield '[id 6546]' => ['Te sugiero el gazpacho [id 6546] y la paella.', 'Te sugiero el gazpacho y la paella.'];
        yield '(#6546)' => ['El gazpacho (#6546) es vegano.', 'El gazpacho es vegano.'];
        yield '(6546)' => ['El gazpacho (6546) es vegano.', 'El gazpacho es vegano.'];
        yield '(id#6546)' => ['El gazpacho (id#6546) es vegano.', 'El gazpacho es vegano.'];
        yield 'bare "id 6546"' => ['El gazpacho, id 6546, es vegano.', 'El gazpacho, es vegano.'];
        yield 'bare "ID: 6546"' => ['Prueba el gazpacho ID: 6546.', 'Prueba el gazpacho.'];
        yield 'bare "#6546"' => ['Prueba el gazpacho #6546 y la paella #6558.', 'Prueba el gazpacho y la paella.'];
        yield 'list in brackets' => ['Tenemos gazpacho y paella (id 6546, 6558).', 'Tenemos gazpacho y paella.'];
        yield 'list with "y"' => ['Tenemos gazpacho y paella (ids 6546 y 6558).', 'Tenemos gazpacho y paella.'];
        yield 'three in one sentence (the real reply)' => [
            'Te sugiero el gazpacho de tomate y cereza (id 6546), la paella de verduras de temporada (id 6558) y el sorbete de limón al cava (id 6562). Son veganos.',
            'Te sugiero el gazpacho de tomate y cereza, la paella de verduras de temporada y el sorbete de limón al cava. Son veganos.',
        ];
        yield 'with a DISHES line too' => ["El gazpacho (id 6546) es vegano.\nDISHES: 6546", 'El gazpacho es vegano.'];
    }

    #[DataProvider('inlineIds')]
    public function testAnIdWrittenInASentenceIsStripped(string $reply, string $expectedText): void
    {
        [$text] = SmartWaiterDishRefs::split($reply, [], [6546, 6558, 6562]);

        self::assertSame($expectedText, $text);
    }

    /** @return iterable<string, array{0: string}> numbers that are not ids sent in this request */
    public static function numbersThatStay(): iterable
    {
        yield 'a quantity in brackets' => ['La paella (2 personas) cuesta 24 €.'];
        yield 'a number in brackets that is no sent id' => ['La paella (1234) es para compartir.'];
        yield 'an unsent id' => ['La paella (id 1234) es para compartir.'];
        yield 'a list with one unsent id' => ['Tenemos gazpacho y paella (id 6546, 1234).'];
        yield 'a sent id as a price' => ['La mariscada cuesta 6546 pesos.'];
        yield 'a sent id inside a longer number' => ['Llámanos al 65460000.'];
        yield '"id" inside a word' => ['Paella de Madrid 6546.'];
        yield 'a year' => ['Receta de 1998.'];
    }

    #[DataProvider('numbersThatStay')]
    public function testANumberThatIsNoSentIdStays(string $reply): void
    {
        self::assertSame([$reply, []], SmartWaiterDishRefs::split($reply, [], [6546, 6558]));
    }

    public function testWithoutTheSentIdsNothingInASentenceIsStripped(): void
    {
        self::assertSame(['El gazpacho (id 6546).', []], SmartWaiterDishRefs::split('El gazpacho (id 6546).', [6546]));
    }

    public function testPickStripsInlineIdsButNeverMakesCardsOfThem(): void
    {
        $logger = $this->logger();
        [$text, $ids] = (new SmartWaiterDishRefs($logger))->pick("El gazpacho (id 6546) y la paella (id 6558).\nDISHES: 6558", [6558], 145, [6546, 6558]);

        self::assertSame('El gazpacho y la paella.', $text);
        self::assertSame([6558], $ids, 'only the DISHES line asks for cards');
        self::assertSame([], $logger->records);
    }

    public function testATranslatedKeywordWithNumbersThatWereNotSentStays(): void
    {
        [$text, $ids] = SmartWaiterDishRefs::split("Con las dos te quedas en 24.\nTotal: 24", [12, 45]);

        self::assertSame("Con las dos te quedas en 24.\nTotal: 24", $text, 'never a closing total');
        self::assertSame([], $ids);
    }

    public function testWordsThatOnlyLookLikeTheKeywordStay(): void
    {
        $reply = "Dishes like our paella are made to share.\nOur dishes change every season, DI.";

        self::assertSame([$reply, []], SmartWaiterDishRefs::split($reply, [12]));
    }

    public function testTheTextAroundAMidReplyLineIsKept(): void
    {
        [$text, $ids] = SmartWaiterDishRefs::split("La paella es para dos.\nDISHES: 12\n¿Quieres algo de beber?", [12]);

        self::assertSame("La paella es para dos.\n¿Quieres algo de beber?", $text);
        self::assertSame([12], $ids);
    }

    public function testOnlySentIdsSurviveDeduplicatedInOrderAtMostThree(): void
    {
        $logger = $this->logger();
        [$text, $ids] = (new SmartWaiterDishRefs($logger))->pick("Ok.\nDISHES: 7, 99999, 3, 7, 5, 1, 2", [1, 2, 3, 5, 7], 4242);

        self::assertSame('Ok.', $text);
        self::assertSame([7, 3, 5], $ids, 'the AI\'s order, no repeats, at most MAX_CARDS');
        self::assertCount(1, $logger->records);
        self::assertSame(['restaurant_id' => 4242, 'discarded' => [99999]], $logger->records[0]['context']);
    }

    public function testAnUnknownIdIsDroppedAndTheReplyStillShows(): void
    {
        $logger = $this->logger();
        [$text, $ids] = (new SmartWaiterDishRefs($logger))->pick("La paella.\nDISHES: 500", [1, 2], 1);

        self::assertSame('La paella.', $text);
        self::assertSame([], $ids);
        self::assertSame([500], $logger->records[0]['context']['discarded']);
    }

    public function testNothingIsLoggedWhenEveryIdIsValid(): void
    {
        $logger = $this->logger();
        (new SmartWaiterDishRefs($logger))->pick("Ok.\nDISHES: 1", [1], 1);

        self::assertSame([], $logger->records);
    }

    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
