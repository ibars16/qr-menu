<?php

namespace App\Tests\EventSubscriber;

use App\Entity\Restaurant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * App\EventSubscriber\SecurityHeaders: nosniff and Referrer-Policy
 * everywhere, HSTS only over https, and the panels (login included) can't
 * be framed by another website — the public menu can.
 */
final class SecurityHeadersTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    public function testThePanelsCantBeFramed(): void
    {
        foreach (['/admin/login', '/admin/menu', '/partners', '/platform', '/partners/registro'] as $path) {
            $this->client->request('GET', $path);
            $headers = $this->client->getResponse()->headers;

            self::assertSame('DENY', $headers->get('X-Frame-Options'), $path);
            self::assertSame("frame-ancestors 'none'", $headers->get('Content-Security-Policy'), $path);
            self::assertSame('nosniff', $headers->get('X-Content-Type-Options'), $path);
            self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'), $path);
        }
    }

    public function testThePublicMenuAndTheHomeCanBeFramed(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $restaurant = new Restaurant();
        $restaurant->setName('Security Headers Test');
        $restaurant->setSlug('security-headers-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $em->persist($restaurant);
        $em->flush();

        try {
            foreach (['/r/' . $restaurant->getSlug() . '?lang=es', '/'] as $path) {
                $this->client->request('GET', $path);
                $headers = $this->client->getResponse()->headers;

                self::assertFalse($headers->has('X-Frame-Options'), $path);
                self::assertFalse($headers->has('Content-Security-Policy'), $path);
                self::assertSame('nosniff', $headers->get('X-Content-Type-Options'), $path);
                self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'), $path);
            }
        } finally {
            $em->remove($em->find(Restaurant::class, $restaurant->getId()));
            $em->flush();
        }
    }

    public function testHstsOnlyOverHttps(): void
    {
        $this->client->request('GET', '/admin/login');
        self::assertFalse($this->client->getResponse()->headers->has('Strict-Transport-Security'));

        $this->client->request('GET', 'https://localhost/admin/login');
        self::assertSame('max-age=31536000', $this->client->getResponse()->headers->get('Strict-Transport-Security'));
    }
}
