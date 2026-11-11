<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Service\DishPhoto\DishImageFailure;
use App\Tests\Support\AiPhotoTestFixtures;
use App\Tests\Support\FakeDishImageGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * "Generar con IA" in the dish editor (DishPhotoController): drafts, the
 * trial limit of 3, refunds on failure, applying on save. The provider is
 * always FakeDishImageGenerator — never a real AI.
 */
final class DishPhotoEditorTest extends WebTestCase
{
    use AiPhotoTestFixtures;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->setUpAiPhoto();
    }

    protected function tearDown(): void
    {
        $this->tearDownAiPhoto();
        parent::tearDown();
    }

    private function generate(array $body): array
    {
        $this->client->request('POST', '/admin/products/ai-photo/generate', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function apply(Product $product, string $token): array
    {
        $this->client->request('POST', '/admin/products/' . $product->getId() . '/image/ai', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['token' => $token]));

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testTrialGetsThreePhotosThenTheChoosePlanMessage(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));

        $first = $this->generate(['name' => 'Tortilla de patatas', 'description' => 'Con cebolla']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $first['trialRemaining']);
        self::assertSame(1, $this->generate(['name' => 'Gazpacho'])['trialRemaining']);
        self::assertSame(0, $this->generate(['name' => 'Salmorejo'])['trialRemaining']);

        $fourth = $this->generate(['name' => 'Croquetas']);
        self::assertResponseStatusCodeSame(402);
        self::assertSame('trial_limit', $fourth['reason']);
        self::assertSame(0, $fourth['trialRemaining']);
        self::assertSame(3, $this->aiPhotoCount($restaurant));
        self::assertCount(3, FakeDishImageGenerator::$prompts, 'the fourth never reaches the AI');
    }

    public function testOnceUsedUpTheEditorButtonIsOffWithTheChoosePlanMessage(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));
        $this->dish($this->category($restaurant), 'Ensalada');
        foreach (['Gazpacho', 'Salmorejo', 'Pisto'] as $name) {
            $this->generate(['name' => $name]);
        }

        $crawler = $this->client->request('GET', '/admin/menu');
        $button = $crawler->filter('.ai-photo-btn')->first();
        self::assertNotNull($button->attr('disabled'), 'button off');
        self::assertStringContainsString('is-exhausted', $button->attr('class'));
        $line = $crawler->filter('.ai-photo-trial')->first();
        self::assertNull($line->attr('hidden'));
        self::assertSame('Ya has usado tus 3 fotos con IA gratis. Elige un plan para crear más.', trim($line->text()));
        self::assertNull($crawler->filter('.ai-photo-plan-btn')->first()->attr('hidden'), '"Elegir plan" (the JS hides it without the plan dialog)');
    }

    public function testAnUnsavedDraftStillCounts(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));

        $this->generate(['name' => 'Paella']);
        $this->generate(['name' => 'Paella']);

        self::assertSame(2, $this->aiPhotoCount($restaurant), 'generated = counted, saved or not');
    }

    public function testFailuresAreGivenBack(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));

        $cases = [
            [DishImageFailure::Refused, 422, 'refused', 'No hemos podido crear una foto para este plato. Prueba a cambiar la descripción o sube una tuya.'],
            [DishImageFailure::Timeout, 504, 'timeout', 'Está tardando demasiado, inténtalo de nuevo.'],
            [DishImageFailure::Unavailable, 503, 'unavailable', 'Ahora mismo no se puede crear la foto, prueba en unos minutos.'],
        ];
        foreach ($cases as [$failure, $status, $reason, $message]) {
            FakeDishImageGenerator::willFail($failure);
            $data = $this->generate(['name' => 'Pulpo ' . $reason]);
            self::assertResponseStatusCodeSame($status);
            self::assertSame($reason, $data['reason']);
            self::assertSame($message, $data['error']);
            self::assertSame(3, $data['trialRemaining'], 'nothing spent');
        }
        self::assertSame(0, $this->aiPhotoCount($restaurant));
    }

    public function testAnImageThatFailsTheValidatorIsARefusalAndGivenBack(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));

        FakeDishImageGenerator::willReturn(dirname(__DIR__) . '/fixtures/dish_photo/too_small_200x150.png');
        $data = $this->generate(['name' => 'Flan']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('refused', $data['reason']);
        self::assertSame(0, $this->aiPhotoCount($restaurant));
    }

    public function testAPaidPlanHasNoTrialLimit(): void
    {
        $restaurant = $this->restaurant(paid: true);
        $this->client->loginUser($this->owner($restaurant));

        for ($i = 1; $i <= 5; $i++) {
            $data = $this->generate(['name' => 'Plato ' . $i]);
            self::assertResponseIsSuccessful();
            self::assertNull($data['trialRemaining']);
        }
        self::assertSame(5, $this->aiPhotoCount($restaurant));
    }

    public function testTheSameDishIsBrakedAfterTenAnHour(): void
    {
        $restaurant = $this->restaurant(paid: true);
        $this->client->loginUser($this->owner($restaurant));
        $product = $this->dish($this->category($restaurant), 'Lubina');

        for ($i = 1; $i <= 10; $i++) {
            $this->generate(['name' => 'Lubina', 'productId' => $product->getId()]);
            self::assertResponseIsSuccessful();
        }
        $data = $this->generate(['name' => 'Lubina', 'productId' => $product->getId()]);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $data['reason']);
        self::assertCount(10, FakeDishImageGenerator::$prompts);

        $this->generate(['name' => 'Otro plato']);
        self::assertResponseIsSuccessful('the brake is per dish here, not the whole restaurant');
    }

    public function testTheDraftChangesNothingUntilApplied(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));
        file_put_contents($this->productsDir() . '/old.jpg', 'old');
        $product = $this->dish($this->category($restaurant), 'Merluza', 'old.jpg');

        $data = $this->generate(['name' => 'Merluza', 'description' => 'A la romana', 'productId' => $product->getId()]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('«Merluza»', FakeDishImageGenerator::$prompts[0]);
        self::assertStringContainsString('«A la romana»', FakeDishImageGenerator::$prompts[0]);

        $this->assertSame('old.jpg', $this->reload($product)->getImage(), 'closing without saving keeps the photo');
        self::assertFileExists($this->productsDir() . '/old.jpg');

        // The editor's preview: a light JPEG, private, ~1200px.
        $this->client->request('GET', $data['preview']);
        self::assertResponseIsSuccessful();
        $file = $this->client->getResponse()->getFile();
        self::assertSame('image/jpeg', mime_content_type($file->getPathname()));
        [$width] = getimagesize($file->getPathname());
        self::assertLessThanOrEqual(1200, $width);
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testApplyingReplacesThePhotoAndDropsTheClip(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));
        file_put_contents($this->productsDir() . '/old-poster.jpg', 'old');
        file_put_contents($this->productsDir() . '/old-clip.mp4', 'old');
        $product = $this->dish($this->category($restaurant), 'Pulpo', 'old-poster.jpg');
        $product->setVideoClip('old-clip.mp4');
        $this->em->flush();

        $token = $this->generate(['name' => 'Pulpo', 'productId' => $product->getId()])['token'];
        $result = $this->apply($product, $token);
        self::assertResponseIsSuccessful();

        $product = $this->reload($product);
        self::assertSame($result['image'], $product->getImage());
        self::assertSame(Product::IMAGE_SOURCE_AI, $product->getImageSource());
        self::assertNull($product->getVideoClip());
        self::assertFileExists($this->productsDir() . '/' . $product->getImage());
        self::assertFileDoesNotExist($this->productsDir() . '/old-poster.jpg');
        self::assertFileDoesNotExist($this->productsDir() . '/old-clip.mp4');

        $this->apply($product, $token);
        self::assertResponseStatusCodeSame(410, 'a draft is used once');
    }

    public function testRemovingThePhotoDeletesPhotoClipAndFiles(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));
        file_put_contents($this->productsDir() . '/poster.jpg', 'old');
        file_put_contents($this->productsDir() . '/clip.mp4', 'old');
        $product = $this->dish($this->category($restaurant), 'Pulpo', 'poster.jpg');
        $product->setImageSource(Product::IMAGE_SOURCE_AI);
        $product->setVideoClip('clip.mp4');
        $this->em->flush();
        $version = $this->em->find(Restaurant::class, $restaurant->getId())->getMenuContentVersion();

        $this->client->request('POST', '/admin/products/' . $product->getId() . '/image/delete');
        self::assertResponseIsSuccessful();
        self::assertSame(['image' => null, 'videoClip' => null], json_decode($this->client->getResponse()->getContent(), true));

        $product = $this->reload($product);
        self::assertNull($product->getImage());
        self::assertNull($product->getImageSource());
        self::assertNull($product->getVideoClip());
        self::assertFileDoesNotExist($this->productsDir() . '/poster.jpg');
        self::assertFileDoesNotExist($this->productsDir() . '/clip.mp4');
        self::assertGreaterThan($version, $this->em->find(Restaurant::class, $restaurant->getId())->getMenuContentVersion(), 'the public menu is refreshed');
    }

    public function testAnotherRestaurantsPhotoCannotBeRemoved(): void
    {
        $theirs = $this->restaurant();
        file_put_contents($this->productsDir() . '/theirs.jpg', 'theirs');
        $theirDish = $this->dish($this->category($theirs), 'Ajeno', 'theirs.jpg');

        $this->client->loginUser($this->owner($this->restaurant()));
        $this->client->request('POST', '/admin/products/' . $theirDish->getId() . '/image/delete');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('theirs.jpg', $this->reload($theirDish)->getImage());
        self::assertFileExists($this->productsDir() . '/theirs.jpg');
    }

    public function testANewDishCanGetADraftBeforeItExists(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));

        $token = $this->generate(['name' => 'Plato nuevo', 'productId' => null])['token'];
        $product = $this->dish($this->category($restaurant), 'Plato nuevo');
        $this->apply($product, $token);

        self::assertResponseIsSuccessful();
        self::assertSame(Product::IMAGE_SOURCE_AI, $this->reload($product)->getImageSource());
    }

    public function testAnotherRestaurantsDishAndDraftAreOffLimits(): void
    {
        $mine = $this->restaurant();
        $theirs = $this->restaurant();
        $theirDish = $this->dish($this->category($theirs), 'Ajeno');
        $myDish = $this->dish($this->category($mine), 'Mío');

        $this->client->loginUser($this->owner($theirs));
        $theirToken = $this->generate(['name' => 'Ajeno'])['token'];

        $this->client->loginUser($this->owner($mine));
        $this->generate(['name' => 'Ajeno', 'productId' => $theirDish->getId()]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/products/ai-photo/draft/' . $theirToken);
        self::assertResponseStatusCodeSame(404);

        $this->apply($myDish, $theirToken);
        self::assertResponseStatusCodeSame(410, 'their draft is not found under my restaurant');

        $this->apply($theirDish, $theirToken);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reload($theirDish)->getImage());
    }

    public function testANameIsRequired(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));

        $data = $this->generate(['name' => '  ']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('name_required', $data['reason']);
        self::assertSame([], FakeDishImageGenerator::$prompts);
    }

    public function testTheOwnersOwnUploadClearsTheAiMark(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));
        $product = $this->dish($this->category($restaurant), 'Bravas');
        $this->apply($product, $this->generate(['name' => 'Bravas', 'productId' => $product->getId()])['token']);
        self::assertSame(Product::IMAGE_SOURCE_AI, $this->reload($product)->getImageSource());

        $tmp = tempnam(sys_get_temp_dir(), 'own') . '.png';
        copy(FakeDishImageGenerator::defaultFixture(), $tmp);
        $this->client->request('POST', '/admin/products/' . $product->getId() . '/image', files: [
            'image' => new UploadedFile($tmp, 'mine.png', 'image/png', null, true),
        ]);
        self::assertResponseIsSuccessful();

        self::assertNull($this->reload($product)->getImageSource());
    }

    public function testTheEditorShowsTheButtonAndTheTrialLine(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));
        $this->dish($this->category($restaurant), 'Ensalada');

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.ai-photo-btn')->count());
        self::assertGreaterThan(0, $crawler->filter('.img-upload-delete-btn')->count(), '"Eliminar foto" is there too');
        self::assertSame('Te quedan 3 de 3 fotos con IA gratis.', trim($crawler->filter('.ai-photo-trial')->first()->text()));
        self::assertNull($crawler->filter('.ai-photo-btn')->first()->attr('disabled'));
        self::assertNotNull($crawler->filter('.ai-photo-plan-btn')->first()->attr('hidden'));

        $paid = $this->restaurant(paid: true);
        $this->client->loginUser($this->owner($paid));
        $this->dish($this->category($paid), 'Ensalada');
        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertNotNull($crawler->filter('.ai-photo-trial')->first()->attr('hidden'), 'no trial line on a paid plan');
    }

    public function testWithoutTheImageKeyThereIsNoButtonAndNoPhoto(): void
    {
        $this->withoutImageKey();
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));
        $this->dish($this->category($restaurant), 'Ensalada');

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.ai-photo-btn'));
        self::assertCount(0, $crawler->filter('.ai-photo-trial'));
        self::assertCount(0, $crawler->filter('.ai-photo-plan-btn'));

        $result = $this->generate(['name' => 'Gazpacho']);
        self::assertResponseStatusCodeSame(503);
        self::assertSame('not_configured', $result['reason']);
        self::assertSame([], FakeDishImageGenerator::$prompts, 'never reaches the AI');
        self::assertSame(0, $this->aiPhotoCount($restaurant), 'nothing counted');
    }

    public function testThePublicMenuShowsNoAiMark(): void
    {
        // Built both-sides and active, like MaisonNutritionPanelTest, so the
        // dish really renders; its photo is marked as an AI one.
        $restaurant = $this->restaurant();
        $category = new Category();
        $category->setActive(true);
        $restaurant->addCategory($category);
        $categoryTranslation = new CategoryTranslation();
        $categoryTranslation->setLocale('es');
        $categoryTranslation->setName('Raciones');
        $category->addTranslation($categoryTranslation);
        $this->em->persist($category);
        $this->em->persist($categoryTranslation);

        $product = new Product();
        $product->setBasePrice(1000);
        $product->setActive(true);
        $product->setImage('ai-made-photo.jpg');
        $product->setImageSource(Product::IMAGE_SOURCE_AI);
        $category->addProduct($product);
        $translation = new ProductTranslation();
        $translation->setLocale('es');
        $translation->setName('Calamares');
        $product->addTranslation($translation);
        $this->em->persist($product);
        $this->em->persist($translation);
        $this->em->flush();

        $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=es');
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('ai-made-photo.jpg', $body, 'the dish and its photo are on the menu');
        self::assertStringContainsString('Calamares', $body);
        foreach (['image_source', 'imageSource', 'ai-photo', 'Generada con IA', 'Creada con IA', 'data-ai'] as $marker) {
            self::assertStringNotContainsStringIgnoringCase($marker, $body);
        }
    }
}
