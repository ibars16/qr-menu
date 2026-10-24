<?php

namespace App\Tests\Service;

use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\Restaurant;
use App\Repository\ProductTagTranslationRepository;
use App\Service\AI\AIModelRouter;
use App\Service\AI\AIProviderFactory;
use App\Service\AiTagTranslator;
use App\Service\RouterLabelTranslator;
use App\Service\RouterTagTranslator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tag names go through the same path as dishes and categories: groq-llama
 * first, AIModelRouter as fallback, then a check pass merged into the first.
 *
 * The real provider factory/router run against a MockHttpClient that
 * answers every request with the next scripted reply (or failure) — no
 * request can reach a real API. Every provider key is pinned in $_ENV so
 * the chain is the same on any machine.
 */
final class RouterTagTranslatorTest extends KernelTestCase
{
    private const KEY_ENVS = ['GEMINI_API_KEY', 'GROQ_API_KEY', 'OPENROUTER_API_KEY', 'OPENAI_API_KEY', 'MISTRAL_API_KEY', 'DEEPSEEK_API_KEY'];

    /** @var array<string, mixed> */
    private array $previousEnv = [];

    /** @var list<string> hosts actually requested */
    private array $requestedHosts = [];

    private ?EntityManagerInterface $em = null;
    private ?int $restaurantId = null;

    protected function setUp(): void
    {
        foreach (self::KEY_ENVS as $name) {
            $this->previousEnv[$name] = $_ENV[$name] ?? null;
            $_ENV[$name] = '';
        }
        $_ENV['GROQ_API_KEY'] = 'test-key-never-sent';
    }

    protected function tearDown(): void
    {
        if ($this->em !== null && $this->restaurantId !== null) {
            $this->em->clear();
            $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurantId);
            if ($restaurant) {
                foreach ($this->em->getRepository(ProductTag::class)->findBy(['restaurant' => $restaurant]) as $tag) {
                    $this->em->remove($tag);
                }
                $this->em->flush();
                $this->em->remove($restaurant);
                $this->em->flush();
            }
        }

        foreach ($this->previousEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }

        parent::tearDown();
    }

    /**
     * @param list<string|int> $replies model reply content, or an HTTP status
     *                                  code to fail that request with
     */
    private function translator(array $replies): RouterTagTranslator
    {
        $http = new MockHttpClient(function (string $method, string $url) use (&$replies): MockResponse {
            $this->requestedHosts[] = parse_url($url, PHP_URL_HOST);
            $reply = array_shift($replies);
            if ($reply === null) {
                return new MockResponse('{"error":{"message":"unexpected extra request"}}', ['http_code' => 500]);
            }
            if (is_int($reply)) {
                return new MockResponse('{"error":{"message":"scripted failure"}}', ['http_code' => $reply]);
            }

            return new MockResponse(json_encode(['choices' => [['message' => ['content' => $reply]]]]));
        });
        $factory = new AIProviderFactory($http, dirname(__DIR__, 2));
        $labels = new RouterLabelTranslator(new AIModelRouter($factory, new NullLogger()), $factory, new NullLogger());

        return new RouterTagTranslator($labels);
    }

    public function testABatchOfTagsIsTranslatedAndSavedAsAi(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $restaurant = new Restaurant();
        $restaurant->setName('Casa Etiquetas');
        $restaurant->setSlug('router-tag-translator-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $tagIds = [];
        foreach (['esparrago' => 'Esparrago', 'alergeno' => 'Alergeno', 'carne' => 'Carne'] as $key => $name) {
            $tag = new ProductTag($restaurant, $key);
            $t = new ProductTagTranslation();
            $t->setLocale('es');
            $t->setName($name);
            $t->setSource(ProductTagTranslation::SOURCE_HUMAN);
            $tag->addTranslation($t);
            $this->em->persist($t);
            $this->em->persist($tag);
            $tagIds[$key] = $tag;
        }
        $this->em->flush();
        $this->restaurantId = $restaurant->getId();
        $tagIds = array_map(static fn (ProductTag $tag) => $tag->getId(), $tagIds);
        // Start from the DB: Restaurant::$productTags was never filled in memory.
        $this->em->clear();
        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurantId);

        $firstPass = [
            (string) $tagIds['esparrago'] => 'Asparago',
            (string) $tagIds['alergeno']  => 'Allergene',
            (string) $tagIds['carne']     => 'Carne',
        ];
        $aiTranslator = new AiTagTranslator(
            $this->translator([json_encode($firstPass), json_encode($firstPass)]),
            $this->em,
            static::getContainer()->get(ProductTagTranslationRepository::class),
        );

        $aiTranslator->translateMissing($restaurant, 'it');

        $this->em->clear();
        $saved = [];
        foreach ($this->em->getRepository(ProductTagTranslation::class)->findBy(['locale' => 'it']) as $t) {
            if (in_array($t->getTag()->getId(), $tagIds, true)) {
                $saved[$t->getTag()->getId()] = [$t->getName(), $t->getSource()];
            }
        }
        ksort($saved);

        self::assertSame([
            $tagIds['esparrago'] => ['Asparago', ProductTagTranslation::SOURCE_AI],
            $tagIds['alergeno']  => ['Allergene', ProductTagTranslation::SOURCE_AI],
            $tagIds['carne']     => ['Carne', ProductTagTranslation::SOURCE_AI],
        ], $saved);
        self::assertSame(['api.groq.com', 'api.groq.com'], $this->requestedHosts, 'translate + check, both through groq-llama');
    }

    public function testTheCheckCorrectsOneTagAndIsMergedIntoTheFirstPass(): void
    {
        $result = $this->translator([
            json_encode(['49' => 'Espárago', '87' => 'Allergene', '90' => 'Carne']),
            json_encode(['49' => 'Asparago']),
        ])->translate([49 => 'Esparrago', 87 => 'Alergeno', 90 => 'Carne'], 'it');

        self::assertSame([49 => 'Asparago', 87 => 'Allergene', 90 => 'Carne'], $result);
    }

    public function testAFailingFirstProviderFallsBackToTheNextOne(): void
    {
        $_ENV['OPENAI_API_KEY'] = 'test-key-never-sent';

        $result = $this->translator([
            503,                                                  // groq-llama, first pass
            json_encode(['49' => 'Asparago', '87' => 'Allergene']), // openai-mini via AIModelRouter
            503,                                                  // groq-llama, check pass
            '{}',                                                 // openai-mini: nothing to fix
        ])->translate([49 => 'Esparrago', 87 => 'Alergeno'], 'it');

        self::assertSame([49 => 'Asparago', 87 => 'Allergene'], $result);
        self::assertSame(['api.groq.com', 'api.openai.com', 'api.groq.com', 'api.openai.com'], $this->requestedHosts);
    }
}
