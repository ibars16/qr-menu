<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /admin/login's language picker: the choice is remembered in the session
 * and also used by the logged-out "forgot password" screen.
 */
final class LoginLocalePickerTest extends WebTestCase
{
    public function testPickingItalianTranslatesTheLoginAndForgotPassword(): void
    {
        $client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $page = $client->request('GET', '/admin/login');

        $client->submit($page->filter('form.lang-form button[value="it"]')->form());
        self::assertResponseRedirects('/admin/login', 303);

        $page = $client->followRedirect();
        self::assertSame('Benvenuto', $page->filter('.login-title')->text());
        self::assertSame('it', $page->filter('.lang-item.active')->attr('value'));
        self::assertStringContainsString('IT', $page->filter('.lang-current')->text());

        $page = $client->click($page->filter('a.forgot-link')->link());
        self::assertSame('Password dimenticata?', $page->filter('h1.card-title')->text());
        self::assertStringContainsString('IT', $page->filter('.lang-current')->text(), 'the picker is there too');
    }
}
