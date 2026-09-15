<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional coverage for MenuAdminController::uploadProductClip — the
 * upload endpoint for Product::$videoClip. Real HTTP requests through the
 * actual route, same approach as SettingsControllerCrossTenantLogoTest /
 * MenuAdminMissingPhotoNoticeTest.
 *
 * No GD, no ffmpeg: the clip fixture is a minimal, well-formed MP4
 * (ftyp + moov/mvhd + mdat) built by hand in makeMp4Bytes() — the same
 * technique already validated against UploadValidator's own moov/mvhd
 * parser in UploadValidatorTest (finfo genuinely detects it as
 * video/mp4). MIME/size/duration edge cases are covered as pure logic in
 * UploadValidatorTest; this file only covers what needs a real HTTP
 * request and a persisted Product — the tenant check, and that a
 * replacement clip actually deletes the old file.
 */
final class MenuAdminControllerClipUploadTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $productsDir;

    /** @var int[] */
    private array $restaurantIdsToRemove = [];

    /** @var string[] */
    private array $clipFilesToRemove = [];

    /** @var string[] */
    private array $imageFilesToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->productsDir = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/products';
    }

    protected function tearDown(): void
    {
        foreach ($this->clipFilesToRemove as $filename) {
            $path = $this->productsDir . '/' . $filename;
            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach ($this->imageFilesToRemove as $filename) {
            $path = $this->productsDir . '/' . $filename;
            if (is_file($path)) {
                unlink($path);
            }
        }

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

    public function testUploadingAValidClipSetsVideoClipAndDeletesThePreviousOne(): void
    {
        [$owner, $productId] = $this->makeRestaurantWithOwnerAndProduct('Clip Upload Test');
        $this->client->loginUser($owner);

        $this->client->request('POST', "/admin/products/{$productId}/clip", [], [
            'clip' => $this->clipUpload(3.0),
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('videoClip', $data);
        $firstFilename = $data['videoClip'];
        $this->clipFilesToRemove[] = $firstFilename;
        self::assertFileExists($this->productsDir . '/' . $firstFilename);

        // Replace it — the old file must be gone once the new one lands,
        // same cleanup pattern as uploadProductImage().
        $this->client->request('POST', "/admin/products/{$productId}/clip", [], [
            'clip' => $this->clipUpload(4.0),
        ]);
        self::assertResponseIsSuccessful();
        $data2 = json_decode($this->client->getResponse()->getContent(), true);
        $secondFilename = $data2['videoClip'];
        $this->clipFilesToRemove[] = $secondFilename;

        self::assertNotSame($firstFilename, $secondFilename);
        self::assertFileExists($this->productsDir . '/' . $secondFilename);
        self::assertFileDoesNotExist($this->productsDir . '/' . $firstFilename, 'old clip must be deleted on replace');

        $this->em->clear();
        $product = $this->em->getRepository(Product::class)->find($productId);
        self::assertSame($secondFilename, $product->getVideoClip());
        self::assertNull($product->getImage(), 'the clip endpoint must never touch the image column');
    }

    public function testUploadingAPhotoAfterAClipClearsVideoClipAndDeletesItsFile(): void
    {
        [$owner, $productId] = $this->makeRestaurantWithOwnerAndProduct('Photo Clears Clip Test');
        $this->client->loginUser($owner);

        $this->client->request('POST', "/admin/products/{$productId}/clip", [], [
            'clip' => $this->clipUpload(3.0),
        ]);
        self::assertResponseIsSuccessful();
        $clipFilename = json_decode($this->client->getResponse()->getContent(), true)['videoClip'];
        $this->clipFilesToRemove[] = $clipFilename;
        self::assertFileExists($this->productsDir . '/' . $clipFilename);

        // A manually chosen photo must win outright — image and videoClip
        // are never allowed to point at unrelated media (see
        // uploadProductImage()'s own comment on this).
        $this->client->request('POST', "/admin/products/{$productId}/image", [], [
            'image' => $this->photoUpload(),
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNull($data['videoClip'], 'the response itself must report the clip as cleared');
        $imageFilename = $data['image'];
        $this->imageFilesToRemove[] = $imageFilename;

        self::assertFileExists($this->productsDir . '/' . $imageFilename);
        self::assertFileDoesNotExist($this->productsDir . '/' . $clipFilename, 'the orphaned clip file must be deleted, not just unlinked from the row');

        $this->em->clear();
        $product = $this->em->getRepository(Product::class)->find($productId);
        self::assertSame($imageFilename, $product->getImage());
        self::assertNull($product->getVideoClip(), 'uploading a photo must clear a previously-set videoClip');
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
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->imageFilesToRemove[] = $data['image'];
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

    public function testDurationOverTenSecondsIsRejectedOverHttpAndNeverPersisted(): void
    {
        [$owner, $productId] = $this->makeRestaurantWithOwnerAndProduct('Clip Duration Test');
        $this->client->loginUser($owner);

        $this->client->request('POST', "/admin/products/{$productId}/clip", [], [
            'clip' => $this->clipUpload(15.0),
        ]);
        self::assertResponseStatusCodeSame(400);

        $this->em->clear();
        $product = $this->em->getRepository(Product::class)->find($productId);
        self::assertNull($product->getVideoClip(), 'a rejected upload must never persist a videoClip reference');
    }
}
