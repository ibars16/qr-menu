<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional coverage for the logo- and hero-image-replacement paths in
 * SettingsController::settings. Written after a9b46c2/8ea147a found that,
 * unlike MenuAdminController::uploadProductImage, replacing a restaurant's
 * logo left the previous file on disk in public/uploads/logos/ — an
 * accumulating, URL-reachable orphan. These tests pin down that the old
 * file is now deleted on a real replacement, and that the two edge cases
 * (no previous logo/hero image, previous file already missing from disk)
 * don't crash. Restaurant::$heroImage (see conversation) replicates the
 * exact same validate/move/delete-old logic in its own public/uploads/heroes/
 * directory, so its tests mirror the logo ones one-for-one.
 *
 * NOTE on this environment: pdo_pgsql is available (via the project's
 * docker compose stack), but GD (imagecreatetruecolor) is not, so the
 * replacement/cleanup tests above that synthesize images with GD still
 * don't run here — verified instead via a standalone simulation of the
 * exact move/unlink logic (see task report) covering the same three cases.
 * The quality-warning HTTP tests below use static fixture files instead of
 * GD (see UploadValidatorQualityWarningTest's own docblock for why those
 * fixtures exist) and do run here.
 */
final class SettingsControllerTest extends WebTestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../fixtures';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $hasher;
    private string $logoDir;
    private string $heroImageDir;

    /** @var int[] restaurant ids, not entities — logging in via loginUser() can detach the original object */
    private array $restaurantIdsToRemove = [];

    /** @var string[] logo filenames created during a test, cleaned up regardless of pass/fail */
    private array $logoFilesToRemove = [];

    /** @var string[] hero image filenames created during a test, cleaned up regardless of pass/fail */
    private array $heroImageFilesToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->logoDir = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/logos';
        $this->heroImageDir = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/heroes';
    }

    protected function tearDown(): void
    {
        foreach ($this->logoFilesToRemove as $filename) {
            $path = $this->logoDir . '/' . $filename;
            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach ($this->heroImageFilesToRemove as $filename) {
            $path = $this->heroImageDir . '/' . $filename;
            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach ($this->restaurantIdsToRemove as $restaurantId) {
            $restaurant = $this->em->getRepository(Restaurant::class)->find($restaurantId);
            if (!$restaurant) {
                continue;
            }
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            $this->em->remove($restaurant);
        }
        $this->em->flush();

        parent::tearDown();
    }

    private function makeRestaurant(string $name): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName($name);
        $restaurant->setSlug('settings-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantIdsToRemove[] = $restaurant->getId();

        return $restaurant;
    }

    private function makeOwner(Restaurant $restaurant): User
    {
        $user = new User();
        $user->setEmail('settings-test-' . uniqid() . '@example.test');
        $user->setPassword($this->hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function logoUpload(string $originalName = 'logo.png'): UploadedFile
    {
        $image = imagecreatetruecolor(150, 150);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 20, 30));

        $path = tempnam(sys_get_temp_dir(), 'settings_logo_test_');
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $originalName, 'image/png', null, true);
    }

    private function submitLogo(Restaurant $restaurant, UploadedFile $logo): void
    {
        $this->client->request('POST', '/admin/settings', [
            'name'            => $restaurant->getName(),
            'primaryColor'    => '#000000',
            'currency'        => 'EUR',
            'defaultLanguage' => 'es',
        ], [
            'logo' => $logo,
        ]);
        self::assertResponseRedirects('/admin/settings');
    }

    /** DishImage profile requires >=400x400 (unlike Logo's >=100x100), so this is deliberately bigger than logoUpload()'s. */
    private function heroImageUpload(string $originalName = 'hero.png'): UploadedFile
    {
        $image = imagecreatetruecolor(500, 500);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 20, 10));

        $path = tempnam(sys_get_temp_dir(), 'settings_hero_image_test_');
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $originalName, 'image/png', null, true);
    }

    /**
     * Copies a checked-in fixture to a disposable temp path before wrapping
     * it in an UploadedFile: UploadedFile::move() in test mode (the `$test
     * = true` 5th constructor arg, needed since these aren't real HTTP
     * uploads) renames the *source* path rather than copying it — pointing
     * it straight at a fixture under tests/fixtures/ would move the
     * checked-in file out of the repo on any test run that reaches move().
     */
    private function fixtureUploadedFile(string $fixtureFilename, string $originalName, string $mimeType): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'settings_fixture_test_');
        copy(self::FIXTURES_DIR . '/' . $fixtureFilename, $path);

        return new UploadedFile($path, $originalName, $mimeType, null, true);
    }

    private function submitHeroImage(Restaurant $restaurant, UploadedFile $heroImage): void
    {
        $this->client->request('POST', '/admin/settings', [
            'name'            => $restaurant->getName(),
            'primaryColor'    => '#000000',
            'currency'        => 'EUR',
            'defaultLanguage' => 'es',
        ], [
            'heroImage' => $heroImage,
        ]);
        self::assertResponseRedirects('/admin/settings');
    }

    public function testReplacingTheLogoDeletesThePreviousFileFromDisk(): void
    {
        $restaurant = $this->makeRestaurant('Logo Cleanup Test');
        $owner = $this->makeOwner($restaurant);
        $this->client->loginUser($owner);

        $this->submitLogo($restaurant, $this->logoUpload());
        $this->em->refresh($restaurant);
        $firstLogo = $restaurant->getLogo();
        $this->logoFilesToRemove[] = $firstLogo;
        self::assertNotNull($firstLogo);
        self::assertFileExists($this->logoDir . '/' . $firstLogo);

        $this->submitLogo($restaurant, $this->logoUpload());
        $this->em->refresh($restaurant);
        $secondLogo = $restaurant->getLogo();
        $this->logoFilesToRemove[] = $secondLogo;

        self::assertNotSame($firstLogo, $secondLogo);
        self::assertFileDoesNotExist($this->logoDir . '/' . $firstLogo, 'previous logo file should be deleted after replacement');
        self::assertFileExists($this->logoDir . '/' . $secondLogo);
    }

    public function testUploadingAFirstLogoDoesNotAttemptToDeleteAnything(): void
    {
        $restaurant = $this->makeRestaurant('First Logo Test');
        $owner = $this->makeOwner($restaurant);
        $this->client->loginUser($owner);

        self::assertNull($restaurant->getLogo());

        $this->submitLogo($restaurant, $this->logoUpload());
        $this->em->refresh($restaurant);
        $logo = $restaurant->getLogo();
        $this->logoFilesToRemove[] = $logo;

        self::assertNotNull($logo);
        self::assertFileExists($this->logoDir . '/' . $logo);
    }

    public function testReplacingALogoWhoseFileIsAlreadyMissingFromDiskDoesNotError(): void
    {
        $restaurant = $this->makeRestaurant('Missing Old File Test');
        $owner = $this->makeOwner($restaurant);
        $this->client->loginUser($owner);

        $this->submitLogo($restaurant, $this->logoUpload());
        $this->em->refresh($restaurant);
        $firstLogo = $restaurant->getLogo();
        unlink($this->logoDir . '/' . $firstLogo); // simulate an already-orphaned/removed file

        $this->submitLogo($restaurant, $this->logoUpload());
        $this->em->refresh($restaurant);
        $secondLogo = $restaurant->getLogo();
        $this->logoFilesToRemove[] = $secondLogo;

        self::assertNotSame($firstLogo, $secondLogo);
        self::assertFileExists($this->logoDir . '/' . $secondLogo);
    }

    public function testReplacingTheHeroImageDeletesThePreviousFileFromDisk(): void
    {
        $restaurant = $this->makeRestaurant('Hero Image Cleanup Test');
        $owner = $this->makeOwner($restaurant);
        $this->client->loginUser($owner);

        $this->submitHeroImage($restaurant, $this->heroImageUpload());
        $this->em->refresh($restaurant);
        $firstHeroImage = $restaurant->getHeroImage();
        $this->heroImageFilesToRemove[] = $firstHeroImage;
        self::assertNotNull($firstHeroImage);
        self::assertFileExists($this->heroImageDir . '/' . $firstHeroImage);

        $this->submitHeroImage($restaurant, $this->heroImageUpload());
        $this->em->refresh($restaurant);
        $secondHeroImage = $restaurant->getHeroImage();
        $this->heroImageFilesToRemove[] = $secondHeroImage;

        self::assertNotSame($firstHeroImage, $secondHeroImage);
        self::assertFileDoesNotExist($this->heroImageDir . '/' . $firstHeroImage, 'previous hero image file should be deleted after replacement');
        self::assertFileExists($this->heroImageDir . '/' . $secondHeroImage);
    }

    public function testUploadingAFirstHeroImageDoesNotAttemptToDeleteAnything(): void
    {
        $restaurant = $this->makeRestaurant('First Hero Image Test');
        $owner = $this->makeOwner($restaurant);
        $this->client->loginUser($owner);

        self::assertNull($restaurant->getHeroImage());

        $this->submitHeroImage($restaurant, $this->heroImageUpload());
        $this->em->refresh($restaurant);
        $heroImage = $restaurant->getHeroImage();
        $this->heroImageFilesToRemove[] = $heroImage;

        self::assertNotNull($heroImage);
        self::assertFileExists($this->heroImageDir . '/' . $heroImage);
    }

    public function testReplacingAHeroImageWhoseFileIsAlreadyMissingFromDiskDoesNotError(): void
    {
        $restaurant = $this->makeRestaurant('Missing Old Hero Image File Test');
        $owner = $this->makeOwner($restaurant);
        $this->client->loginUser($owner);

        $this->submitHeroImage($restaurant, $this->heroImageUpload());
        $this->em->refresh($restaurant);
        $firstHeroImage = $restaurant->getHeroImage();
        unlink($this->heroImageDir . '/' . $firstHeroImage); // simulate an already-orphaned/removed file

        $this->submitHeroImage($restaurant, $this->heroImageUpload());
        $this->em->refresh($restaurant);
        $secondHeroImage = $restaurant->getHeroImage();
        $this->heroImageFilesToRemove[] = $secondHeroImage;

        self::assertNotSame($firstHeroImage, $secondHeroImage);
        self::assertFileExists($this->heroImageDir . '/' . $secondHeroImage);
    }

    /**
     * The end-to-end counterpart to
     * UploadValidatorQualityWarningTest::testFakeMimeIsRejectedEvenWithQualityWarningsConfirmed —
     * that test proves UploadValidator::validate() itself can't be tricked,
     * but not that SettingsController's wiring actually rejects the request
     * rather than, say, reading heroImageQualityConfirmed before validate()
     * and skipping the call altogether. Posts the same spoofed-MIME fixture
     * (real bytes are plain text; only the client-declared name/type claim
     * .jpg/image/jpeg) through the real HTTP endpoint with the "upload
     * anyway" checkbox set, and asserts nothing reaches disk or the DB.
     */
    public function testFakeMimeHeroImageIsRejectedOverHttpEvenWithQualityWarningsConfirmed(): void
    {
        $restaurant = $this->makeRestaurant('Fake Mime Hero Image Guard Test');
        $owner = $this->makeOwner($restaurant);
        $this->client->loginUser($owner);

        self::assertNull($restaurant->getHeroImage());
        $filesBefore = scandir($this->heroImageDir) ?: [];

        $fakeImage = $this->fixtureUploadedFile('upload_fake_mime.png', 'fake.jpg', 'image/jpeg');

        $this->client->request('POST', '/admin/settings', [
            'name'                        => $restaurant->getName(),
            'primaryColor'                => '#000000',
            'currency'                    => 'EUR',
            'defaultLanguage'             => 'es',
            'heroImageQualityConfirmed'   => '1',
        ], [
            'heroImage' => $fakeImage,
        ]);
        self::assertResponseRedirects('/admin/settings');

        $this->em->refresh($restaurant);
        self::assertNull($restaurant->getHeroImage(), 'a security rejection must never persist a heroImage reference');

        $filesAfter = scandir($this->heroImageDir) ?: [];
        self::assertSame($filesBefore, $filesAfter, 'a security rejection must never leave a file behind on disk, confirmation flag or not');
    }

    /**
     * Counterpart to the fixtures already covered by
     * UploadValidatorQualityWarningTest::testCleanWideHeroImagePassesWithNoWarnings —
     * confirms the same clean, above-minimum image goes straight through the
     * real controller (no confirmation flag needed) and actually persists.
     */
    public function testCleanHeroImageAboveMinimumUploadsDirectlyOverHttp(): void
    {
        $restaurant = $this->makeRestaurant('Clean Hero Image Http Test');
        $owner = $this->makeOwner($restaurant);
        $this->client->loginUser($owner);

        self::assertNull($restaurant->getHeroImage());

        $goodImage = $this->fixtureUploadedFile('upload_hero_ok.jpg', 'hero.jpg', 'image/jpeg');

        $this->client->request('POST', '/admin/settings', [
            'name'            => $restaurant->getName(),
            'primaryColor'    => '#000000',
            'currency'        => 'EUR',
            'defaultLanguage' => 'es',
        ], [
            'heroImage' => $goodImage,
        ]);
        self::assertResponseRedirects('/admin/settings');

        $session = $this->client->getRequest()->getSession();
        self::assertSame([], $session->getFlashBag()->peek('warning'), 'a clean above-minimum image must not raise a quality warning');
        self::assertNotEmpty($session->getFlashBag()->peek('success'));

        $this->em->refresh($restaurant);
        $heroImage = $restaurant->getHeroImage();
        $this->heroImageFilesToRemove[] = $heroImage;

        self::assertNotNull($heroImage);
        self::assertFileExists($this->heroImageDir . '/' . $heroImage);
    }
}
