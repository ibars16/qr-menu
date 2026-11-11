<?php

namespace App\Tests\Service\DishPhoto;

use App\Command\ImportGlobalIngredientsCommand;
use App\Service\AI\AIProviderFactory;
use App\Service\DishPhoto\DishPhotoAvailability;
use App\Service\DishPhoto\GeminiDishImageGenerator;
use App\Service\GeminiIngredientTranslator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Yaml\Yaml;

/**
 * Each Gemini key goes to its own services: GEMINI_IMAGE_API_KEY only to
 * AI dish photos, GEMINI_API_KEY (text) to everything else — and never the
 * text key standing in for a missing image key. Values are the fakes from
 * .env.test; no request is ever sent.
 */
final class DishPhotoApiKeysTest extends KernelTestCase
{
    private const TEXT_KEY = 'gemini_fake_for_tests';
    private const IMAGE_KEY = 'gemini_image_fake_for_tests';

    /** @var array<string, array{env: mixed, server: mixed}> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $saved) {
            foreach (['env' => '_ENV', 'server' => '_SERVER'] as $key => $global) {
                if ($saved[$key] === null) {
                    unset($GLOBALS[$global][$name]);
                } else {
                    $GLOBALS[$global][$name] = $saved[$key];
                }
            }
        }
        $this->savedEnv = [];
        parent::tearDown();
    }

    private function setEnv(string $name, string $value): void
    {
        $this->savedEnv[$name] ??= ['env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null];
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private static function property(object $service, string $name): mixed
    {
        return (new \ReflectionProperty($service, $name))->getValue($service);
    }

    /**
     * Never assertSame() on a key: a failure would print its value, and if
     * a real key ever ends up in the test env (.env.test) it would land in
     * the test output. Only the expected fake/empty value is ever shown.
     */
    private static function assertKey(string $expected, mixed $actual, string $what): void
    {
        self::assertTrue($actual === $expected, sprintf(
            '%s should be "%s" (from .env.test) but is something else (%s) — is a real key in .env.test?',
            $what, $expected, is_string($actual) ? strlen($actual) . ' chars, not shown' : get_debug_type($actual),
        ));
    }

    public function testTheImageGeneratorGetsTheImageKey(): void
    {
        self::bootKernel();
        $generator = static::getContainer()->get(GeminiDishImageGenerator::class);

        self::assertKey(self::IMAGE_KEY, self::property($generator, 'apiKey'), 'GeminiDishImageGenerator key');
    }

    public function testTextServicesKeepTheTextKey(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        self::assertKey(self::TEXT_KEY, self::property($container->get(GeminiIngredientTranslator::class), 'geminiApiKey'), 'GeminiIngredientTranslator key');
        self::assertKey(self::TEXT_KEY, self::property($container->get(ImportGlobalIngredientsCommand::class), 'geminiApiKey'), 'ImportGlobalIngredientsCommand key');
    }

    public function testAiProvidersReadOnlyTheTextKey(): void
    {
        $providers = Yaml::parseFile(dirname(__DIR__, 3) . '/config/ai_providers.yaml');
        $keys = [];
        array_walk_recursive($providers, static function ($value, $key) use (&$keys) {
            if ($key === 'api_key_env') {
                $keys[] = $value;
            }
        });
        self::assertContains('GEMINI_API_KEY', $keys);
        self::assertNotContains('GEMINI_IMAGE_API_KEY', $keys, 'translations, Smart Waiter and menu import never use the image key');

        // With only the image key set, no Gemini text provider is available.
        $this->setEnv('GEMINI_API_KEY', '');
        $this->setEnv('GEMINI_IMAGE_API_KEY', self::IMAGE_KEY);
        $factory = new AIProviderFactory(new MockHttpClient(), dirname(__DIR__, 3));
        self::assertNull($factory->getProviderById('gemini-flash-lite'));

        $this->setEnv('GEMINI_API_KEY', self::TEXT_KEY);
        self::assertNotNull($factory->getProviderById('gemini-flash-lite'));
    }

    public function testWithoutTheImageKeyPhotosAreOffEvenWithTheTextKey(): void
    {
        $this->setEnv('GEMINI_IMAGE_API_KEY', '');
        $this->setEnv('GEMINI_API_KEY', self::TEXT_KEY);
        self::bootKernel();
        $container = static::getContainer();

        self::assertFalse($container->get(DishPhotoAvailability::class)->isAvailable());
        self::assertKey('', self::property($container->get(GeminiDishImageGenerator::class), 'apiKey'), 'GeminiDishImageGenerator key (no fallback to the text key)');
    }

    public function testWithTheImageKeyPhotosAreOn(): void
    {
        self::bootKernel();

        self::assertTrue(static::getContainer()->get(DishPhotoAvailability::class)->isAvailable());
    }
}
