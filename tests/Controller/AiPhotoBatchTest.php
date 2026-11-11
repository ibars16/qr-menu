<?php

namespace App\Tests\Controller;

use App\Entity\MenuImportBatch;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Service\DishPhoto\DishImageFailure;
use App\Tests\Support\AiPhotoTestFixtures;
use App\Tests\Support\FakeDishImageGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Crear sus fotos con IA" after an import (AiPhotoBatch through
 * DishPhotoController::importStep): one dish per request, only that
 * import's dishes without a photo, refusals skipped, provider down pauses,
 * resumable from Carta. Always FakeDishImageGenerator, never a real AI.
 */
final class AiPhotoBatchTest extends WebTestCase
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

    /** @return array{0: Restaurant, 1: MenuImportBatch, 2: Product[]} an import with $withoutPhoto dishes to do, one with a photo, and a dish of another import */
    private function importWithDishes(int $withoutPhoto, bool $paid = true): array
    {
        $restaurant = $this->restaurant($paid);
        $this->client->loginUser($this->owner($restaurant));
        $category = $this->category($restaurant);
        $batch = $this->importBatch($restaurant);
        $other = $this->importBatch($restaurant);

        $dishes = [];
        for ($i = 1; $i <= $withoutPhoto; $i++) {
            $dishes[] = $this->dish($category, 'Plato ' . $i, null, $batch, 'Descripción ' . $i);
        }
        $this->dish($category, 'Ya con foto', 'own.jpg', $batch);
        $this->dish($category, 'De otra importación', null, $other);
        $this->dish($category, 'Hecho a mano', null);

        return [$restaurant, $batch, $dishes];
    }

    private function token(MenuImportBatch $batch): string
    {
        $crawler = $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');
        self::assertResponseIsSuccessful();
        $box = $crawler->filter('.ai-batch');
        self::assertCount(1, $box, 'the offer is on the complete page');

        return $box->attr('data-token');
    }

    private function step(MenuImportBatch $batch, string $token): array
    {
        $this->client->request('POST', '/admin/menu/import/' . $batch->getId() . '/photos/step', ['_token' => $token]);

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function batch(MenuImportBatch $batch): MenuImportBatch
    {
        $this->em->clear();

        return $this->em->find(MenuImportBatch::class, $batch->getId());
    }

    public function testTheCompletePageOffersItWithTheCount(): void
    {
        [, $batch] = $this->importWithDishes(3);

        $crawler = $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');

        self::assertStringContainsString('3 platos no tienen foto', $crawler->filter('.ai-batch-message')->text());
        self::assertStringContainsString('Crear sus fotos con IA', $crawler->filter('.ai-batch-start')->text());
    }

    public function testOnlyThisImportsDishesWithoutPhotoOneByOne(): void
    {
        [$restaurant, $batch, $dishes] = $this->importWithDishes(2);
        $token = $this->token($batch);

        $first = $this->step($batch, $token);
        self::assertTrue($first['generated']);
        self::assertFalse($first['done']);
        self::assertSame(1, $first['remaining']);

        $second = $this->step($batch, $token);
        self::assertTrue($second['done']);
        self::assertSame(0, $second['remaining']);
        self::assertCount(2, FakeDishImageGenerator::$prompts);
        self::assertStringContainsString('«Plato 1»', FakeDishImageGenerator::$prompts[0]);
        self::assertStringContainsString('«Descripción 1»', FakeDishImageGenerator::$prompts[0]);

        foreach ($dishes as $dish) {
            $dish = $this->reload($dish);
            self::assertNotNull($dish->getImage());
            self::assertSame(Product::IMAGE_SOURCE_AI, $dish->getImageSource());
            self::assertFileExists($this->productsDir() . '/' . $dish->getImage());
            self::assertSame('image/jpeg', mime_content_type($this->productsDir() . '/' . $dish->getImage()));
        }
        $untouched = $this->em->getConnection()->fetchFirstColumn(
            "SELECT p.image FROM product p JOIN product_translation t ON t.product_id = p.id WHERE t.name IN ('Ya con foto', 'De otra importación', 'Hecho a mano') AND p.category_id IN (SELECT id FROM category WHERE restaurant_id = ?) ORDER BY t.name",
            [$restaurant->getId()],
        );
        self::assertSame([null, null, 'own.jpg'], $untouched, 'other dishes never touched');
        self::assertSame(MenuImportBatch::AI_PHOTOS_DONE, $this->batch($batch)->getAiPhotosStatus());
    }

    public function testARefusedDishIsSkippedForGood(): void
    {
        [, $batch, $dishes] = $this->importWithDishes(2);
        $token = $this->token($batch);

        FakeDishImageGenerator::willFail(DishImageFailure::Refused);
        $first = $this->step($batch, $token);
        self::assertFalse($first['generated']);
        self::assertNull($first['stopped']);
        self::assertSame(1, $first['skipped']);
        self::assertSame(1, $first['remaining']);

        $second = $this->step($batch, $token);
        self::assertTrue($second['done']);
        self::assertNull($this->reload($dishes[0])->getImage());
        self::assertNotNull($this->reload($dishes[1])->getImage());
        self::assertSame([$dishes[0]->getId()], $this->batch($batch)->getAiPhotosSkipped());

        $again = $this->step($batch, $token);
        self::assertTrue($again['done']);
        self::assertCount(2, FakeDishImageGenerator::$prompts, 'the skipped dish is never retried');
    }

    public function testProviderDownPausesAndCartaOffersToContinue(): void
    {
        [, $batch] = $this->importWithDishes(2);
        $token = $this->token($batch);

        FakeDishImageGenerator::willFail(DishImageFailure::Unavailable);
        $result = $this->step($batch, $token);

        self::assertSame('unavailable', $result['stopped']);
        self::assertSame('Ahora mismo no se puede crear la foto, prueba en unos minutos.', $result['message']);
        self::assertSame(2, $result['remaining'], 'nothing skipped: the dish is fine');
        self::assertSame(MenuImportBatch::AI_PHOTOS_PAUSED, $this->batch($batch)->getAiPhotosStatus());

        $crawler = $this->client->request('GET', '/admin/menu');
        $notice = $crawler->filter('.ai-batch[data-variant="notice"]');
        self::assertCount(1, $notice);
        self::assertStringContainsString('faltan 2 platos', $notice->text());

        $resumed = $this->step($batch, $notice->attr('data-token'));
        self::assertTrue($resumed['generated'], 'resumable');
    }

    public function testWithoutTheImageKeyNoOfferAndNoCartaNotice(): void
    {
        [, $batch] = $this->importWithDishes(2);
        $token = $this->token($batch);
        FakeDishImageGenerator::willFail(DishImageFailure::Unavailable);
        $this->step($batch, $token); // paused: Carta would offer "Continuar"

        $this->withoutImageKey();
        $crawler = $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.ai-batch'), 'no offer after the import');

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.ai-batch'), 'no notice in Carta');
    }

    public function testDismissHidesTheNotice(): void
    {
        [, $batch] = $this->importWithDishes(2);
        $token = $this->token($batch);
        $this->step($batch, $token);

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertCount(1, $crawler->filter('.ai-batch'), 'half done: on Carta');
        $form = $crawler->filter('.ai-batch form')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/menu');

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertCount(0, $crawler->filter('.ai-batch'));
        $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');
        self::assertCount(0, $this->client->getCrawler()->filter('.ai-batch'), 'nor offered again');
    }

    public function testTheTrialStopsAtTwoWithTheChoosePlanMessage(): void
    {
        [$restaurant, $batch] = $this->importWithDishes(3, paid: false);
        $token = $this->token($batch);

        self::assertTrue($this->step($batch, $token)['generated']);
        self::assertTrue($this->step($batch, $token)['generated']);
        $third = $this->step($batch, $token);

        self::assertSame('trial_limit', $third['stopped']);
        self::assertSame('Elige un plan para crear más fotos con IA.', $third['message']);
        self::assertSame(0, $third['trialRemaining']);
        self::assertSame(1, $third['remaining']);
        self::assertSame(2, $this->aiPhotoCount($restaurant));
        self::assertCount(2, FakeDishImageGenerator::$prompts);

        $crawler = $this->client->request('GET', '/admin/menu');
        $notice = $crawler->filter('.ai-batch');
        self::assertSame('stopped', $notice->attr('data-state'));
        self::assertStringContainsString('Elige un plan para crear más fotos con IA.', $notice->filter('.ai-batch-error')->text());
    }

    public function testAnotherRestaurantOrABadTokenIsRefused(): void
    {
        [, $batch] = $this->importWithDishes(1);
        $token = $this->token($batch);

        $this->client->request('POST', '/admin/menu/import/' . $batch->getId() . '/photos/step', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->owner($this->restaurant()));
        $this->client->request('POST', '/admin/menu/import/' . $batch->getId() . '/photos/step', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], FakeDishImageGenerator::$prompts);
    }
}
