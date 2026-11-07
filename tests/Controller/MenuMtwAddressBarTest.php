<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Service\CategoryTranslatorInterface;
use App\Service\ProductTranslatorInterface;
use App\Service\TagTranslatorInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ?mtw=1 only marks the load that already went through the loading screen:
 * the menu drops it from the address bar as soon as it loads
 * (history.replaceState, menu/_prefs_dialog.html.twig), so a reload — the
 * same address without it — checks again and detours once through the
 * loading screen while something is still missing (the menu itself never
 * translates). Before, the reload kept mtw=1 and showed the untranslated
 * text for good. No AI involved: a call would fail the test.
 */
final class MenuMtwAddressBarTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $restaurantId;
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->client->disableReboot();

        $noAi = new class implements ProductTranslatorInterface, CategoryTranslatorInterface, TagTranslatorInterface {
            public function translate(array $items, string $targetLocale, array $sourceLocales = []): array
            {
                throw new \LogicException('No AI call expected while rendering the menu.');
            }
        };
        $container = static::getContainer();
        $container->set(ProductTranslatorInterface::class, $noAi);
        $container->set(CategoryTranslatorInterface::class, $noAi);
        $container->set(TagTranslatorInterface::class, $noAi);

        // A Spanish menu whose tagline has no French translation yet (just edited, say).
        $em = $container->get(EntityManagerInterface::class);
        $restaurant = new Restaurant();
        $restaurant->setName('Mtw Address Bar Test');
        $this->slug = 'mtw-address-bar-test-' . uniqid();
        $restaurant->setSlug($this->slug);
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTagline('Cocina de mercado. Abrimos el fin de semana.');
        $em->persist($restaurant);
        $em->flush();
        $this->restaurantId = $restaurant->getId();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(Connection::class)->executeStatement('DELETE FROM restaurant WHERE id = ?', [$this->restaurantId]);

        parent::tearDown();
    }

    private function isLoadingScreen(): bool
    {
        return str_contains((string) $this->client->getResponse()->getContent(), 'warm-translations');
    }

    public function testTheMenuDropsMtwFromTheAddressBarSoAReloadDetoursAgain(): void
    {
        $this->client->request('GET', "/r/{$this->slug}?lang=fr");
        self::assertResponseIsSuccessful();
        self::assertTrue($this->isLoadingScreen(), 'something missing: loading screen first');
        self::assertStringContainsString('mtw=1', (string) $this->client->getResponse()->getContent(), 'it goes back to the menu with mtw=1');

        // The loading screen's redirect (the translation failed or timed out: still missing).
        $this->client->request('GET', "/r/{$this->slug}?lang=fr&mtw=1");
        self::assertResponseIsSuccessful();
        self::assertFalse($this->isLoadingScreen(), 'mtw=1: one detour only, never a loop');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("here.searchParams.delete('mtw')", $html);
        self::assertStringContainsString("history.replaceState(history.state, '', here.pathname + here.search + here.hash)", $html);

        // A reload: the address the bar now shows, without mtw — detours once again.
        $this->client->request('GET', "/r/{$this->slug}?lang=fr");
        self::assertTrue($this->isLoadingScreen());
    }

    /** Nothing missing: no loading screen, mtw or not. */
    public function testNothingMissingGoesStraightToTheMenu(): void
    {
        $this->client->request('GET', "/r/{$this->slug}?lang=es");
        self::assertResponseIsSuccessful();
        self::assertFalse($this->isLoadingScreen());
    }
}
