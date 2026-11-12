<?php

namespace App\Tests\Controller;

use App\Entity\MenuImportBatch;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Service\DishPhoto\AiPhotoBatch;
use App\Service\DishPhoto\DishImageFailure;
use App\Service\DishPhoto\DishPhotoQuota;
use App\Tests\Support\AiPhotoTestFixtures;
use App\Tests\Support\FakeDishImageGenerator;
use Random\Engine\Mt19937;
use Random\Randomizer;
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

    /** First carta (MenuImportAssembler publishes it): "ya está publicada" + the one-time "Revisa tu carta". */
    public function testTheCompletePageAsksToReviewAPublishedCarta(): void
    {
        [, $batch, $dishes] = $this->importWithDishes(2);
        $dishes[0]->setActive(true);
        $dishes[1]->setActive(false);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');

        self::assertSame('Tu carta ya está publicada', trim($crawler->filter('.import-complete-title')->text()));
        self::assertStringContainsString('Revisa tu carta', $crawler->filter('.import-review-notice')->text());
        self::assertStringContainsString('1 plato sin precio se ha quedado oculto', $crawler->filter('.import-review-notice')->text());
    }

    public function testTheCompletePageOfAHiddenImportKeepsItsText(): void
    {
        [, $batch] = $this->importWithDishes(1);
        $this->em->createQuery('UPDATE App\Entity\Product p SET p.active = false WHERE p.importBatch = :b')->setParameter('b', $batch)->execute();

        $crawler = $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');

        self::assertSame('Tu menú está listo para revisar', trim($crawler->filter('.import-complete-title')->text()));
        self::assertCount(0, $crawler->filter('.import-review-notice'));
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

    /**
     * Trial import: 4 dishes in a first category (lowest ids), 1 in a second
     * — so taking dishes in id order would never reach the second one.
     *
     * @return array{0: Restaurant, 1: MenuImportBatch, 2: Product[], 3: Product}
     */
    private function trialImportInTwoCategories(): array
    {
        $restaurant = $this->restaurant();
        $this->client->loginUser($this->owner($restaurant));
        $batch = $this->importBatch($restaurant);
        $first = $this->category($restaurant);
        $second = $this->category($restaurant);
        $many = [];
        for ($i = 1; $i <= 4; $i++) {
            $many[] = $this->dish($first, 'Primero ' . $i, null, $batch);
        }

        return [$restaurant, $batch, $many, $this->dish($second, 'Segundo', null, $batch)];
    }

    private function pay(Restaurant $restaurant): void
    {
        $this->em->clear();
        $this->em->find(Restaurant::class, $restaurant->getId())->markPaid(Restaurant::PLAN_MONTHLY);
        $this->em->flush();
    }

    private function spendTrial(Restaurant $restaurant, int $photos = DishPhotoQuota::TRIAL_LIMIT): void
    {
        $this->em->getConnection()->executeStatement('UPDATE restaurant SET ai_photo_count = ? WHERE id = ?', [$photos, $restaurant->getId()]);
    }

    public function testTheTrialMakesTwoSamplesAcrossCategoriesAndStops(): void
    {
        [$restaurant, $batch, $many, $only] = $this->trialImportInTwoCategories();

        $crawler = $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');
        $box = $crawler->filter('.ai-batch');
        self::assertStringContainsString('5 platos no tienen foto', $box->filter('.ai-batch-message')->text());
        self::assertSame('Te quedan 2 de 2 fotos con IA gratis.', trim($box->filter('.ai-batch-trial')->text()));
        self::assertSame('Tu prueba gratuita incluye 2 fotos con IA', trim($box->filter('.ai-batch-trial-title')->text()));
        self::assertSame('/admin/billing', parse_url((string) $box->filter('.ai-batch-trial-plan')->attr('href'), PHP_URL_PATH));
        self::assertSame('La prueba sigue siendo gratis. Con un plan tendrás más fotos con IA cada mes.', trim($box->filter('.ai-batch-trial-explain')->text()));
        self::assertSame('Crear 2 fotos de ejemplo', trim($box->filter('.ai-batch-start')->text()));
        self::assertSame('2', $box->attr('data-total'));
        $token = $box->attr('data-token');

        $result = $this->step($batch, $token);
        self::assertTrue($result['generated']);
        self::assertFalse($result['done']);
        self::assertSame(1, $result['trialRemaining']);
        $last = $this->step($batch, $token);
        self::assertTrue($last['generated']);
        self::assertTrue($last['done']);
        self::assertTrue($last['trialEnded']);
        self::assertSame(3, $last['remaining']);
        self::assertSame(0, $last['trialRemaining']);

        self::assertNotNull($this->reload($only)->getImage(), 'the other category gets one');
        $withPhoto = array_filter($many, fn (Product $p) => $this->reload($p)->getImage() !== null);
        self::assertCount(1, $withPhoto);
        self::assertSame(MenuImportBatch::AI_PHOTOS_TRIAL, $this->batch($batch)->getAiPhotosStatus());

        $again = $this->step($batch, $token);
        self::assertFalse($again['generated'], 'and it stops there');
        self::assertTrue($again['trialEnded']);
        self::assertCount(2, FakeDishImageGenerator::$prompts);
        self::assertSame(2, $this->aiPhotoCount($restaurant));

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertCount(0, $crawler->filter('.ai-batch'), 'no Carta notice while on the trial');
    }

    public function testOneSpentInTheEditorLeavesOneForTheImport(): void
    {
        [$restaurant, $batch] = $this->trialImportInTwoCategories();
        $this->client->request('POST', '/admin/products/ai-photo/generate', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => 'Gazpacho']));
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');
        $box = $crawler->filter('.ai-batch');
        self::assertSame('Te quedan 1 de 2 fotos con IA gratis.', trim($box->filter('.ai-batch-trial')->text()));
        self::assertSame('Crear 1 foto de ejemplo', trim($box->filter('.ai-batch-start')->text()));
        self::assertSame('1', $box->attr('data-total'));

        $last = $this->step($batch, $box->attr('data-token'));
        self::assertTrue($last['trialEnded']);
        self::assertSame(4, $last['remaining']);
        self::assertSame(2, $this->aiPhotoCount($restaurant));
        self::assertCount(2, FakeDishImageGenerator::$prompts, '1 in the editor + 1 in the import');
    }

    public function testALaterImportWithoutFreePhotosOnlySaysChooseAPlan(): void
    {
        [$restaurant, $batch] = $this->trialImportInTwoCategories();
        $token = $this->token($batch);
        do {
            $result = $this->step($batch, $token);
        } while (!$result['done']);

        $this->em->clear();
        $restaurant = $this->em->find(Restaurant::class, $restaurant->getId());
        $second = $this->importBatch($restaurant);
        $category = $this->category($restaurant);
        $this->dish($category, 'Postre 1', null, $second);
        $this->dish($category, 'Postre 2', null, $second);

        $crawler = $this->client->request('GET', '/admin/menu/import/' . $second->getId() . '/complete');
        $box = $crawler->filter('.ai-batch');
        self::assertCount(1, $box);
        self::assertSame('stopped', $box->attr('data-state'));
        self::assertNotNull($box->filter('.ai-batch-start')->attr('hidden'), 'no button');
        self::assertCount(0, $box->filter('.ai-batch-trial'), 'no counter');
        self::assertSame('Ya has usado tus 2 fotos con IA gratis. Elige un plan para crear las fotos de los 2 platos que faltan.', trim($box->filter('.ai-batch-plan-text')->text()));
        self::assertNull($box->filter('.ai-batch-plan')->attr('hidden'));

        // Every dish already with a photo: still the message, without the count.
        $third = $this->importBatch($restaurant);
        $this->dish($category, 'Con foto', 'own.jpg', $third);
        $crawler = $this->client->request('GET', '/admin/menu/import/' . $third->getId() . '/complete');
        $box = $crawler->filter('.ai-batch');
        self::assertCount(0, $box->filter('.ai-batch-message'));
        self::assertSame('Ya has usado tus 2 fotos con IA gratis. Elige un plan para crear más.', trim($box->filter('.ai-batch-plan-text')->text()));
        self::assertCount(2, FakeDishImageGenerator::$prompts);
    }

    public function testAPaidPlanShowsNoCounter(): void
    {
        [, $batch] = $this->importWithDishes(3);
        $crawler = $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');
        self::assertCount(0, $crawler->filter('.ai-batch-trial'));
        self::assertCount(0, $crawler->filter('.ai-batch-plan-text:not([hidden])'));

        [, $empty] = $this->importWithDishes(0);
        $this->client->request('GET', '/admin/menu/import/' . $empty->getId() . '/complete');
        self::assertCount(0, $this->client->getCrawler()->filter('.ai-batch'), 'nothing to do, nothing shown');
    }

    public function testTheTrialPicksAtRandomPreferringAnUnusedCategory(): void
    {
        [, , $many, $only] = $this->trialImportInTwoCategories();
        $pending = [...$many, $only];
        $firstCategory = $many[0]->getCategory()->getId();

        $picks = [];
        for ($seed = 1; $seed <= 40; $seed++) {
            $picks[AiPhotoBatch::pickForTrial($many, [], new Randomizer(new Mt19937($seed)))] = true;
            self::assertSame(4, AiPhotoBatch::pickForTrial($pending, [$firstCategory], new Randomizer(new Mt19937($seed))), 'the other category first');
        }
        self::assertGreaterThan(1, count($picks), 'not always the same dish');
    }

    public function testWithTheTrialPhotosAlreadySpentThereIsNoRun(): void
    {
        [$restaurant, $batch] = $this->trialImportInTwoCategories();
        $this->spendTrial($restaurant);

        $crawler = $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');
        $box = $crawler->filter('.ai-batch');
        self::assertSame('stopped', $box->attr('data-state'));
        self::assertNotNull($box->filter('.ai-batch-start')->attr('hidden'), 'no run offered');
        self::assertNull($box->filter('.ai-batch-plan-text')->attr('hidden'));
        self::assertSame('Ya has usado tus 2 fotos con IA gratis. Elige un plan para crear las fotos de los 5 platos que faltan.', trim($box->filter('.ai-batch-plan-text')->text()));
        self::assertNull($box->filter('.ai-batch-plan')->attr('hidden'));

        $result = $this->step($batch, $box->attr('data-token'));
        self::assertFalse($result['generated']);
        self::assertTrue($result['trialEnded']);
        self::assertSame([], FakeDishImageGenerator::$prompts);
        self::assertSame(MenuImportBatch::AI_PHOTOS_TRIAL, $this->batch($batch)->getAiPhotosStatus());
    }

    public function testAfterPayingCartaOffersTheRestAndMakesThem(): void
    {
        [$restaurant, $batch, $many, $only] = $this->trialImportInTwoCategories();
        $token = $this->token($batch);
        foreach (range(1, DishPhotoQuota::TRIAL_LIMIT) as $i) {
            $this->step($batch, $token);
        }

        $this->pay($restaurant);
        $crawler = $this->client->request('GET', '/admin/menu');
        $notice = $crawler->filter('.ai-batch[data-variant="notice"]');
        self::assertCount(1, $notice);
        self::assertStringContainsString('3 platos sin foto de tu carta importada', $notice->filter('.ai-batch-message')->text());
        self::assertSame('Crear sus fotos con IA', trim($notice->filter('.ai-batch-start')->text()));
        self::assertSame('3', $notice->attr('data-total'));
        self::assertCount(0, $notice->filter('.ai-batch-trial'), 'no counter once paid');
        self::assertCount(1, $notice->filter('form'), '"Dejarlo"');

        $token = $notice->attr('data-token');
        do {
            $result = $this->step($batch, $token);
            self::assertFalse($result['trialEnded']);
        } while (!$result['done'] && count(FakeDishImageGenerator::$prompts) < 10);

        self::assertCount(5, FakeDishImageGenerator::$prompts);
        foreach ([...$many, $only] as $dish) {
            self::assertNotNull($this->reload($dish)->getImage());
        }
        self::assertSame(MenuImportBatch::AI_PHOTOS_DONE, $this->batch($batch)->getAiPhotosStatus());
        self::assertCount(0, $this->client->request('GET', '/admin/menu')->filter('.ai-batch'));
    }

    public function testWithACardSetButNotChargedItNeverSaysChooseAPlan(): void
    {
        [$restaurant, $batch] = $this->trialImportInTwoCategories();
        $this->setCardPending($restaurant);

        $crawler = $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');
        $box = $crawler->filter('.ai-batch');
        self::assertSame('1', $box->attr('data-card-pending'));
        self::assertSame('Te quedan 2 de 2 fotos con IA gratis.', trim($box->filter('.ai-batch-trial')->text()));
        self::assertSame('Tendrás más fotos cuando empiece tu plan.', trim($box->filter('.ai-batch-trial-explain')->text()));
        self::assertCount(0, $box->filter('.ai-batch-trial-plan'), 'no "Elegir plan" link');
        self::assertSame('Crear 2 fotos de ejemplo', trim($box->filter('.ai-batch-start')->text()));

        $this->spendTrial($restaurant);
        $crawler = $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');
        $box = $crawler->filter('.ai-batch');
        self::assertSame('Ya has usado tus 2 fotos con IA gratis. Tendrás más fotos cuando empiece tu plan.', trim($box->filter('.ai-batch-plan-text')->text()));
        self::assertNotNull($box->filter('.ai-batch-plan')->attr('hidden'), 'no "Elegir plan" button');
        self::assertNotNull($box->filter('.ai-batch-start')->attr('hidden'));

        $result = $this->step($batch, $box->attr('data-token'));
        self::assertTrue($result['trialEnded']);
        self::assertSame([], FakeDishImageGenerator::$prompts);
    }

    public function testPaidWithThisMonthsPhotosUsedUpOffersNoRun(): void
    {
        [$restaurant, $batch] = $this->importWithDishes(3);
        $this->useUpMonth($restaurant);

        $crawler = $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');
        $box = $crawler->filter('.ai-batch');
        self::assertSame('stopped', $box->attr('data-state'));
        self::assertCount(0, $box->filter('.ai-batch-trial'), 'no counter');
        self::assertNull($box->filter('.ai-batch-error')->attr('hidden'));
        self::assertSame(self::monthlyLimitMessage(), trim($box->filter('.ai-batch-error-text')->text()));
        self::assertNotNull($box->filter('.ai-batch-start')->attr('hidden'));
        self::assertNotNull($box->filter('.ai-batch-plan')->attr('hidden'));

        $result = $this->step($batch, $box->attr('data-token'));
        self::assertFalse($result['generated']);
        self::assertSame('monthly_limit', $result['stopped']);
        self::assertSame(self::monthlyLimitMessage(), $result['message']);
        self::assertSame([], FakeDishImageGenerator::$prompts);
        self::assertSame(MenuImportBatch::AI_PHOTOS_PAUSED, $this->batch($batch)->getAiPhotosStatus());
    }

    public function testAfterPayingTheNoticeAlsoComesWhenTheTrialRunNeverHappened(): void
    {
        [$restaurant, $batch] = $this->trialImportInTwoCategories();
        $this->spendTrial($restaurant);
        $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');

        $this->pay($restaurant);
        $notice = $this->client->request('GET', '/admin/menu')->filter('.ai-batch');
        self::assertStringContainsString('5 platos sin foto de tu carta importada', $notice->text());
    }

    public function testAPaidPlanMakesThemAll(): void
    {
        [, $batch, $dishes] = $this->importWithDishes(3);

        $crawler = $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');
        $box = $crawler->filter('.ai-batch');
        self::assertSame('Crear sus fotos con IA', trim($box->filter('.ai-batch-start')->text()));
        self::assertSame('3', $box->attr('data-total'));

        $token = $box->attr('data-token');
        foreach ([2, 1, 0] as $remaining) {
            $result = $this->step($batch, $token);
            self::assertTrue($result['generated']);
            self::assertFalse($result['trialEnded']);
            self::assertSame($remaining, $result['remaining']);
        }
        self::assertTrue($result['done']);
        foreach ($dishes as $dish) {
            self::assertNotNull($this->reload($dish)->getImage());
        }
        self::assertSame(MenuImportBatch::AI_PHOTOS_DONE, $this->batch($batch)->getAiPhotosStatus(), 'never marked as a trial import');
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
