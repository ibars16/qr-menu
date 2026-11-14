<?php

namespace App\Tests\Controller;

use App\Entity\MenuImportBatch;
use App\Entity\MenuImportPage;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\BatchProcessingTriggerInterface;
use App\Service\Upload\ContentModerationInterface;
use App\Service\Upload\ContentModerationResult;
use App\Tests\Support\TestUploadsDir;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Importar carta con IA" with PDFs: each PDF page becomes one JPEG page of
 * the batch (photos and PDFs can be mixed, 30 pages max in total), and any
 * failure — a bad PDF, a page that fails validation, a file that can't be
 * moved — leaves nothing behind: no batch, no pages, no files.
 *
 * The processing trigger is faked (it only records the batch), so no
 * background process and no AI ever runs here.
 */
final class MenuImportPdfUploadTest extends WebTestCase
{
    use TestUploadsDir;

    private const PDF_FIXTURES = __DIR__ . '/../fixtures/pdf';
    private const PHOTO_FIXTURE = __DIR__ . '/../fixtures/upload_png_150x150.png';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private object $trigger;
    private int $restaurantId;

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
        $this->loginOwner();
    }

    protected function tearDown(): void
    {
        $restaurant = $this->em->getRepository(Restaurant::class)->find($this->restaurantId);
        if ($restaurant) {
            foreach ($this->em->getRepository(MenuImportBatch::class)->findBy(['restaurant' => $restaurant]) as $batch) {
                $this->em->remove($batch);
            }
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            $this->em->remove($restaurant);
            $this->em->flush();
        }
        self::emptyTestUploadsDir();

        parent::tearDown();
    }

    private function loginOwner(): void
    {
        $restaurant = new Restaurant();
        $restaurant->setName('PDF import test');
        $restaurant->setSlug('pdf-import-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantId = $restaurant->getId();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('pdf-import-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    private function upload(string $source, string $clientName, string $clientMime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf_import_test_');
        copy($source, $path);

        return new UploadedFile($path, $clientName, $clientMime, null, true);
    }

    private function pdf(string $fixture, ?string $clientName = null): UploadedFile
    {
        return $this->upload(self::PDF_FIXTURES . '/' . $fixture, $clientName ?? $fixture, 'application/pdf');
    }

    private function photo(string $clientName = 'foto.png'): UploadedFile
    {
        return $this->upload(self::PHOTO_FIXTURE, $clientName, 'image/png');
    }

    /** @param UploadedFile[] $files */
    private function send(array $files): void
    {
        $this->client->request('POST', '/admin/menu/import/upload', [], ['images' => $files]);
    }

    /** @return MenuImportBatch[] */
    private function batches(): array
    {
        $this->em->clear();

        return $this->em->getRepository(MenuImportBatch::class)->findBy(['restaurant' => $this->restaurantId]);
    }

    /** @return string[] every file stored under this restaurant's import folder */
    private function storedFiles(): array
    {
        $dir = self::testUploadsDir() . '/menu-imports/' . $this->restaurantId;
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            $files[] = $entry->getPathname();
        }

        return $files;
    }

    /** @return string[] */
    private function tempWorkDirs(): array
    {
        return glob(sys_get_temp_dir() . '/menu-import-pdf-*') ?: [];
    }

    private function assertNothingSaved(string $flash): void
    {
        self::assertResponseRedirects('/admin/menu/import');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', $flash);
        self::assertSame([], $this->batches(), 'no batch (nor pages) may be left behind');
        self::assertSame([], $this->storedFiles(), 'no file may be left in the import folder');
        self::assertSame([], $this->trigger->triggered, 'processing never starts');
    }

    /** @return MenuImportPage[] ordered by position */
    private function pagesOf(MenuImportBatch $batch): array
    {
        $pages = $batch->getPages()->toArray();
        usort($pages, fn (MenuImportPage $a, MenuImportPage $b) => $a->getPosition() <=> $b->getPosition());

        return $pages;
    }

    public function testAThreePagePdfBecomesThreeJpegPages(): void
    {
        $tempBefore = $this->tempWorkDirs();

        $this->send([$this->pdf('three-pages.pdf', 'carta.pdf')]);

        $batches = $this->batches();
        self::assertCount(1, $batches);
        self::assertResponseRedirects('/admin/menu/import/' . $batches[0]->getId());
        self::assertSame([$batches[0]->getId()], $this->trigger->triggered);

        $pages = $this->pagesOf($batches[0]);
        self::assertCount(3, $pages);
        foreach ($pages as $i => $page) {
            self::assertSame($i, $page->getPosition());
            self::assertMatchesRegularExpression(
                '#^uploads/menu-imports/' . $this->restaurantId . '/' . $batches[0]->getId() . '/[0-9a-f]{32}\.jpg$#',
                $page->getImagePath(),
            );
            $absolute = self::testUploadsDir() . substr($page->getImagePath(), strlen('uploads'));
            self::assertSame('image/jpeg', mime_content_type($absolute));
            self::assertSame(hash_file('sha256', $absolute), $page->getImageHash());
        }
        self::assertCount(3, $this->storedFiles(), 'only the page JPEGs are stored — never the PDF');
        self::assertSame($tempBefore, $this->tempWorkDirs(), 'the temp folder is always removed');
    }

    public function testPhotosAndPdfsMixKeepingTheirOrder(): void
    {
        $this->send([$this->photo('portada.png'), $this->pdf('three-pages.pdf'), $this->photo('postres.png')]);

        $batches = $this->batches();
        self::assertCount(1, $batches);
        $paths = array_map(fn (MenuImportPage $p) => $p->getImagePath(), $this->pagesOf($batches[0]));
        self::assertCount(5, $paths);
        self::assertStringEndsWith('.png', $paths[0]);
        self::assertStringEndsWith('.jpg', $paths[1]);
        self::assertStringEndsWith('.jpg', $paths[2]);
        self::assertStringEndsWith('.jpg', $paths[3]);
        self::assertStringEndsWith('.png', $paths[4]);
    }

    public function testPhotosAloneStillWork(): void
    {
        $this->send([$this->photo()]);

        $batches = $this->batches();
        self::assertCount(1, $batches);
        self::assertCount(1, $batches[0]->getPages());
    }

    public function testARealPdfIsRecognisedByItsContentWhateverItsName(): void
    {
        $this->send([$this->pdf('three-pages.pdf', 'carta.jpg')]);

        $batches = $this->batches();
        self::assertCount(1, $batches);
        self::assertCount(3, $batches[0]->getPages());
    }

    public function testAFileNamedPdfThatIsNotReallyOneIsRejected(): void
    {
        $this->send([$this->upload(self::PHOTO_FIXTURE, 'carta.pdf', 'application/pdf')]);

        $this->assertNothingSaved('"carta.pdf" no se pudo abrir. Comprueba que es un PDF válido o sube fotos de la carta.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function badPdfs(): iterable
    {
        yield 'password' => ['password-protected.pdf', '"password-protected.pdf" está protegido con contraseña. Quítasela o sube fotos de la carta.'];
        yield 'broken' => ['broken.pdf', '"broken.pdf" no se pudo abrir. Comprueba que es un PDF válido o sube fotos de la carta.'];
        yield 'zero pages' => ['zero-pages.pdf', '"zero-pages.pdf" no tiene páginas.'];
    }

    #[DataProvider('badPdfs')]
    public function testABadPdfRejectsTheWholeUpload(string $fixture, string $flash): void
    {
        $this->send([$this->photo(), $this->pdf($fixture)]);

        $this->assertNothingSaved($flash);
    }

    public function testPdfPagesCountTowardsThe30PageCap(): void
    {
        $files = [];
        for ($i = 0; $i < 28; $i++) {
            $files[] = $this->photo("foto-{$i}.png");
        }
        $files[] = $this->pdf('three-pages.pdf', 'carta.pdf');

        $this->send($files);

        $this->assertNothingSaved('"carta.pdf" tiene 3 páginas y en este envío caben 2 como máximo. Súbelo en varias veces o quita páginas.');
    }

    public function testExactly30PagesFit(): void
    {
        $files = [];
        for ($i = 0; $i < 27; $i++) {
            $files[] = $this->photo("foto-{$i}.png");
        }
        $files[] = $this->pdf('three-pages.pdf');

        $this->send($files);

        $batches = $this->batches();
        self::assertCount(1, $batches);
        self::assertCount(30, $batches[0]->getPages());
    }

    public function testAPdfPageFailingValidationLeavesNothing(): void
    {
        // Moderation rejects the rendered PDF pages (they live in the temp
        // work folder) and lets the photo through.
        static::getContainer()->set(ContentModerationInterface::class, new class implements ContentModerationInterface {
            public function moderate(string $filePath, string $mimeType): ContentModerationResult
            {
                return str_contains($filePath, '/menu-import-pdf-') ? ContentModerationResult::rejected() : ContentModerationResult::allowed();
            }
        });
        $tempBefore = $this->tempWorkDirs();

        $this->send([$this->photo(), $this->pdf('three-pages.pdf', 'carta.pdf')]);

        $this->assertNothingSaved('"carta.pdf" no se pudo abrir.');
        self::assertSame($tempBefore, $this->tempWorkDirs(), 'the temp folder is removed on failure too');
    }

    public function testAFileThatCannotBeMovedRollsEverythingBack(): void
    {
        // The same uploaded file sent twice: both validate, the first move
        // takes it away, so the second move fails — after a PDF's three
        // pages and one photo have already been moved into the folder.
        $photo = $this->photo('postres.png');

        $this->send([$this->pdf('three-pages.pdf'), $photo, $photo]);

        $this->assertNothingSaved('"postres.png" no se pudo guardar — inténtalo de nuevo.');
    }

    public function testUploadScreenAcceptsPdf(): void
    {
        $crawler = $this->client->request('GET', '/admin/menu/import');

        self::assertStringContainsString('application/pdf', $crawler->filter('#import-file-input')->attr('accept'));
        self::assertSelectorTextContains('.import-drop-hint', 'Hasta 30 páginas (fotos o PDF), de 15 MB como máximo cada archivo.');
    }
}
