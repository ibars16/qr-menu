<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Tests\Support\TestUploadsDir;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Process\Process;

/**
 * Functional coverage for MenuAdminController::uploadProductClip — the
 * upload endpoint for Product::$videoClip. Real HTTP requests through the
 * actual route, same approach as SettingsControllerCrossTenantLogoTest /
 * MenuAdminMissingPhotoNoticeTest.
 *
 * The endpoint runs FFmpeg (ClipTranscoder: trim + poster), so accepted
 * clips here are real, decodable videos generated with FFmpeg's own test
 * source (realClipUpload()) — FFmpeg is in the app's Docker image. The
 * hand-built header-only MP4 (makeMp4Bytes(), no decodable frames) is
 * still used where that's the point: rejected-before-FFmpeg durations, and
 * FFmpeg failing on a file the validator accepted. MIME/size/duration edge
 * cases are covered as pure logic in UploadValidatorTest.
 */
final class MenuAdminControllerClipUploadTest extends WebTestCase
{
    use TestUploadsDir;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $productsDir;

    /** @var int[] */
    private array $restaurantIdsToRemove = [];

    /** @var array<string|null> filenames to delete in tearDown (null when an upload failed) */
    private array $clipFilesToRemove = [];

    /** @var array<string|null> */
    private array $imageFilesToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        // var/test-uploads/products, never public/uploads (see TestUploadsDir).
        $this->productsDir = self::testUploadsDir('products') . '/products';
    }

    protected function tearDown(): void
    {
        foreach ([...$this->clipFilesToRemove, ...$this->imageFilesToRemove] as $filename) {
            if ($filename && is_file($this->productsDir . '/' . $filename)) {
                unlink($this->productsDir . '/' . $filename);
            }
        }
        self::emptyTestUploadsDir();

        foreach ($this->restaurantIdsToRemove as $id) {
            $restaurant = $this->em->getRepository(Restaurant::class)->find($id);
            if (!$restaurant) {
                continue;
            }
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            foreach ($this->em->getRepository(Category::class)->findBy(['restaurant' => $restaurant]) as $category) {
                foreach ($this->em->getRepository(Product::class)->findBy(['category' => $category]) as $product) {
                    $this->em->remove($product);
                }
                $this->em->remove($category);
            }
            $this->em->remove($restaurant);
        }
        $this->em->flush();

        parent::tearDown();
    }

    /** @return array{0: User, 1: int} the owner and the id of a fresh product belonging to them */
    private function makeRestaurantWithOwnerAndProduct(string $name): array
    {
        $restaurant = new Restaurant();
        $restaurant->setName($name);
        $restaurant->setSlug('clip-upload-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantIdsToRemove[] = $restaurant->getId();

        $category = new Category();
        $category->setRestaurant($restaurant);
        $this->em->persist($category);

        $product = new Product();
        $product->setCategory($category);
        $product->setBasePrice(1000);
        $this->em->persist($product);
        $this->em->flush();
        $productId = $product->getId();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('clip-upload-test-' . uniqid() . '@example.test');
        $user->setPassword($hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        // Force a genuine reload on the next request — see
        // MenuAdminMissingPhotoNoticeTest's own note on why this matters
        // (static::getContainer() survives the client's kernel reboot).
        $this->em->clear();

        return [$user, $productId];
    }

    /**
     * A minimal, well-formed MP4 (ftyp + moov/mvhd version 0 + mdat) with an
     * exact duration via a 1000-unit timescale — no ffmpeg involved
     * anywhere, matching the "camino A, sin ffmpeg" decision this whole
     * feature is built on.
     */
    private function makeMp4Bytes(float $durationSeconds): string
    {
        $ftypData = 'isom' . pack('N', 0x200) . 'isomiso2avc1mp41';
        $ftyp = pack('N', 8 + \strlen($ftypData)) . 'ftyp' . $ftypData;

        $timescale = 1000;
        $duration = (int) round($durationSeconds * $timescale);
        $mvhdBody = \chr(0) . "\x00\x00\x00"
            . pack('N', 0)              // creation_time
            . pack('N', 0)              // modification_time
            . pack('N', $timescale)
            . pack('N', $duration)
            . pack('N', 0x00010000)     // rate
            . pack('n', 0x0100) . "\x00\x00" // volume + reserved
            . str_repeat("\x00", 8)     // reserved
            . str_repeat("\x00", 36)    // matrix (shortened — irrelevant to duration parsing)
            . str_repeat("\x00", 24)    // pre_defined
            . pack('N', 2);             // next_track_id
        $mvhd = pack('N', 8 + \strlen($mvhdBody)) . 'mvhd' . $mvhdBody;
        $moov = pack('N', 8 + \strlen($mvhd)) . 'moov' . $mvhd;

        $mdat = pack('N', 16) . 'mdat' . str_repeat("\x00", 8);

        return $ftyp . $moov . $mdat;
    }

    /** A real, decodable MP4 (FFmpeg testsrc, 320x240, 10 fps) of the given length. */
    private function realClipUpload(int $durationSeconds): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'clip_upload_test_') . '.mp4';
        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-loglevel', 'error',
            '-f', 'lavfi', '-i', "testsrc=size=320x240:rate=10:duration={$durationSeconds}",
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $path,
        ]);
        $process->mustRun();

        return new UploadedFile($path, 'clip.mp4', 'video/mp4', null, true);
    }

    private function storedDurationSeconds(string $filename): float
    {
        $process = new Process([
            'ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0',
            $this->productsDir . '/' . $filename,
        ]);
        $process->mustRun();

        return (float) trim($process->getOutput());
    }

    /** Header-only MP4 — valid to UploadValidator, undecodable to FFmpeg. */
    private function clipUpload(float $durationSeconds): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'clip_upload_test_');
        file_put_contents($path, $this->makeMp4Bytes($durationSeconds));

        return new UploadedFile($path, 'clip.mp4', 'video/mp4', null, true);
    }

    /**
     * Real static fixture (800x400 JPEG, already committed for the hero-image
     * tests) copied to a fresh temp path — UploadedFile::move() consumes its
     * source file even in test mode, so pointing straight at the fixture
     * would break every later test reusing it. 800x400 clears dish_image's
     * own 400x400 minimum in both dimensions; no GD needed.
     */
    private function photoUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'photo_upload_test_');
        copy(__DIR__ . '/../fixtures/upload_hero_ok.jpg', $path);

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }

    public function testUploadingAValidClipSetsVideoClipAndPosterAndDeletesThePreviousOnes(): void
    {
        [$owner, $productId] = $this->makeRestaurantWithOwnerAndProduct('Clip Upload Test');
        $this->client->loginUser($owner);

        $this->client->request('POST', "/admin/products/{$productId}/clip", [], [
            'clip' => $this->realClipUpload(3),
        ]);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $firstClip = $data['videoClip'] ?? null;
        $firstPoster = $data['image'] ?? null;
        $this->clipFilesToRemove[] = $firstClip;
        $this->imageFilesToRemove[] = $firstPoster;
        self::assertResponseIsSuccessful();
        self::assertFalse($data['trimmed'], 'a clip under the cap is stored as-is');
        self::assertFileExists($this->productsDir . '/' . $firstClip);
        self::assertFileExists($this->productsDir . '/' . $firstPoster, 'the poster is extracted server-side, same request');
        self::assertStringEndsWith('.jpg', $firstPoster);

        // Replace it — both old files must be gone once the new ones land,
        // same cleanup pattern as uploadProductImage().
        $this->client->request('POST', "/admin/products/{$productId}/clip", [], [
            'clip' => $this->realClipUpload(4),
        ]);
        $data2 = json_decode($this->client->getResponse()->getContent(), true);
        $this->clipFilesToRemove[] = $data2['videoClip'] ?? null;
        $this->imageFilesToRemove[] = $data2['image'] ?? null;
        self::assertResponseIsSuccessful();

        self::assertNotSame($firstClip, $data2['videoClip']);
        self::assertFileDoesNotExist($this->productsDir . '/' . $firstClip, 'old clip must be deleted on replace');
        self::assertFileDoesNotExist($this->productsDir . '/' . $firstPoster, 'old poster must be deleted on replace');

        $this->em->clear();
        $product = $this->em->getRepository(Product::class)->find($productId);
        self::assertSame($data2['videoClip'], $product->getVideoClip());
        self::assertSame($data2['image'], $product->getImage());
        self::assertSame(round(320 / 240, 4), $product->getVideoAspectRatio(), 'dimensions are read with ffprobe on upload');
    }

    public function testAClipOverSixSecondsIsTrimmedInsteadOfRejected(): void
    {
        [$owner, $productId] = $this->makeRestaurantWithOwnerAndProduct('Clip Trim Test');
        $this->client->loginUser($owner);

        $this->client->request('POST', "/admin/products/{$productId}/clip", [], [
            'clip' => $this->realClipUpload(10),
        ]);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->clipFilesToRemove[] = $data['videoClip'] ?? null;
        $this->imageFilesToRemove[] = $data['image'] ?? null;
        self::assertResponseIsSuccessful();

        self::assertTrue($data['trimmed']);
        self::assertLessThanOrEqual(6.05, $this->storedDurationSeconds($data['videoClip']), 'stored clip must be cut to the first 6 s');
        self::assertGreaterThan(5.5, $this->storedDurationSeconds($data['videoClip']), 'trim keeps the first 6 s, not less');

        $this->em->clear();
        self::assertSame($data['videoClip'], $this->em->getRepository(Product::class)->find($productId)->getVideoClip());
    }

    public function testWhenFfmpegFailsNothingIsPersistedOrLeftOnDisk(): void
    {
        [$owner, $productId] = $this->makeRestaurantWithOwnerAndProduct('Clip Ffmpeg Failure Test');
        $this->client->loginUser($owner);
        $filesBefore = scandir($this->productsDir);

        // Header-only MP4: passes the validator, but has no frame FFmpeg can
        // extract a poster from.
        $this->client->request('POST', "/admin/products/{$productId}/clip", [], [
            'clip' => $this->clipUpload(3.0),
        ]);
        self::assertResponseStatusCodeSame(500);
        self::assertSame($filesBefore, scandir($this->productsDir), 'no orphaned clip/poster file may be left behind');

        $this->em->clear();
        $product = $this->em->getRepository(Product::class)->find($productId);
        self::assertNull($product->getVideoClip());
        self::assertNull($product->getImage());
    }

    public function testUploadingAPhotoAfterAClipClearsVideoClipAndDeletesItsFile(): void
    {
        [$owner, $productId] = $this->makeRestaurantWithOwnerAndProduct('Photo Clears Clip Test');
        $this->client->loginUser($owner);

        $this->client->request('POST', "/admin/products/{$productId}/clip", [], [
            'clip' => $this->realClipUpload(3),
        ]);
        $clipData = json_decode($this->client->getResponse()->getContent(), true);
        $clipFilename = $clipData['videoClip'] ?? null;
        $this->clipFilesToRemove[] = $clipFilename;
        $this->imageFilesToRemove[] = $clipData['image'] ?? null;
        self::assertResponseIsSuccessful();
        self::assertFileExists($this->productsDir . '/' . $clipFilename);

        // Framing belongs to the clip — set some so the photo upload below
        // has something to clear.
        $this->em->clear();
        $framed = $this->em->getRepository(Product::class)->find($productId);
        $framed->setVideoFocus(30, 80);
        $this->em->flush();
        $this->em->clear();

        // A manually chosen photo must win outright — image and videoClip
        // are never allowed to point at unrelated media (see
        // uploadProductImage()'s own comment on this).
        $this->client->request('POST', "/admin/products/{$productId}/image", [], [
            'image' => $this->photoUpload(),
        ]);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $imageFilename = $data['image'] ?? null;
        $this->imageFilesToRemove[] = $imageFilename;
        self::assertResponseIsSuccessful();
        self::assertNull($data['videoClip'], 'the response itself must report the clip as cleared');

        self::assertFileExists($this->productsDir . '/' . $imageFilename);
        self::assertFileDoesNotExist($this->productsDir . '/' . $clipFilename, 'the orphaned clip file must be deleted, not just unlinked from the row');

        $this->em->clear();
        $product = $this->em->getRepository(Product::class)->find($productId);
        self::assertSame($imageFilename, $product->getImage());
        self::assertNull($product->getVideoClip(), 'uploading a photo must clear a previously-set videoClip');
        self::assertNull($product->getVideoFocusX(), 'the clip\'s framing goes with it');
        self::assertNull($product->getVideoFocusY(), 'the clip\'s framing goes with it');
    }

    public function testUploadingAPhotoWithNoExistingClipNeverTouchesVideoClip(): void
    {
        // The new clip-clearing branch in uploadProductImage() is guarded
        // on getVideoClip() being non-null — this pins down the common
        // case (no clip at all) stays a no-op, not just "doesn't crash".
        [$owner, $productId] = $this->makeRestaurantWithOwnerAndProduct('Photo Only Test');
        $this->client->loginUser($owner);

        $this->client->request('POST', "/admin/products/{$productId}/image", [], [
            'image' => $this->photoUpload(),
        ]);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->imageFilesToRemove[] = $data['image'] ?? null;
        self::assertResponseIsSuccessful();
        self::assertNull($data['videoClip']);

        $this->em->clear();
        $product = $this->em->getRepository(Product::class)->find($productId);
        self::assertNull($product->getVideoClip());
    }

    public function testUploadingAClipToAnotherTenantsProductIsForbiddenAndLeavesItUntouched(): void
    {
        [, $victimProductId] = $this->makeRestaurantWithOwnerAndProduct('Clip Tenant Victim');
        [$attackerOwner] = $this->makeRestaurantWithOwnerAndProduct('Clip Tenant Attacker');

        $this->client->loginUser($attackerOwner);
        $this->client->request('POST', "/admin/products/{$victimProductId}/clip", [], [
            'clip' => $this->clipUpload(3.0),
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        $product = $this->em->getRepository(Product::class)->find($victimProductId);
        self::assertNull($product->getVideoClip(), "another tenant's upload must not have reached this product");
    }

    public function testDurationOverTheSixtySecondInputCapIsRejectedOverHttpAndNeverPersisted(): void
    {
        [$owner, $productId] = $this->makeRestaurantWithOwnerAndProduct('Clip Duration Test');
        $this->client->loginUser($owner);

        $this->client->request('POST', "/admin/products/{$productId}/clip", [], [
            'clip' => $this->clipUpload(65.0),
        ]);
        self::assertResponseStatusCodeSame(400);

        $this->em->clear();
        $product = $this->em->getRepository(Product::class)->find($productId);
        self::assertNull($product->getVideoClip(), 'a rejected upload must never persist a videoClip reference');
    }
}
