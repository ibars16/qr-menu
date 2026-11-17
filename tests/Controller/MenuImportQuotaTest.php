<?php

namespace App\Tests\Controller;

use App\Entity\MenuImportBatch;
use App\Entity\MenuImportPage;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\BatchProcessingTriggerInterface;
use App\Service\MenuImportQuota;
use App\Tests\Support\TestUploadsDir;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * "Importar carta" a day per restaurant (App\Service\MenuImportQuota): 5
 * uploads and 60 pages, 2 uploads while the email isn't confirmed. Past it
 * the import screen shows why and nothing is stored or processed.
 *
 * Earlier uploads are made as rows (batch + pages) dated as needed; the
 * processing trigger is faked, so no background process and no AI runs.
 */
final class MenuImportQuotaTest extends WebTestCase
{
    use TestUploadsDir;

    private const PHOTO_FIXTURE = __DIR__ . '/../fixtures/upload_png_150x150.png';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private object $trigger;
    private int $restaurantId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->trigger = new class implements BatchProcessingTriggerInterface {
            /** @var int[] */
            public array $triggered = [];

            public function trigger(MenuImportBatch $batch): void
            {
                $this->triggered[] = $batch->getId();
            }
        };
        static::getContainer()->set(BatchProcessingTriggerInterface::class, $this->trigger);
        self::testUploadsDir();

        $restaurant = new Restaurant();
        $restaurant->setName('Import quota test');
        $restaurant->setSlug('import-quota-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);

        $user = new User();
        $user->setEmail('import-quota-test-' . uniqid() . '@example.test');
        $user->setPassword('unused-in-tests');
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $this->restaurantId = $restaurant->getId();
        $this->userId = $user->getId();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $restaurant = $this->em->find(Restaurant::class, $this->restaurantId);
        foreach ($this->em->getRepository(MenuImportBatch::class)->findBy(['restaurant' => $restaurant]) as $batch) {
            $this->em->remove($batch);
        }
        foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
            $this->em->remove($user);
        }
        $this->em->remove($restaurant);
        $this->em->flush();
        self::emptyTestUploadsDir();

        parent::tearDown();
    }

    /** An earlier upload of $pages pages, made $when (DB rows only). */
    private function earlierUpload(int $pages, string $when = 'now'): void
    {
        $batch = new MenuImportBatch($this->em->find(Restaurant::class, $this->restaurantId));
        $this->em->persist($batch);
        for ($i = 0; $i < $pages; $i++) {
            $page = new MenuImportPage($batch, 'uploads/menu-imports/x/' . $i . '.jpg', $i, str_repeat('0', 64));
            $this->em->persist($page);
            $batch->addPage($page);
        }
        $this->em->flush();
        static::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE menu_import_batch SET created_at = ? WHERE id = ?',
            [(new \DateTimeImmutable($when))->format('Y-m-d H:i:s'), $batch->getId()],
        );
    }

    private function sendPhotos(int $count): void
    {
        $files = [];
        for ($i = 0; $i < $count; $i++) {
            $path = tempnam(sys_get_temp_dir(), 'import_quota_test_');
            copy(self::PHOTO_FIXTURE, $path);
            $files[] = new UploadedFile($path, "foto{$i}.png", 'image/png', null, true);
        }
        $this->client->request('POST', '/admin/menu/import/upload', [], ['images' => $files]);
    }

    private function batchCount(): int
    {
        $this->em->clear();

        return count($this->em->getRepository(MenuImportBatch::class)->findBy(['restaurant' => $this->restaurantId]));
    }

    private function assertRefused(string $flash, int $batchesBefore): void
    {
        self::assertResponseRedirects('/admin/menu/import');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', $flash);
        self::assertSame($batchesBefore, $this->batchCount(), 'nothing stored');
        self::assertSame([], $this->trigger->triggered, 'processing never starts');
    }

    public function testFiveUploadsADay(): void
    {
        for ($i = 0; $i < MenuImportQuota::MAX_UPLOADS - 1; $i++) {
            $this->earlierUpload(1);
        }
        $this->earlierUpload(30, 'yesterday 23:30'); // yesterday's don't count

        $this->sendPhotos(1);
        self::assertResponseRedirects();
        self::assertCount(1, $this->trigger->triggered, 'the 5th of today goes through');

        $this->trigger->triggered = [];
        $this->sendPhotos(1);
        $this->assertRefused('Hoy ya has importado el máximo de cartas. Podrás volver a importar mañana.', 6);
    }

    public function testSixtyPagesADay(): void
    {
        $this->earlierUpload(30);
        $this->earlierUpload(27);

        $this->sendPhotos(4);
        $this->assertRefused('Hoy solo puedes importar 3 páginas más.', 2);

        $this->sendPhotos(3);
        self::assertResponseRedirects();
        self::assertCount(1, $this->trigger->triggered, 'exactly up to 60 is fine');

        $this->trigger->triggered = [];
        $this->sendPhotos(1);
        $this->assertRefused('Hoy ya has importado el máximo de cartas.', 3);
    }

    public function testTwoUploadsADayUntilTheEmailIsConfirmed(): void
    {
        $user = $this->em->find(User::class, $this->userId);
        $user->setEmailConfirmationPending(true);
        $this->em->flush();
        $this->earlierUpload(1);
        $this->earlierUpload(1);
        $this->em->clear(); // the restaurant reloads with its users (built here with none)

        $this->sendPhotos(1);
        $this->assertRefused('Hasta que confirmes tu email puedes importar 2 veces al día.', 2);

        // Confirmed: the usual 5.
        $this->em->clear();
        $user = $this->em->find(User::class, $this->userId);
        $user->setEmailConfirmationPending(false);
        $this->em->flush();
        $this->sendPhotos(1);
        self::assertResponseRedirects();
        self::assertCount(1, $this->trigger->triggered);
    }

    public function testOffWhenTheAbuseLimitsAreOff(): void
    {
        $restaurant = $this->em->find(Restaurant::class, $this->restaurantId);
        for ($i = 0; $i < MenuImportQuota::MAX_UPLOADS; $i++) {
            $this->earlierUpload(30);
        }
        $connection = static::getContainer()->get(Connection::class);

        self::assertNotNull((new MenuImportQuota($connection, true))->refusal($restaurant));
        self::assertNull((new MenuImportQuota($connection, false))->refusal($restaurant), 'dev: never refused');
    }
}
