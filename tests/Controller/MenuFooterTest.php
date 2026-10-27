<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Public menu footer (menu/_footer.html.twig): contact details, social
 * links and grouped opening hours from Configuración, rendered only when
 * set. Runs against the test database (qrmenu_test) with restaurants it
 * creates and removes itself.
 */
final class MenuFooterTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var int[] */
    private array $restaurantIdsToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->restaurantIdsToRemove as $id) {
            $restaurant = $this->em->getRepository(Restaurant::class)->find($id);
            if ($restaurant) {
                $this->em->remove($restaurant);
            }
        }
        $this->em->flush();

        parent::tearDown();
    }

    private function makeRestaurant(string $theme, callable $configure): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa Footer');
        $restaurant->setSlug('menu-footer-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTheme($theme);
        $restaurant->setLayout('standard');
        $configure($restaurant);
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantIdsToRemove[] = $restaurant->getId();
        $this->em->clear();

        return $restaurant;
    }

    private function footer(Restaurant $restaurant, string $lang = 'es'): Crawler
    {
        $crawler = $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=' . $lang . '&currency=EUR');
        self::assertResponseIsSuccessful();

        $footer = $crawler->filter('footer.site-footer');
        self::assertCount(1, $footer);

        return $footer;
    }

    private static function text(Crawler $node): string
    {
        return preg_replace('/\s+/u', ' ', trim($node->text()));
    }

    public function testWithAllDataEveryBlockRendersWithItsLinks(): void
    {
        $restaurant = $this->makeRestaurant('maison', function (Restaurant $r) {
            $r->setTagline('Cocina de mercado');
            $r->setAddress('Calle Mayor 1, Madrid');
            $r->setPhone('+34 600 12-34-56');
            $r->setSocialLinks([
                'instagram' => 'https://www.instagram.com/casafooter/',
                'google'    => 'https://maps.app.goo.gl/AbC123',
                'website'   => 'https://casafooter.es',
            ]);
            $r->setOpeningHours([
                'mon' => [['13:00', '16:00']], 'tue' => [['13:00', '16:00']], 'wed' => [['13:00', '16:00']],
                'thu' => [['13:00', '16:00'], ['20:00', '23:30']],
                'fri' => [['20:00', '01:00']], 'sat' => [['20:00', '01:00']],
                'sun' => [],
            ]);
        });

        $footer = $this->footer($restaurant);

        self::assertSame('Casa Footer', self::text($footer->filter('.ft-name')));
        self::assertSame('Cocina de mercado', self::text($footer->filter('.ft-tagline')));
        self::assertSame(['Síguenos', 'Visítanos', 'Horario'], $footer->filter('.ft-label')->each(fn (Crawler $n) => self::text($n)));

        $social = $footer->filter('.ft-social a');
        self::assertCount(3, $social, 'only the networks that are set, in fixed order');
        self::assertSame('https://www.instagram.com/casafooter/', $social->eq(0)->attr('href'));
        self::assertSame('Instagram de Casa Footer', $social->eq(0)->attr('aria-label'));
        self::assertSame('Web de Casa Footer', $social->eq(2)->attr('aria-label'));
        foreach ($social as $a) {
            self::assertSame('_blank', $a->getAttribute('target'));
            self::assertSame('noopener noreferrer', $a->getAttribute('rel'));
        }

        $links = $footer->filter('.ft-contact .ft-link');
        self::assertSame('https://maps.app.goo.gl/AbC123', $links->eq(0)->attr('href'), "the owner's Google link wins over a search");
        self::assertSame('_blank', $links->eq(0)->attr('target'));
        self::assertSame('tel:+34600123456', $links->eq(1)->attr('href'));
        self::assertSame('+34 600 12-34-56', self::text($links->eq(1)), 'shown as the owner typed it');

        self::assertSame([
            'Lun–Mié 13:00–16:00',
            'Jue 13:00–16:00 · 20:00–23:30',
            'Vie–Sáb 20:00–01:00',
            'Dom Cerrado',
        ], $footer->filter('.ft-hours-row')->each(fn (Crawler $n) => self::text($n)));

        self::assertSame('© ' . date('Y') . ' Casa Footer', self::text($footer->filter('.ft-copy')));
    }

    public function testAddressWithoutGoogleLinkFallsBackToAMapsSearch(): void
    {
        $restaurant = $this->makeRestaurant('ocean', fn (Restaurant $r) => $r->setAddress('Plaça Reial 3, Barcelona'));

        $footer = $this->footer($restaurant);

        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=Pla%C3%A7a%20Reial%203%2C%20Barcelona',
            $footer->filter('.ft-contact .ft-link')->attr('href')
        );
        self::assertCount(0, $footer->filter('.ft-social'));
        self::assertCount(0, $footer->filter('.ft-hours'));
    }

    public function testWithoutAnyDataOnlyNameAndCopyright(): void
    {
        $restaurant = $this->makeRestaurant('ocean', fn (Restaurant $r) => $r->setTagline('No se muestra sin datos'));

        $footer = $this->footer($restaurant);

        self::assertSame('Casa Footer', self::text($footer->filter('.ft-name')));
        self::assertCount(0, $footer->filter('.ft-tagline'));
        self::assertCount(0, $footer->filter('.ft-block'));
        self::assertCount(0, $footer->filter('a'));
        self::assertSame('© ' . date('Y') . ' Casa Footer', self::text($footer->filter('.ft-copy')));
    }

    public function testLabelsAndDaysAreTranslated(): void
    {
        $restaurant = $this->makeRestaurant('ocean', function (Restaurant $r) {
            $r->setPhone('600123456');
            $r->setOpeningHours(['mon' => [['13:00', '16:00']], 'tue' => [['13:00', '16:00']], 'sun' => []]);
        });

        $footer = $this->footer($restaurant, 'de');
        self::assertSame(['Besuchen Sie uns', 'Öffnungszeiten'], $footer->filter('.ft-label')->each(fn (Crawler $n) => self::text($n)));
        self::assertSame(['Mo–Di 13:00–16:00', 'So Geschlossen'], $footer->filter('.ft-hours-row')->each(fn (Crawler $n) => self::text($n)));
        self::assertSame('tel:600123456', $footer->filter('.ft-link')->attr('href'));
    }
}
