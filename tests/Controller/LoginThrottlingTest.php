<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /admin/login has login_throttling with its defaults: after 5 failed
 * attempts in a minute for the same email (and IP), the 6th is refused —
 * even with the right password — with Symfony's own translated message.
 * Also: the "¿Has olvidado tu contraseña?" link.
 */
final class LoginThrottlingTest extends WebTestCase
{
    public function testTheSixthAttemptInAMinuteIsRefused(): void
    {
        $client = static::createClient();
        $email = 'throttle-test-' . uniqid() . '@example.test'; // fresh key every run

        for ($i = 1; $i <= 6; ++$i) {
            $form = $client->request('GET', '/admin/login')->filter('form')->form();
            $form['email'] = $email;
            $form['password'] = 'wrong-password';
            $client->submit($form);
            $page = $client->followRedirect();
        }

        self::assertStringContainsString('Demasiados intentos fallidos de inicio de sesión', $page->filter('.error-msg')->text());
    }

    public function testTheLoginLinksToForgotPassword(): void
    {
        $client = static::createClient();
        $page = $client->request('GET', '/admin/login');

        self::assertSame('/admin/login/recuperar', $page->filter('a.forgot-link')->attr('href'));
        self::assertSame('¿Has olvidado tu contraseña?', $page->filter('a.forgot-link')->text());
    }
}
