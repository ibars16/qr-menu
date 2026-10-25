<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Configuración → "Contacto y redes" (address, phone, social links, opening
 * hours), plus the CSRF token and the server-side tagline limit added to the
 * same form. Runs against the test database (qrmenu_test).
 */
final class SettingsContactTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $restaurantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $restaurant = new Restaurant();
        $restaurant->setName('Settings Contact Test');
        $restaurant->setSlug('settings-contact-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('settings-contact-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $this->restaurantId = $restaurant->getId();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurantId);
        if ($restaurant) {
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            $this->em->remove($restaurant);
            $this->em->flush();
        }

        parent::tearDown();
    }

    /** The form's own CSRF token, read from a real GET of the page. */
    private function token(): string
    {
        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();

        return $crawler->filter('input[name="_token"]')->attr('value');
    }

    private function post(array $fields, ?string $token = null): void
    {
        $token ??= $this->token();
        $this->client->request('POST', '/admin/settings', array_merge([
            '_token'          => $token,
            'name'            => 'Settings Contact Test',
            'tagline'         => '',
            'currency'        => 'EUR',
            'defaultLanguage' => 'es',
            'adminLocale'     => 'es',
        ], $fields));
    }

    private function reload(): Restaurant
    {
        $this->em->clear();

        return $this->em->getRepository(Restaurant::class)->find($this->restaurantId);
    }

    public function testMigrationAddedTheColumnsToTheTestDatabase(): void
    {
        $columns = array_keys($this->em->getConnection()->createSchemaManager()->listTableColumns('restaurant'));

        foreach (['social_links', 'address', 'phone', 'opening_hours'] as $column) {
            self::assertContains($column, $columns);
        }
    }

    public function testSavingStoresNormalizedValuesAndTheFormShowsThemBack(): void
    {
        $versionBefore = $this->reload()->getMenuContentVersion();

        $this->post([
            'address' => '  Calle Mayor 1, Madrid ',
            'phone'   => '+34 600 123 456',
            'social'  => [
                'instagram'   => '@davidtest',
                'facebook'    => 'https://m.facebook.com/davidtest.bcn',
                'tiktok'      => '',
                'google'      => 'https://maps.app.goo.gl/AbC123',
                'tripadvisor' => '',
                'website'     => 'www.davidtest.es',
            ],
            'hours' => [
                'mon' => [['open' => '13:00', 'close' => '16:00'], ['open' => '20:00', 'close' => '01:00']],
                'sun' => ['closed' => '1'],
            ],
        ]);

        self::assertResponseRedirects('/admin/settings');
        $restaurant = $this->reload();
        self::assertSame('Calle Mayor 1, Madrid', $restaurant->getAddress());
        self::assertSame('+34 600 123 456', $restaurant->getPhone());
        self::assertSame([
            'instagram' => 'https://www.instagram.com/davidtest/',
            'facebook'  => 'https://www.facebook.com/davidtest.bcn',
            'google'    => 'https://maps.app.goo.gl/AbC123',
            'website'   => 'https://www.davidtest.es',
        ], $restaurant->getSocialLinks(), 'empty networks are absent keys');
        self::assertSame([
            'mon' => [['13:00', '16:00'], ['20:00', '01:00']],
            'sun' => [],
        ], $restaurant->getOpeningHours());
        self::assertGreaterThan($versionBefore, $restaurant->getMenuContentVersion());

        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertSame('https://www.instagram.com/davidtest/', $crawler->filter('#social-instagram')->attr('value'));
        self::assertSame('20:00', $crawler->filter('input[name="hours[mon][1][open]"]')->attr('value'));
        self::assertNotNull($crawler->filter('input[name="hours[sun][closed]"]')->attr('checked'), 'closed day comes back ticked');
    }

    public function testEverythingEmptyStoresNull(): void
    {
        $this->post(['address' => '', 'phone' => '', 'social' => [], 'hours' => []]);

        self::assertResponseRedirects('/admin/settings');
        $restaurant = $this->reload();
        self::assertNull($restaurant->getAddress());
        self::assertNull($restaurant->getPhone());
        self::assertNull($restaurant->getSocialLinks());
        self::assertNull($restaurant->getOpeningHours());
    }

    public function testErrorsShowUnderEachFieldKeepTheInputAndSaveNothing(): void
    {
        $this->post([
            'tagline'         => str_repeat('a', 161),
            'phone'           => '12',
            'currency'        => 'USD',
            'defaultLanguage' => 'en',
            'social'  => ['instagram' => 'https://evil.com/davidtest', 'website' => 'javascript:alert(1)'],
            'hours'   => ['tue' => [['open' => '13:00', 'close' => '17:00'], ['open' => '16:00', 'close' => '20:00']]],
        ]);

        self::assertResponseStatusCodeSame(422);
        $crawler = $this->client->getCrawler();
        self::assertSame('Máximo 160 caracteres.', $crawler->filter('#err-tagline')->text());
        self::assertSame('Escribe un teléfono válido (entre 7 y 15 dígitos).', $crawler->filter('#err-phone')->text());
        self::assertSame('Este enlace no es de esta red.', $crawler->filter('#err-social_instagram')->text());
        self::assertSame('Este enlace o usuario no es válido.', $crawler->filter('#err-social_website')->text());
        self::assertSame('Los dos turnos se solapan.', $crawler->filter('#err-hours_tue')->text());
        self::assertSame('true', $crawler->filter('#phone')->attr('aria-invalid'));
        self::assertSame('12', $crawler->filter('#phone')->attr('value'), 'what was typed is kept');
        self::assertSame('https://evil.com/davidtest', $crawler->filter('#social-instagram')->attr('value'));
        self::assertSame('16:00', $crawler->filter('input[name="hours[tue][1][open]"]')->attr('value'));
        self::assertSame('USD', $crawler->filter('#currency option[selected]')->attr('value'));
        self::assertSame('en', $crawler->filter('#defaultLanguage option[selected]')->attr('value'));

        $restaurant = $this->reload();
        self::assertNull($restaurant->getPhone());
        self::assertSame('EUR', $restaurant->getCurrency());
        self::assertNull($restaurant->getSocialLinks());
        self::assertNull($restaurant->getTagline());
    }

    public function testNameRequiredIsAFieldError(): void
    {
        $this->post(['name' => '   ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('El nombre del restaurante es obligatorio.', $this->client->getCrawler()->filter('#err-name')->text());
        self::assertSame('Settings Contact Test', $this->reload()->getName());
    }

    public function testMissingOrWrongCsrfTokenSavesNothing(): void
    {
        $this->post(['phone' => '+34 600 123 456'], token: 'not-the-token');
        self::assertResponseRedirects('/admin/settings');
        self::assertNull($this->reload()->getPhone());

        $this->client->request('POST', '/admin/settings', ['name' => 'Hacked', 'phone' => '+34 600 123 456']);
        self::assertResponseRedirects('/admin/settings');
        $restaurant = $this->reload();
        self::assertSame('Settings Contact Test', $restaurant->getName());
        self::assertNull($restaurant->getPhone());
    }

    public function testSectionSaveOnlyStoresThatSection(): void
    {
        $this->post([
            'section'         => 'contact',
            'name'            => 'Nombre sin guardar',
            'currency'        => 'USD',
            'phone'           => '+34 600 123 456',
            'social'          => ['instagram' => '@davidtest'],
        ]);

        self::assertResponseRedirects('/admin/settings#section-contact');
        $restaurant = $this->reload();
        self::assertSame('+34 600 123 456', $restaurant->getPhone());
        self::assertSame([], $restaurant->getSocialLinks() ?? [], 'social links have their own section');
        self::assertSame('Settings Contact Test', $restaurant->getName(), 'other sections are left as they were');
        self::assertSame('EUR', $restaurant->getCurrency());
    }

    public function testThereIsNoPrimaryColourFieldAndAPostedOneIsIgnored(): void
    {
        $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->client->getCrawler()->filter('[name="primaryColor"]'));

        $this->post(['section' => 'appearance', 'primaryColor' => '#123456']);
        self::assertResponseRedirects('/admin/settings#section-appearance');
        self::assertSame('#000000', $this->reload()->getPrimaryColor());

        $this->post(['primaryColor' => 'red;} body{display:none']);
        self::assertResponseRedirects('/admin/settings');
        self::assertSame('#000000', $this->reload()->getPrimaryColor());
    }

    public function testSocialAndHoursAreSeparateSections(): void
    {
        $hours = ['mon' => [['open' => '13:00', 'close' => '16:00']]];

        $this->post(['section' => 'social', 'phone' => '+34 600 123 456', 'social' => ['instagram' => '@davidtest'], 'hours' => $hours]);
        self::assertResponseRedirects('/admin/settings#section-social');
        $restaurant = $this->reload();
        self::assertSame(['instagram' => 'https://www.instagram.com/davidtest/'], $restaurant->getSocialLinks());
        self::assertNull($restaurant->getPhone());
        self::assertEmpty($restaurant->getOpeningHours());

        $this->post(['section' => 'hours', 'social' => ['instagram' => '@otro'], 'hours' => $hours]);
        self::assertResponseRedirects('/admin/settings#section-hours');
        $restaurant = $this->reload();
        self::assertNotEmpty($restaurant->getOpeningHours());
        self::assertSame(['instagram' => 'https://www.instagram.com/davidtest/'], $restaurant->getSocialLinks());
    }

    public function testSectionSaveIgnoresErrorsInOtherSections(): void
    {
        $this->post(['section' => 'language', 'name' => '', 'phone' => '12', 'currency' => 'USD']);

        self::assertResponseRedirects('/admin/settings#section-language');
        $restaurant = $this->reload();
        self::assertSame('USD', $restaurant->getCurrency());
        self::assertSame('Settings Contact Test', $restaurant->getName());
        self::assertNull($restaurant->getPhone());
    }

    public function testSectionSaveStillReportsItsOwnErrors(): void
    {
        $this->post(['section' => 'info', 'name' => '   ', 'currency' => 'USD']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('El nombre del restaurante es obligatorio.', $this->client->getCrawler()->filter('#err-name')->text());
        self::assertSame('EUR', $this->reload()->getCurrency());
    }

    public function testUnknownSectionSavesEverything(): void
    {
        $this->post(['section' => 'bogus', 'name' => 'Nuevo nombre', 'currency' => 'USD']);

        self::assertResponseRedirects('/admin/settings');
        $restaurant = $this->reload();
        self::assertSame('Nuevo nombre', $restaurant->getName());
        self::assertSame('USD', $restaurant->getCurrency());
    }
}
