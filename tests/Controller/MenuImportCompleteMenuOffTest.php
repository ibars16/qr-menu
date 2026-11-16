<?php

namespace App\Tests\Controller;

use App\Entity\MenuImportBatch;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Tests\Support\AiPhotoTestFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Importar menú"'s complete page for a restaurant whose public menu is
 * off — reached by the super-admin "como" the owner (the owner alone gets
 * only the expired page): "Tu carta está lista" instead of "ya está
 * publicada", and the AI photo box only while Service\AiAccess allows the
 * free photos (never paid, some left) — without any "prueba" — else just
 * "N platos no tienen foto". Always FakeDishImageGenerator, never a real AI.
 */
final class MenuImportCompleteMenuOffTest extends WebTestCase
{
    use AiPhotoTestFixtures;

    private KernelBrowser $client;
    private ?User $super = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->setUpAiPhoto();
    }

    protected function tearDown(): void
    {
        if ($this->super !== null) {
            $this->em->getConnection()->executeStatement('DELETE FROM "user" WHERE id = ?', [$this->super->getId()]);
        }
        $this->tearDownAiPhoto();
        parent::tearDown();
    }

    /** A published import of $withoutPhoto dishes without a photo, the restaurant turned off by $off, entered "como" its owner. */
    private function offImport(int $withoutPhoto, callable $off): MenuImportBatch
    {
        $restaurant = $this->restaurant();
        $owner = $this->owner($restaurant);
        $category = $this->category($restaurant);
        $batch = $this->importBatch($restaurant);
        for ($i = 1; $i <= $withoutPhoto; $i++) {
            $this->dish($category, 'Plato ' . $i, null, $batch, 'Descripción ' . $i)->setActive(true);
        }
        $off($restaurant);
        $this->em->flush();
        self::assertTrue($restaurant->isExpired());

        $this->super = new User();
        $this->super->setEmail('dish-pic-test-super-' . uniqid() . '@example.test');
        $this->super->setPassword('unused-in-tests');
        $this->super->setRoles([User::ROLE_SUPER_ADMIN]);
        $this->em->persist($this->super);
        $this->em->flush();

        $this->client->loginUser($this->super);
        $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($owner->getEmail()));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        return $batch;
    }

    private static function trialOver(Restaurant $restaurant): void
    {
        $restaurant->deactivateNow();
    }

    private static function expiredAfterPaying(Restaurant $restaurant): void
    {
        $restaurant->markPaid(Restaurant::PLAN_MONTHLY);
        (new \ReflectionProperty(Restaurant::class, 'paidUntil'))->setValue($restaurant, new \DateTimeImmutable('today -10 days'));
    }

    private function complete(MenuImportBatch $batch): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', '/admin/menu/import/' . $batch->getId() . '/complete');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    public function testMenuOffSaysReadyNotPublished(): void
    {
        $crawler = $this->complete($this->offImport(1, self::trialOver(...)));

        self::assertSame('Tu carta está lista', trim($crawler->filter('.import-complete-title')->text()));
        self::assertSame('Hemos añadido tus platos. Tus clientes la verán cuando se active el plan.', trim($crawler->filter('.import-complete-sub')->text()));
    }

    public function testNeverPaidKeepsTheFreePhotosWithoutTalkingOfATrial(): void
    {
        $batch = $this->offImport(3, self::trialOver(...));
        $box = $this->complete($batch)->filter('.ai-batch');

        self::assertCount(1, $box);
        self::assertSame('Te quedan 2 de 2 fotos con IA gratis.', trim($box->filter('.ai-batch-trial')->text()));
        self::assertCount(0, $box->filter('.ai-batch-trial-box, .ai-batch-trial-plan'));
        self::assertStringNotContainsStringIgnoringCase('prueba', $box->text());
        self::assertSame('Crear 2 fotos de ejemplo', trim($box->filter('.ai-batch-start')->text()));

        // And the run itself goes through (fake generator).
        $this->client->request('POST', '/admin/menu/import/' . $batch->getId() . '/photos/step', ['_token' => $box->attr('data-token')]);
        $result = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($result['generated'] ?? false);
        self::assertSame(1, $result['trialRemaining']);
    }

    public function testFreePhotosUsedLeavesOnlyTheMissingCount(): void
    {
        $batch = $this->offImport(3, self::trialOver(...));
        $this->em->getConnection()->executeStatement('UPDATE restaurant SET ai_photo_count = 2 WHERE id = ?', [$batch->getRestaurant()->getId()]);
        $crawler = $this->complete($batch);

        self::assertCount(0, $crawler->filter('.ai-batch'));
        self::assertSame('3 platos no tienen foto', trim($crawler->filter('.import-photos-missing')->text()));
    }

    public function testExpiredAfterPayingHasNoPhotos(): void
    {
        $crawler = $this->complete($this->offImport(1, self::expiredAfterPaying(...)));

        self::assertCount(0, $crawler->filter('.ai-batch'));
        self::assertSame('1 plato no tiene foto', trim($crawler->filter('.import-photos-missing')->text()));
        self::assertSame('Tu carta está lista', trim($crawler->filter('.import-complete-title')->text()));
    }
}
