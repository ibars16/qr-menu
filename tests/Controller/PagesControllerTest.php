<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PagesControllerTest extends WebTestCase
{
    #[DataProvider('staticPages')]
    public function testStaticPageIsReachable(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
    }

    public static function staticPages(): iterable
    {
        yield 'about' => ['/sobre-nosotros'];
        yield 'privacy' => ['/privacidad'];
        yield 'terms' => ['/terminos'];
        yield 'cookies' => ['/cookies'];
    }

    public function testHomeFootersLinkToEveryStaticPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('href="/sobre-nosotros"', $content);
        self::assertStringContainsString('href="/privacidad"', $content);
        self::assertStringContainsString('href="/terminos"', $content);
        self::assertStringContainsString('href="/cookies"', $content);
    }
}
