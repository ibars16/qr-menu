<?php

namespace App\Tests\Controller;

use App\Entity\Product;
use App\Entity\Restaurant;
use App\Service\DishPhoto\DishImageFailure;
use App\Tests\Support\AiPhotoTestFixtures;
use App\Tests\Support\FakeDishImageGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * "Mejorar con IA" in the dish editor (DishPhotoController::enhance()):
 * only the owner's own photo, sent reduced, saved on the dish at once
 * (ai_edit) with the original kept aside for "Volver a la original"
 * (revert()) until the photo changes, the same quota and brakes as
 * "Generar con IA". The provider is always FakeDishImageGenerator —
 * never a real AI.
 */
final class DishPhotoEnhanceTest extends WebTestCase
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

    private function enhance(Product $product): array
    {
        $this->client->request('POST', '/admin/products/' . $product->getId() . '/ai-photo/enhance');

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
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

    /** A dish with a real 1200×896 photo the owner uploaded (image_source null). */
    private function dishWithOwnPhoto(string $name = 'Merluza', ?Restaurant $restaurant = null): Product
    {
        $restaurant ??= $this->restaurant();
        $filename = 'own-' . bin2hex(random_bytes(4)) . '.png';
        copy(FakeDishImageGenerator::defaultFixture(), $this->productsDir() . '/' . $filename);

        return $this->dish($this->category($restaurant), $name, $filename);
    }

    /** @return string[] the originals kept for $restaurant (basenames) */
    private function keptOriginals(Restaurant $restaurant): array
    {
        $dir = static::getContainer()->getParameter('app.ai_photo_originals_dir') . '/' . $restaurant->getId();

        return is_dir($dir) ? array_values(array_diff(scandir($dir), ['.', '..'])) : [];
    }

    private function menuVersion(Restaurant $restaurant): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT menu_content_version FROM restaurant WHERE id = ?', [$restaurant->getId()]);
    }

    private function revert(Product $product): array
    {
        $this->client->request('POST', '/admin/products/' . $product->getId() . '/image/original');

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testSavedOnTheDishAtOnceKeepingTheOriginalAside(): void
    {
        $product = $this->dishWithOwnPhoto();
        $restaurant = $product->getCategory()->getRestaurant();
        $original = $product->getImage();
        $originalBytes = file_get_contents($this->productsDir() . '/' . $original);
        $version = $this->menuVersion($restaurant);
        $this->client->loginUser($this->owner($restaurant));

        $data = $this->enhance($product);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $data['trialRemaining'], 'counts like a generated photo');
        self::assertSame(1, $this->aiPhotoCount($restaurant));

        // What the AI got: the fixed retouch instruction and the photo, reduced.
        self::assertCount(1, FakeDishImageGenerator::$edits);
        self::assertStringContainsString('made from the attached photo of a real dish', FakeDishImageGenerator::$prompts[0]);
        self::assertStringNotContainsString('Merluza', FakeDishImageGenerator::$prompts[0], 'no dish name: nothing to "fix" the food towards');
        $sent = FakeDishImageGenerator::$edits[0];
        self::assertSame('image/jpeg', $sent['mimeType']);
        self::assertSame('4:3', $sent['aspectRatio']);
        $size = getimagesizefromstring($sent['bytes']);
        self::assertSame(IMAGETYPE_JPEG, $size[2]);
        self::assertSame(1024, max($size[0], $size[1]), 'longest side reduced to 1024');

        // On the dish already, no Guardar needed.
        self::assertSame(Product::IMAGE_SOURCE_AI_EDIT, $data['imageSource']);
        self::assertTrue($data['canRevert']);
        $product = $this->reload($product);
        self::assertSame($data['image'], $product->getImage());
        self::assertSame(Product::IMAGE_SOURCE_AI_EDIT, $product->getImageSource());
        self::assertFileExists($this->productsDir() . '/' . $data['image']);
        self::assertGreaterThan($version, $this->menuVersion($restaurant), 'the public menu is refreshed');

        // The original: out of the public folder, kept aside, untouched.
        self::assertFileDoesNotExist($this->productsDir() . '/' . $original);
        self::assertSame([$data['image'] . '.png'], $this->keptOriginals($restaurant));
        $kept = static::getContainer()->getParameter('app.ai_photo_originals_dir') . '/' . $restaurant->getId() . '/' . $data['image'] . '.png';
        self::assertSame($originalBytes, file_get_contents($kept));

        // The editor knows it can go back.
        $this->client->request('GET', '/admin/products/' . $product->getId());
        self::assertTrue(json_decode($this->client->getResponse()->getContent(), true)['canRevert']);
    }

    public function testOtraStartsAgainFromTheOriginal(): void
    {
        $product = $this->dishWithOwnPhoto();
        $restaurant = $product->getCategory()->getRestaurant();
        $this->client->loginUser($this->owner($restaurant));

        $first = $this->enhance($product);
        $second = $this->enhance($this->reload($product));
        self::assertResponseIsSuccessful();

        self::assertSame(FakeDishImageGenerator::$edits[0]['bytes'], FakeDishImageGenerator::$edits[1]['bytes'], 'the original again, not the enhanced one');
        self::assertSame($second['image'], $this->reload($product)->getImage());
        self::assertFileDoesNotExist($this->productsDir() . '/' . $first['image']);
        self::assertSame([$second['image'] . '.png'], $this->keptOriginals($restaurant), 'still one original');
        self::assertSame(2, $this->aiPhotoCount($restaurant));
    }

    public function testBackToTheOriginal(): void
    {
        $product = $this->dishWithOwnPhoto();
        $restaurant = $product->getCategory()->getRestaurant();
        $originalBytes = file_get_contents($this->productsDir() . '/' . $product->getImage());
        $this->client->loginUser($this->owner($restaurant));
        $enhanced = $this->enhance($product)['image'];
        $version = $this->menuVersion($restaurant);

        $data = $this->revert($this->reload($product));
        self::assertResponseIsSuccessful();
        self::assertFalse($data['canRevert']);
        self::assertNull($data['imageSource']);

        $product = $this->reload($product);
        self::assertSame($data['image'], $product->getImage());
        self::assertNull($product->getImageSource());
        self::assertSame($originalBytes, file_get_contents($this->productsDir() . '/' . $product->getImage()));
        self::assertFileDoesNotExist($this->productsDir() . '/' . $enhanced);
        self::assertSame([], $this->keptOriginals($restaurant));
        self::assertGreaterThan($version, $this->menuVersion($restaurant), 'the public menu is refreshed');
        self::assertSame(1, $this->aiPhotoCount($restaurant), 'going back costs nothing');

        $again = $this->revert($product);
        self::assertResponseStatusCodeSame(410);
        self::assertSame('no_original', $again['reason']);
        self::assertSame('La foto original ya no está disponible.', $again['error']);

        $this->enhance($product);
        self::assertResponseIsSuccessful('the original can be enhanced again');
    }

    public function testAnyOtherPhotoChangeDropsTheOriginal(): void
    {
        $restaurant = $this->restaurant(paid: true);
        $this->client->loginUser($this->owner($restaurant));
        $uploaded = $this->dishWithOwnPhoto('Bravas', $restaurant);
        $removed = $this->dishWithOwnPhoto('Patatas', $restaurant);
        $generated = $this->dishWithOwnPhoto('Gazpacho', $restaurant);
        foreach ([$uploaded, $removed, $generated] as $product) {
            $this->enhance($product);
            self::assertResponseIsSuccessful();
        }
        self::assertCount(3, $this->keptOriginals($restaurant));

        $tmp = tempnam(sys_get_temp_dir(), 'own') . '.png';
        copy(FakeDishImageGenerator::defaultFixture(), $tmp);
        $this->client->request('POST', '/admin/products/' . $uploaded->getId() . '/image', files: [
            'image' => new UploadedFile($tmp, 'mine.png', 'image/png', null, true),
        ]);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/admin/products/' . $removed->getId() . '/image/delete');
        self::assertResponseIsSuccessful();
        $this->apply($generated, $this->generate(['name' => 'Gazpacho', 'productId' => $generated->getId()])['token']);
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->keptOriginals($restaurant));
        foreach ([$uploaded, $removed, $generated] as $product) {
            $this->revert($this->reload($product));
            self::assertResponseStatusCodeSame(410);
        }
    }

    public function testAnEnhancedPhotoWithoutItsOriginalIsNotEnhancedAgain(): void
    {
        $product = $this->dishWithOwnPhoto();
        $product->setImageSource(Product::IMAGE_SOURCE_AI_EDIT);
        $this->em->flush();
        $this->client->loginUser($this->owner($product->getCategory()->getRestaurant()));

        $data = $this->enhance($product);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_owner_photo', $data['reason']);
        self::assertSame('Solo se pueden mejorar las fotos que has subido tú.', $data['error']);
        self::assertSame([], FakeDishImageGenerator::$edits);
    }

    public function testOnlyTheOwnersOwnPhoto(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));

        $aiMade = $this->dishWithOwnPhoto('Pulpo', $restaurant);
        $aiMade->setImageSource(Product::IMAGE_SOURCE_AI);
        $withClip = $this->dishWithOwnPhoto('Gambas', $restaurant);
        $withClip->setVideoClip('clip.mp4');
        $this->em->flush();
        $noPhoto = $this->dish($this->category($restaurant), 'Flan');

        foreach ([$aiMade, $withClip, $noPhoto] as $product) {
            $data = $this->enhance($product);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('no_owner_photo', $data['reason']);
        }
        self::assertSame([], FakeDishImageGenerator::$edits, 'never reaches the AI');
        self::assertSame(0, $this->aiPhotoCount($restaurant), 'nothing counted');
    }

    public function testSharesTheTrialWithGenerate(): void
    {
        $product = $this->dishWithOwnPhoto();
        $restaurant = $product->getCategory()->getRestaurant();
        $this->client->loginUser($this->owner($restaurant));

        self::assertSame(1, $this->generate(['name' => 'Gazpacho'])['trialRemaining']);
        self::assertSame(0, $this->enhance($product)['trialRemaining']);

        $third = $this->enhance($product);
        self::assertResponseStatusCodeSame(402);
        self::assertSame('trial_limit', $third['reason']);
        self::assertSame(2, $this->aiPhotoCount($restaurant));
        self::assertCount(1, FakeDishImageGenerator::$edits);
    }

    public function testThePaidMonthlyLimitAppliesToo(): void
    {
        $restaurant = $this->restaurant(paid: true);
        $product = $this->dishWithOwnPhoto('Merluza', $restaurant);
        $this->client->loginUser($this->owner($restaurant));
        $this->useUpMonth($restaurant);

        $data = $this->enhance($product);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('monthly_limit', $data['reason']);
        self::assertSame(self::monthlyLimitMessage(), $data['error']);
        self::assertSame([], FakeDishImageGenerator::$edits);
    }

    public function testTheSameDishBrakeCountsGenerateAndEnhanceTogether(): void
    {
        $restaurant = $this->restaurant(paid: true);
        $product = $this->dishWithOwnPhoto('Lubina', $restaurant);
        $this->client->loginUser($this->owner($restaurant));

        for ($i = 1; $i <= 10; $i++) {
            $this->generate(['name' => 'Lubina', 'productId' => $product->getId()]);
            self::assertResponseIsSuccessful();
        }
        $data = $this->enhance($product);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $data['reason']);
        self::assertSame([], FakeDishImageGenerator::$edits);
    }

    public function testFailuresAreGivenBackWithTheirOwnMessage(): void
    {
        $product = $this->dishWithOwnPhoto();
        $restaurant = $product->getCategory()->getRestaurant();
        $this->client->loginUser($this->owner($restaurant));

        FakeDishImageGenerator::willFail(DishImageFailure::Refused);
        $data = $this->enhance($product);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('enhance_refused', $data['reason']);
        self::assertSame('No hemos podido mejorar esta foto. Prueba con otra foto del plato.', $data['error']);

        FakeDishImageGenerator::willFail(DishImageFailure::Unavailable);
        $this->enhance($product);
        self::assertResponseStatusCodeSame(503);

        self::assertSame(0, $this->aiPhotoCount($restaurant));
        self::assertNull($this->reload($product)->getImageSource(), 'the original is untouched');
    }

    public function testAnotherRestaurantsDishIsOffLimits(): void
    {
        $theirDish = $this->dishWithOwnPhoto();
        $mine = $this->restaurant();
        $this->client->loginUser($this->owner($mine));

        $this->enhance($theirDish);
        self::assertResponseStatusCodeSame(403);
        $this->revert($theirDish);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], FakeDishImageGenerator::$edits);
        self::assertSame(0, $this->aiPhotoCount($mine));
    }

    public function testWithoutTheImageKeyThereIsNoButtonAndNoCall(): void
    {
        $this->withoutImageKey();
        $product = $this->dishWithOwnPhoto();
        $restaurant = $product->getCategory()->getRestaurant();
        $this->client->loginUser($this->owner($restaurant));

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertCount(0, $crawler->filter('.ai-enhance-btn'));

        $data = $this->enhance($product);
        self::assertResponseStatusCodeSame(503);
        self::assertSame('not_configured', $data['reason']);
        self::assertSame([], FakeDishImageGenerator::$edits);
        self::assertSame(0, $this->aiPhotoCount($restaurant));
    }

    public function testTheEditorHasTheButtonHiddenUntilThePhotoIsKnownToBeTheOwners(): void
    {
        $product = $this->dishWithOwnPhoto();
        $this->client->loginUser($this->owner($product->getCategory()->getRestaurant()));

        $crawler = $this->client->request('GET', '/admin/menu');
        $button = $crawler->filter('.ai-enhance-btn')->first();
        self::assertSame('Mejorar con IA', trim($button->text()));
        self::assertNotNull($button->attr('hidden'), 'the editor JS shows it per dish');

        // What the editor reads to decide.
        $this->client->request('GET', '/admin/products/' . $product->getId());
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('imageSource', $data = json_decode($this->client->getResponse()->getContent(), true));
        self::assertNull($data['imageSource']);
    }

    public function testANewUploadOrRemovingThePhotoMakesItEnhanceableAgain(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));

        $uploaded = $this->dishWithOwnPhoto('Bravas', $restaurant);
        $uploaded->setImageSource(Product::IMAGE_SOURCE_AI_EDIT);
        $removed = $this->dishWithOwnPhoto('Patatas', $restaurant);
        $removed->setImageSource(Product::IMAGE_SOURCE_AI_EDIT);
        $this->em->flush();

        $tmp = tempnam(sys_get_temp_dir(), 'own') . '.png';
        copy(FakeDishImageGenerator::defaultFixture(), $tmp);
        $this->client->request('POST', '/admin/products/' . $uploaded->getId() . '/image', files: [
            'image' => new UploadedFile($tmp, 'mine.png', 'image/png', null, true),
        ]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->reload($uploaded)->getImageSource());

        $this->client->request('POST', '/admin/products/' . $removed->getId() . '/image/delete');
        self::assertResponseIsSuccessful();
        self::assertNull($this->reload($removed)->getImageSource());

        $this->enhance($this->reload($uploaded));
        self::assertResponseIsSuccessful('the new upload can be enhanced');
    }

    /** A photo under 400px is no longer refused: uploaded as is, and "Mejorar con IA" works on it. */
    public function testASmallPhotoIsAcceptedAndCanBeEnhanced(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));
        $product = $this->dish($this->category($restaurant), 'Croquetas');

        $tmp = tempnam(sys_get_temp_dir(), 'small') . '.png';
        copy(dirname(__DIR__) . '/fixtures/dish_photo/too_small_200x150.png', $tmp);
        $this->client->request('POST', '/admin/products/' . $product->getId() . '/image', files: [
            'image' => new UploadedFile($tmp, 'small.png', 'image/png', null, true),
        ]);
        self::assertResponseIsSuccessful();
        $product = $this->reload($product);
        self::assertNotNull($product->getImage());
        self::assertTrue($product->hasOwnerPhoto());

        $this->enhance($product);
        self::assertResponseIsSuccessful();
        $size = getimagesizefromstring(FakeDishImageGenerator::$edits[0]['bytes']);
        self::assertSame([200, 150], [$size[0], $size[1]], 'never upscaled before sending');
        self::assertSame('4:3', FakeDishImageGenerator::$edits[0]['aspectRatio']);
    }

    /** Whatever the original's shape, the result is asked in the cards' 4:3. */
    public function testAPortraitPhotoIsAskedBackIn43(): void
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));
        copy(dirname(__DIR__) . '/fixtures/upload_hero_portrait.jpg', $this->productsDir() . '/portrait.jpg');
        $product = $this->dish($this->category($restaurant), 'Tarta', 'portrait.jpg');

        $this->enhance($product);

        self::assertResponseIsSuccessful();
        $size = getimagesizefromstring(FakeDishImageGenerator::$edits[0]['bytes']);
        self::assertLessThan($size[1], $size[0], 'the portrait original is what gets sent');
        self::assertSame('4:3', FakeDishImageGenerator::$edits[0]['aspectRatio']);
    }

    /** The soft notice and its own "Mejorar con IA" are in the editor, hidden until the browser measures the photo. */
    public function testTheEditorHasTheSmallPhotoNotice(): void
    {
        $product = $this->dishWithOwnPhoto();
        $this->client->loginUser($this->owner($product->getCategory()->getRestaurant()));

        $crawler = $this->client->request('GET', '/admin/menu');
        $notice = $crawler->filter('.photo-small-notice')->first();
        self::assertNotNull($notice->attr('hidden'));
        self::assertSame('Esta foto es pequeña y puede verse borrosa en la carta.', trim($notice->filter('.photo-small-notice-text')->text()));
        self::assertSame('Mejorar con IA', trim($notice->filter('.ai-enhance-btn--notice')->text()));
        self::assertStringNotContainsString('400×400', $crawler->filter('.img-upload-hint-limits')->first()->text());
        self::assertStringContainsString('const SMALL_PHOTO_SIDE = 400;', $this->client->getResponse()->getContent());

        // Without the image key: the notice stays, no button in it.
        $this->withoutImageKey();
        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertCount(1, $crawler->filter('.photo-small-notice')->first());
        self::assertCount(0, $crawler->filter('.photo-small-notice .ai-enhance-btn'));
    }

    /** The public menu has no "IA" mark: none of its templates (everything outside admin/) reads image_source. */
    public function testThePublicTemplatesNeverReadTheImageSource(): void
    {
        $templates = dirname(__DIR__, 2) . '/templates';
        $hits = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templates, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = substr($file->getPathname(), strlen($templates) + 1);
            if (!str_starts_with($path, 'admin/') && preg_match('/imageSource|image_source|ai_edit/', (string) file_get_contents($file->getPathname()))) {
                $hits[] = $path;
            }
        }

        self::assertSame([], $hits);
    }
}
