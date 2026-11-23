<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Tests\Support\TestUploadsDir;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional coverage for the logo- and hero-image paths in
 * SettingsController::image (saved the moment they're picked/removed). Written after a9b46c2/8ea147a found that,
 * unlike MenuAdminController::uploadProductImage, replacing a restaurant's
 * logo left the previous file on disk in public/uploads/logos/ — an
 * accumulating, URL-reachable orphan. These tests pin down that the old
 * file is now deleted on a real replacement, and that the two edge cases
 * (no previous logo/hero image, previous file already missing from disk)
 * don't crash. Restaurant::$heroImage (see conversation) replicates the
 * exact same validate/move/delete-old logic in its own public/uploads/heroes/
 * directory, so its tests mirror the logo ones one-for-one.
 *
 * Files land in app.uploads_dir's test folder (var/test-uploads, never
 * public/uploads — see TestUploadsDir), emptied after every test. Images
 * are checked-in fixtures (tests/fixtures/upload_png_*.png and the hero
 * JPEGs), not generated: the container has no GD.
 */
final class SettingsControllerTest extends WebTestCase
{
    use TestUploadsDir;

    private const FIXTURES_DIR = __DIR__ . '/../fixtures';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $hasher;
    private string $logoDir;
    private string $heroImageDir;

    /** @var int[] restaurant ids, not entities — logging in via loginUser() can detach the original object */
    private array $restaurantIdsToRemove = [];

    /** @var array<string|null> logo filenames created during a test, cleaned up regardless of pass/fail */
    private array $logoFilesToRemove = [];

