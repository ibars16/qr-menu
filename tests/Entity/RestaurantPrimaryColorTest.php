<?php

namespace App\Tests\Entity;

use App\Entity\Restaurant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The primary colour is no longer editable nor read by any theme, but the
 * column stays and setPrimaryColor() keeps refusing anything but "#rrggbb"
 * (stored lowercase), so nothing unsafe can land in it.
 */
final class RestaurantPrimaryColorTest extends TestCase
{
    public function testHexIsStoredLowercase(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setPrimaryColor('#C1440E');

        self::assertSame('#c1440e', $restaurant->getPrimaryColor());
    }

    public static function invalidColours(): iterable
    {
        yield 'css injection' => ['red;} body{display:none'];
        yield 'named colour'  => ['red'];
        yield 'short hex'     => ['#abc'];
        yield 'no hash'       => ['c1440e'];
        yield 'alpha'         => ['#c1440e80'];
        yield 'trailing nl'   => ["#c1440e\n"];
        yield 'empty'         => [''];
    }

    #[DataProvider('invalidColours')]
    public function testAnythingElseIsRefused(string $colour): void
    {
        $restaurant = new Restaurant();

        try {
            $restaurant->setPrimaryColor($colour);
            self::fail('accepted ' . var_export($colour, true));
        } catch (\InvalidArgumentException) {
            self::assertSame('#000000', $restaurant->getPrimaryColor());
        }
    }
}
