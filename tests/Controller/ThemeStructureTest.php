<?php

namespace App\Tests\Controller;

use App\Controller\Admin\ThemeController;
use App\Entity\Restaurant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Every public-menu theme is the shared structure
 * (layouts/standard/_structure.html.twig) plus its own colours: its
 * vars.html.twig defines the same token set as maison's, covers every
 * token the structure, the layout and the Smart Waiter read without a
 * fallback, and the rendered menu carries both the structure and those
 * colours.
 */
final class ThemeStructureTest extends WebTestCase
{
    /** Optional tokens a theme may add on top of maison's set (read with a fallback). */
    private const OPTIONAL_TOKENS = ['--hero-focus'];

    private const TEMPLATES = [
        'templates/menu/layouts/standard/_structure.html.twig',
        'templates/menu/layouts/standard/show.html.twig',
        'templates/menu/_smart_waiter.html.twig',
    ];

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

    /** @return iterable<string, array{string}> */
    public static function themes(): iterable
    {
        foreach (array_keys(ThemeController::THEMES) as $theme) {
            yield $theme => [$theme];
        }
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2) . '/';
    }

    /** @return array<string, string> token => value, as declared in the theme's vars.html.twig */
    private static function themeTokens(string $theme): array
    {
        $path = self::root() . 'templates/menu/themes/' . $theme . '/vars.html.twig';
        self::assertFileExists($path);
        preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', (string) file_get_contents($path), $m);

        return array_combine($m[1], array_map('trim', $m[2]));
    }

    #[DataProvider('themes')]
    public function testVarsDefineTheSameTokensAsMaison(string $theme): void
    {
        $maison = array_keys(self::themeTokens('maison'));
        $own = array_keys(self::themeTokens($theme));

        self::assertSame([], array_values(array_diff($maison, $own)), $theme . ' is missing tokens maison defines');
        self::assertSame([], array_values(array_diff($own, $maison, self::OPTIONAL_TOKENS)), $theme . ' defines tokens outside the shared set');
    }

    #[DataProvider('themes')]
    public function testVarsCoverEveryTokenTheStructureReads(string $theme): void
    {
        $declared = [];
        $required = [];
        foreach (self::TEMPLATES as $file) {
            $src = (string) file_get_contents(self::root() . $file);
            preg_match_all('/(--[\w-]+)\s*:/', $src, $d);
            $declared = array_merge($declared, $d[1]);
            // var(--x) with no fallback: the theme has to define it.
            preg_match_all('/var\(\s*(--[\w-]+)\s*\)/', $src, $u);
            $required = array_merge($required, $u[1]);
        }
        $missing = array_diff(array_unique($required), $declared, array_keys(self::themeTokens($theme)));

        self::assertSame([], array_values($missing), $theme . ' leaves tokens undefined');
    }

    #[DataProvider('themes')]
    public function testMenuRendersTheSharedStructureWithTheThemeColours(string $theme): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Casa Tema');
        $restaurant->setSlug('theme-structure-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setTheme($theme);
        $restaurant->setLayout('standard');
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantIdsToRemove[] = $restaurant->getId();

        $crawler = $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=es');
        self::assertResponseIsSuccessful();
        $html = $crawler->html();

        // The structure: its shape tokens, fonts, sheet markup and the chat stepping aside.
        self::assertStringContainsString('--card-radius: 6px;', $html);
        self::assertStringContainsString('family=Fraunces', $html);
        self::assertCount(1, $crawler->filter('.dish-modal-scroll'));
        self::assertCount(1, $crawler->filter('.hero-inner > .cta-explore'));
        self::assertCount(1, $crawler->filter('#sw-widget[data-hide-on-scroll]'));
        // The theme's own colours.
        $tokens = self::themeTokens($theme);
        self::assertStringContainsString('--accent: ' . $tokens['--accent'] . ';', $html);
        self::assertStringContainsString('--hero-band: ' . $tokens['--hero-band'] . ';', $html);
    }
}