    /** @var array<string|null> hero image filenames created during a test, cleaned up regardless of pass/fail */
    private array $heroImageFilesToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $uploadsDir = self::testUploadsDir('logos', 'heroes');
        $this->logoDir = $uploadsDir . '/logos';
        $this->heroImageDir = $uploadsDir . '/heroes';
    }

    protected function tearDown(): void
    {
        // Registered files first (null/'' never reaches unlink()), then
        // the whole test folder, which also catches anything a failing test
        // stored before it could register it.
        foreach ($this->logoFilesToRemove as $filename) {
            if ($filename && is_file($this->logoDir . '/' . $filename)) {
                unlink($this->logoDir . '/' . $filename);
            }
        }

        foreach ($this->heroImageFilesToRemove as $filename) {
            if ($filename && is_file($this->heroImageDir . '/' . $filename)) {
                unlink($this->heroImageDir . '/' . $filename);
            }
        }
        self::emptyTestUploadsDir();

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
        return $this->fixtureUploadedFile('upload_png_150x150.png', $originalName, 'image/png');
    }

    /**
     * Posts one image to SettingsController::image, as Apariencia's
     * saveImage() does the moment a file is picked. Returns the JSON.
     */
    private function postImage(string $kind, ?UploadedFile $file, array $fields = []): array
    {
        $this->client->request('POST', '/admin/settings/image/' . $kind, ['_token' => $this->settingsToken()] + $fields, $file ? ['image' => $file] : []);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function submitLogo(Restaurant $restaurant, UploadedFile $logo): void
    {
        $json = $this->postImage('logo', $logo);
        self::assertResponseIsSuccessful();
        self::assertTrue($json['ok']);
    }

    /** Clears the hero profile's 300px minimum, so deliberately bigger than logoUpload()'s. */
    private function heroImageUpload(string $originalName = 'hero.png'): UploadedFile
    {
        return $this->fixtureUploadedFile('upload_png_500x500.png', $originalName, 'image/png');
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
        $json = $this->postImage('hero', $heroImage);
        self::assertResponseIsSuccessful();
        self::assertTrue($json['ok']);
    }

    public function testReplacingTheLogoDeletesThePreviousFileFromDisk(): void
    {
        $restaurant = $this->makeRestaurant('Logo Cleanup Test');
        $owner = $this->makeOwner($restaurant);
        $this->client->loginUser($owner);

        $this->submitLogo($restaurant, $this->logoUpload());
        $restaurant = $this->reloadRestaurant($restaurant);
        $firstLogo = $restaurant->getLogo();
        $this->logoFilesToRemove[] = $firstLogo;
        self::assertNotNull($firstLogo);
        self::assertFileExists($this->logoDir . '/' . $firstLogo);

        $this->submitLogo($restaurant, $this->logoUpload());
        $restaurant = $this->reloadRestaurant($restaurant);
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
        $restaurant = $this->reloadRestaurant($restaurant);
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
        $restaurant = $this->reloadRestaurant($restaurant);
        $firstLogo = $restaurant->getLogo();
        $this->logoFilesToRemove[] = $firstLogo;
        self::assertNotNull($firstLogo);
        unlink($this->logoDir . '/' . $firstLogo); // simulate an already-orphaned/removed file

        $this->submitLogo($restaurant, $this->logoUpload());
        $restaurant = $this->reloadRestaurant($restaurant);
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
        $restaurant = $this->reloadRestaurant($restaurant);
        $firstHeroImage = $restaurant->getHeroImage();
        $this->heroImageFilesToRemove[] = $firstHeroImage;
        self::assertNotNull($firstHeroImage);
        self::assertFileExists($this->heroImageDir . '/' . $firstHeroImage);

        $this->submitHeroImage($restaurant, $this->heroImageUpload());
        $restaurant = $this->reloadRestaurant($restaurant);
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
        $restaurant = $this->reloadRestaurant($restaurant);
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
        $restaurant = $this->reloadRestaurant($restaurant);
        $firstHeroImage = $restaurant->getHeroImage();
        $this->heroImageFilesToRemove[] = $firstHeroImage;
        self::assertNotNull($firstHeroImage);
        unlink($this->heroImageDir . '/' . $firstHeroImage); // simulate an already-orphaned/removed file

        $this->submitHeroImage($restaurant, $this->heroImageUpload());
        $restaurant = $this->reloadRestaurant($restaurant);
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

        $json = $this->postImage('hero', $fakeImage, ['qualityConfirmed' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertFalse($json['ok']);
        self::assertNotEmpty($json['error']);

        $restaurant = $this->reloadRestaurant($restaurant);
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

        $goodImage = $this->fixtureUploadedFile('upload_hero_wide.jpg', 'hero.jpg', 'image/jpeg');

        $json = $this->postImage('hero', $goodImage);
        $restaurant = $this->reloadRestaurant($restaurant);
        $heroImage = $restaurant->getHeroImage();
        $this->heroImageFilesToRemove[] = $heroImage; // before any assertion, so a failure still cleans up

        self::assertResponseIsSuccessful();
        self::assertTrue($json['ok']);
        self::assertSame([], $json['notices'], 'a clean above-minimum image must not raise a quality warning');
        self::assertSame('/uploads/heroes/' . $heroImage, $json['url']);

        self::assertNotNull($heroImage);
        self::assertFileExists($this->heroImageDir . '/' . $heroImage);
    }

    /**
     * Narrower than the recommended width (800 < 1600): saved straight away,
     * no confirmation, with an informative warning that carries the real
     * size and the recommended width.
     */
    public function testNarrowHeroImageIsSavedWithAWarningShowingItsSize(): void
    {
        $restaurant = $this->makeRestaurant('Narrow Hero Image Http Test');
        $owner = $this->makeOwner($restaurant);
        $this->client->loginUser($owner);

        $json = $this->postImage('hero', $this->fixtureUploadedFile('upload_hero_ok.jpg', 'hero.jpg', 'image/jpeg'));
        $restaurant = $this->reloadRestaurant($restaurant);
        $heroImage = $restaurant->getHeroImage();
        $this->heroImageFilesToRemove[] = $heroImage;

        self::assertResponseIsSuccessful();
        self::assertNotNull($heroImage, 'an informative warning must not hold the photo back');
        self::assertFileExists($this->heroImageDir . '/' . $heroImage);
        self::assertCount(1, $json['notices']);
        self::assertStringContainsString('800 × 400', $json['notices'][0]);
        self::assertStringContainsString('1600', $json['notices'][0]);
    }

    /** A restaurant that already has a logo and a hero image on disk, as a real one would. */
    private function makeRestaurantWithLogoAndHero(string $name): array
    {
        $restaurant = $this->makeRestaurant($name);
        $owner = $this->makeOwner($restaurant);
        $this->client->loginUser($owner);

        $this->submitLogo($restaurant, $this->logoUpload());
        $this->submitHeroImage($restaurant, $this->fixtureUploadedFile('upload_hero_wide.jpg', 'hero.jpg', 'image/jpeg'));
        $restaurant = $this->reloadRestaurant($restaurant);
        $this->logoFilesToRemove[] = $restaurant->getLogo();
        $this->heroImageFilesToRemove[] = $restaurant->getHeroImage();
        self::assertNotNull($restaurant->getLogo());
        self::assertNotNull($restaurant->getHeroImage());

        return [$restaurant, $restaurant->getLogo(), $restaurant->getHeroImage()];
    }

    /** @return array{0: string[], 1: string[]} */
    private function uploadDirsListing(): array
    {
        return [scandir($this->logoDir) ?: [], scandir($this->heroImageDir) ?: []];
    }

    public function testRemovingTheLogoClearsItAndDeletesTheFile(): void
    {
        [$restaurant, $logo, $hero] = $this->makeRestaurantWithLogoAndHero('Remove Logo Test');

        $json = $this->postImage('logo', null, ['action' => 'remove']);
        self::assertResponseIsSuccessful();
        self::assertTrue($json['ok']);
        self::assertNull($json['url']);

        $restaurant = $this->reloadRestaurant($restaurant);
        self::assertNull($restaurant->getLogo());
        self::assertFileDoesNotExist($this->logoDir . '/' . $logo);
        self::assertSame($hero, $restaurant->getHeroImage(), 'the hero image is left alone');
    }

    public function testRemovingTheHeroImageClearsItAndDeletesTheFile(): void
    {
        [$restaurant, $logo, $hero] = $this->makeRestaurantWithLogoAndHero('Remove Hero Test');

        $json = $this->postImage('hero', null, ['action' => 'remove']);
        self::assertResponseIsSuccessful();
        self::assertTrue($json['ok']);

        $restaurant = $this->reloadRestaurant($restaurant);
        self::assertNull($restaurant->getHeroImage());
        self::assertFileDoesNotExist($this->heroImageDir . '/' . $hero);
        self::assertSame($logo, $restaurant->getLogo(), 'the logo is left alone');
    }

    /** A rejected file: the current logo stays, on disk and in the DB, and nothing new is left behind. */
    public function testRejectedLogoLeavesDbAndDiskUntouched(): void
    {
        [$restaurant, $logo] = $this->makeRestaurantWithLogoAndHero('Rejected Logo Test');
        $listingBefore = $this->uploadDirsListing();

        $json = $this->postImage('logo', $this->fixtureUploadedFile('upload_fake_mime.png', 'logo.png', 'image/png'));
        self::assertResponseStatusCodeSame(422);
        self::assertFalse($json['ok']);
        self::assertNotEmpty($json['error']);

        $restaurant = $this->reloadRestaurant($restaurant);
        self::assertSame($logo, $restaurant->getLogo());
        self::assertSame($listingBefore, $this->uploadDirsListing());
    }

    /** Below the 300px minimum: nothing saved until re-posted with qualityConfirmed, then saved. */
    public function testTooSmallHeroImageIsSavedOnlyOnceConfirmed(): void
    {
        [$restaurant, , $hero] = $this->makeRestaurantWithLogoAndHero('Confirm Small Hero Test');
        $listingBefore = $this->uploadDirsListing();

        $json = $this->postImage('hero', $this->fixtureUploadedFile('upload_hero_small.jpg', 'hero.jpg', 'image/jpeg'));
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($json['needsConfirmation']);
        self::assertCount(1, $json['warnings']);
        self::assertStringContainsString('250 × 150', $json['warnings'][0]);
        self::assertStringContainsString('300', $json['warnings'][0]);
        self::assertSame($hero, $this->reloadRestaurant($restaurant)->getHeroImage(), 'unconfirmed: nothing is saved');
        self::assertSame($listingBefore, $this->uploadDirsListing());

        $json = $this->postImage('hero', $this->fixtureUploadedFile('upload_hero_small.jpg', 'hero.jpg', 'image/jpeg'), ['qualityConfirmed' => '1']);
        $restaurant = $this->reloadRestaurant($restaurant);
        $this->heroImageFilesToRemove[] = $restaurant->getHeroImage();
        self::assertResponseIsSuccessful();
        self::assertTrue($json['ok']);
        self::assertNotSame($hero, $restaurant->getHeroImage());
        self::assertFileDoesNotExist($this->heroImageDir . '/' . $hero);
    }

    public function testImageWithAnInvalidCsrfTokenIsRejected(): void
    {
        $restaurant = $this->makeRestaurant('Image Csrf Test');
        $this->client->loginUser($this->makeOwner($restaurant));

        $this->client->request('POST', '/admin/settings/image/logo', ['_token' => 'wrong'], ['image' => $this->logoUpload()]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reloadRestaurant($restaurant)->getLogo());
    }

    /**
     * Images are no longer part of the settings form: a logo posted with it
     * is ignored (the inputs have no name), and Apariencia has no "Guardar".
     */
    public function testTheSettingsFormNoLongerStoresImages(): void
    {
        $restaurant = $this->makeRestaurant('Form Ignores Images Test');
        $this->client->loginUser($this->makeOwner($restaurant));

        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertCount(0, $crawler->filter('button[name="section"][value="appearance"]'));
        self::assertCount(0, $crawler->filter('input[type="file"][name]'));

        $this->client->request('POST', '/admin/settings', [
            '_token'          => $crawler->filter('input[name="_token"]')->attr('value'),
            'name'            => $restaurant->getName(),
            'currency'        => 'EUR',
            'defaultLanguage' => 'es',
        ], [
            'logo' => $this->logoUpload(),
        ]);
        self::assertResponseRedirects('/admin/settings');
        self::assertNull($this->reloadRestaurant($restaurant)->getLogo());
    }

    /** The format hint's numbers are the hero_image profile's own limits, never written into the translations. */
    public function testHeroImageFormatHintShowsTheProfileLimits(): void
    {
        $restaurant = $this->makeRestaurant('Hero Hint Test');
        $this->client->loginUser($this->makeOwner($restaurant));

        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();

        $hint = $crawler->filter('#hero-image-input')->closest('.logo-actions')->filter('.field-hint')->text();
        foreach (['8 MB', '300x300 px', '5000x5000 px', '1600 px'] as $expected) {
            self::assertStringContainsString($expected, $hint);
        }
        self::assertStringNotContainsString('%', $hint, 'every placeholder is filled');
    }

    /** The settings form's CSRF token, read from a real GET of the page (the form now requires it). */
    private function settingsToken(): string
    {
        return $this->client->request('GET', '/admin/settings')->filter('input[name="_token"]')->attr('value');
    }

    /**
     * Re-reads the restaurant from the current container's EntityManager.
     * Each POST is now preceded by a GET (for the CSRF token), and the test
     * client reboots the kernel between requests, which clears the EM this
     * test captured in setUp() — refresh() on the old instance would fail
     * with "entity is not managed".
     */
    private function reloadRestaurant(Restaurant $restaurant): Restaurant
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        return $this->em->getRepository(Restaurant::class)->find($restaurant->getId());
    }
}
