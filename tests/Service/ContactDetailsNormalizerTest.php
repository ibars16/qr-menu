<?php

namespace App\Tests\Service;

use App\Service\ContactDetailsNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContactDetailsNormalizerTest extends TestCase
{
    private ContactDetailsNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ContactDetailsNormalizer();
    }

    public static function validSocial(): array
    {
        return [
            'instagram @user'          => ['instagram', '@david.test_1', 'https://www.instagram.com/david.test_1/'],
            'instagram bare user'      => ['instagram', 'davidtest', 'https://www.instagram.com/davidtest/'],
            'instagram url + query'    => ['instagram', 'https://www.instagram.com/davidtest/?hl=es', 'https://www.instagram.com/davidtest/'],
            'instagram no scheme'      => ['instagram', 'instagram.com/davidtest', 'https://www.instagram.com/davidtest/'],
            'instagram http → https'   => ['instagram', 'http://m.instagram.com/davidtest', 'https://www.instagram.com/davidtest/'],
            'tiktok @user'             => ['tiktok', '@david.test', 'https://www.tiktok.com/@david.test'],
            'tiktok bare user'         => ['tiktok', 'davidtest', 'https://www.tiktok.com/@davidtest'],
            'tiktok url'               => ['tiktok', 'https://www.tiktok.com/@davidtest?lang=es', 'https://www.tiktok.com/@davidtest'],
            'facebook slug'            => ['facebook', 'DavidTestRestaurante', 'https://www.facebook.com/DavidTestRestaurante'],
            'facebook url'             => ['facebook', 'https://www.facebook.com/david.test.bcn/', 'https://www.facebook.com/david.test.bcn'],
            'facebook fb.com'          => ['facebook', 'fb.com/davidtest', 'https://www.facebook.com/davidtest'],
            'facebook m.'              => ['facebook', 'https://m.facebook.com/davidtest', 'https://www.facebook.com/davidtest'],
            'facebook profile.php'     => ['facebook', 'https://www.facebook.com/profile.php?id=100064123456789', 'https://www.facebook.com/profile.php?id=100064123456789'],
            'google maps.app.goo.gl'   => ['google', 'https://maps.app.goo.gl/AbC123xyz', 'https://maps.app.goo.gl/AbC123xyz'],
            'google maps path'         => ['google', 'https://www.google.com/maps/place/David+Test/@41.38,2.17,17z', 'https://www.google.com/maps/place/David+Test/@41.38,2.17,17z'],
            'google .es no scheme'     => ['google', 'google.es/maps/place/David+Test', 'https://google.es/maps/place/David+Test'],
            'google g.page'            => ['google', 'g.page/david-test/review', 'https://g.page/david-test/review'],
            'google writereview'       => ['google', 'https://search.google.com/local/writereview?placeid=ChIJ123', 'https://search.google.com/local/writereview?placeid=ChIJ123'],
            'tripadvisor .es'          => ['tripadvisor', 'https://www.tripadvisor.es/Restaurant_Review-g187497-d123-Reviews-David_Test.html', 'https://www.tripadvisor.es/Restaurant_Review-g187497-d123-Reviews-David_Test.html'],
            'tripadvisor co.uk http'   => ['tripadvisor', 'http://www.tripadvisor.co.uk/Restaurant_Review-g1-d2', 'https://www.tripadvisor.co.uk/Restaurant_Review-g1-d2'],
            'website no scheme'        => ['website', 'www.davidtest.es', 'https://www.davidtest.es'],
            'website keeps http'       => ['website', 'http://davidtest.es/carta', 'http://davidtest.es/carta'],
            'website trimmed'          => ['website', '  https://davidtest.es  ', 'https://davidtest.es'],
        ];
    }

    #[DataProvider('validSocial')]
    public function testValidSocialLinksAreNormalized(string $network, string $input, string $expected): void
    {
        self::assertSame([$expected, null], $this->normalizer->normalizeSocial($network, $input));
    }

    public static function rejectedSocial(): array
    {
        return [
            'javascript scheme'          => ['website', 'javascript:alert(1)', 'invalid'],
            'data scheme'                => ['website', 'data:text/html,hi', 'invalid'],
            'mailto'                     => ['website', 'mailto:a@b.es', 'invalid'],
            'ftp'                        => ['website', 'ftp://davidtest.es', 'invalid'],
            'userinfo'                   => ['website', 'https://user:pw@davidtest.es', 'invalid'],
            'localhost'                  => ['website', 'http://localhost/carta', 'invalid'],
            'ip'                         => ['website', 'http://192.168.1.10', 'invalid'],
            'no dot'                     => ['website', 'davidtest', 'invalid'],
            'whitespace inside'          => ['website', 'https://david test.es', 'invalid'],
            'too long'                   => ['website', 'https://davidtest.es/' . str_repeat('a', 260), 'invalid'],
            'instagram other domain'     => ['instagram', 'https://evil.com/davidtest', 'wrong_domain'],
            'instagram lookalike host'   => ['instagram', 'https://instagram.com.evil.com/davidtest', 'wrong_domain'],
            'instagram post not profile' => ['instagram', 'https://www.instagram.com/p/Cx123/', 'invalid'],
            'instagram bad chars'        => ['instagram', '@david-test!', 'invalid'],
            'instagram js scheme'        => ['instagram', 'javascript:alert(1)', 'invalid'],
            'tiktok no @ path'           => ['tiktok', 'https://www.tiktok.com/davidtest', 'invalid'],
            'tiktok too short'           => ['tiktok', '@d', 'invalid'],
            'tiktok other domain'        => ['tiktok', 'https://www.instagram.com/@davidtest', 'wrong_domain'],
            'facebook slug too short'    => ['facebook', 'abc', 'invalid'],
            'facebook sharer'            => ['facebook', 'https://www.facebook.com/sharer/sharer.php?u=x', 'invalid'],
            'facebook other domain'      => ['facebook', 'https://facebook.evil.com/davidtest', 'wrong_domain'],
            'google search not maps'     => ['google', 'https://www.google.com/search?q=david+test', 'invalid'],
            'google bare name'           => ['google', 'David Test', 'invalid'],
            'google other domain'        => ['google', 'https://maps.apple.com/?q=david', 'wrong_domain'],
            'tripadvisor not restaurant' => ['tripadvisor', 'https://www.tripadvisor.es/Hotel_Review-g1-d2', 'invalid'],
            'tripadvisor other domain'   => ['tripadvisor', 'https://www.yelp.com/biz/david-test', 'wrong_domain'],
        ];
    }

    #[DataProvider('rejectedSocial')]
    public function testRejectedSocialLinks(string $network, string $input, string $error): void
    {
        self::assertSame([null, $error], $this->normalizer->normalizeSocial($network, $input));
    }

    public function testEmptySocialIsNotSetAndNotAnError(): void
    {
        self::assertSame([null, null], $this->normalizer->normalizeSocial('instagram', '   '));
    }

    public static function phones(): array
    {
        return [
            'spaces'             => ['+34 600 123 456', '+34 600 123 456', null],
            'brackets + hyphens' => ['(93) 123-45-67', '(93) 123-45-67', null],
            'trimmed'            => ['  912345678 ', '912345678', null],
            'empty'              => ['', null, null],
            'too few digits'     => ['123 45', null, 'invalid'],
            'too many digits'    => ['+34 600 123 456 789 01', null, 'invalid'],
            'letters'            => ['600 123 ABC', null, 'invalid'],
            'plus not leading'   => ['600+123456', null, 'invalid'],
        ];
    }

    #[DataProvider('phones')]
    public function testPhone(string $input, ?string $stored, ?string $error): void
    {
        self::assertSame([$stored, $error], $this->normalizer->normalizePhone($input));
    }

    public function testAddressTooLong(): void
    {
        self::assertSame([null, 'too_long'], $this->normalizer->normalizeAddress(str_repeat('a', 256)));
        self::assertSame(['Calle Mayor 1, Madrid', null], $this->normalizer->normalizeAddress(' Calle Mayor 1, Madrid '));
    }

    public function testOpeningHoursTwoShiftsClosedDayAndUnsetDay(): void
    {
        [$hours, $errors] = $this->normalizer->normalizeOpeningHours([
            'mon' => [['open' => '20:00', 'close' => '23:30'], ['open' => '13:00', 'close' => '16:00']],
            'tue' => ['closed' => '1', ['open' => '13:00', 'close' => '16:00']],
            'wed' => [['open' => '', 'close' => ''], ['open' => '', 'close' => '']],
        ]);

        self::assertSame([], $errors);
        self::assertSame([
            'mon' => [['13:00', '16:00'], ['20:00', '23:30']],
            'tue' => [],
        ], $hours, 'shifts sorted, closed day = [], unconfigured day = absent key');
    }

    public function testOpeningHoursPastMidnightIsValid(): void
    {
        [$hours, $errors] = $this->normalizer->normalizeOpeningHours([
            'fri' => [['open' => '13:00', 'close' => '16:00'], ['open' => '20:00', 'close' => '02:00']],
        ]);

        self::assertSame([], $errors);
        self::assertSame(['fri' => [['13:00', '16:00'], ['20:00', '02:00']]], $hours);
    }

    public static function hourErrors(): array
    {
        return [
            'overlap'               => [[['open' => '13:00', 'close' => '17:00'], ['open' => '16:00', 'close' => '20:00']], 'overlap'],
            'overlap past midnight' => [[['open' => '20:00', 'close' => '03:00'], ['open' => '22:00', 'close' => '23:30']], 'overlap'],
            'half filled'           => [[['open' => '13:00', 'close' => '']], 'incomplete'],
            'same time'             => [[['open' => '13:00', 'close' => '13:00']], 'same_time'],
            'bad time'              => [[['open' => '25:00', 'close' => '13:00']], 'invalid'],
        ];
    }

    #[DataProvider('hourErrors')]
    public function testOpeningHoursErrors(array $dayInput, string $error): void
    {
        [$hours, $errors] = $this->normalizer->normalizeOpeningHours(['sat' => $dayInput]);

        self::assertNull($hours);
        self::assertSame(['sat' => $error], $errors);
    }

    public function testOpeningHoursAllEmptyIsNull(): void
    {
        self::assertSame([null, []], $this->normalizer->normalizeOpeningHours([]));
        self::assertSame([null, []], $this->normalizer->normalizeOpeningHours([
            'mon' => [['open' => '', 'close' => '']],
            'sun' => [],
        ]));
    }
}
