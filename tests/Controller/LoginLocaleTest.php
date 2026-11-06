<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /admin/login is logged out, so it follows the browser's language
 * (AdminLocaleSubscriber), like /register.
 */
final class LoginLocaleTest extends WebTestCase
{
    public function testTheLoginIsInGermanWithAGermanBrowser(): void
    {
        $client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);
        $page = $client->request('GET', '/admin/login');

        self::assertResponseIsSuccessful();
        self::assertSame('Willkommen', $page->filter('.login-title')->text());
        self::assertSame('Anmelden', $page->filter('button.login-btn')->text());
        self::assertSame('Passwort vergessen?', $page->filter('a.forgot-link')->text());
    }
}
