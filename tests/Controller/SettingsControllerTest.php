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

    private function submitLogo(Restaurant $restaurant, UploadedFile $logo): void
    {
        $this->client->request('POST', '/admin/settings', [
            '_token' => $this->settingsToken(),
            'name'            => $restaurant->getName(),
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
        $this->client->request('POST', '/admin/settings', [
            '_token' => $this->settingsToken(),
            'name'            => $restaurant->getName(),
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

        $this->client->request('POST', '/admin/settings', [
            '_token' => $this->settingsToken(),
            'name'                        => $restaurant->getName(),
            'currency'                    => 'EUR',
            'defaultLanguage'             => 'es',
            'heroImageQualityConfirmed'   => '1',
        ], [
            'heroImage' => $fakeImage,
        ]);
        self::assertResponseRedirects('/admin/settings');

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

        $this->client->request('POST', '/admin/settings', [
            '_token' => $this->settingsToken(),
            'name'            => $restaurant->getName(),
            'currency'        => 'EUR',
            'defaultLanguage' => 'es',
        ], [
            'heroImage' => $goodImage,
        ]);
        $session = $this->client->getRequest()->getSession();
        $restaurant = $this->reloadRestaurant($restaurant);
        $heroImage = $restaurant->getHeroImage();
        $this->heroImageFilesToRemove[] = $heroImage; // before any assertion, so a failure still cleans up

        self::assertResponseRedirects('/admin/settings');
        self::assertSame([], $session->getFlashBag()->peek('warning'), 'a clean above-minimum image must not raise a quality warning');
        self::assertNotEmpty($session->getFlashBag()->peek('success'));

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

        $this->client->request('POST', '/admin/settings', [
            '_token' => $this->settingsToken(),
            'name'            => $restaurant->getName(),
            'currency'        => 'EUR',
            'defaultLanguage' => 'es',
        ], [
            'heroImage' => $this->fixtureUploadedFile('upload_hero_ok.jpg', 'hero.jpg', 'image/jpeg'),
        ]);
        $session = $this->client->getRequest()->getSession();
        $restaurant = $this->reloadRestaurant($restaurant);
        $heroImage = $restaurant->getHeroImage();
        $this->heroImageFilesToRemove[] = $heroImage;

        self::assertResponseRedirects('/admin/settings');
        self::assertNotNull($heroImage, 'an informative warning must not hold the photo back');
        self::assertFileExists($this->heroImageDir . '/' . $heroImage);
        self::assertNotEmpty($session->getFlashBag()->peek('success'));

        $warnings = $session->getFlashBag()->peek('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('800 × 400', $warnings[0]);
        self::assertStringContainsString('1600', $warnings[0]);
    }

    /** A restaurant that already has a logo and a hero image on disk, as a real one would. */
    private function makeRestaurantWithLogoAndHero(string $name): array
    {
        $restaurant = $this->makeRestaurant($name);
        $owner = $this->makeOwner($restaurant);
        $this->client->loginUser($owner);

        $this->client->request('POST', '/admin/settings', [
            '_token'  => $this->settingsToken(),
            'section' => 'appearance',
        ], [
            'logo'      => $this->logoUpload(),
            'heroImage' => $this->fixtureUploadedFile('upload_hero_wide.jpg', 'hero.jpg', 'image/jpeg'),
        ]);
        self::assertResponseRedirects();
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

    /**
     * New logo + a hero below the 300px minimum (needs confirmation): the
     * hero used to be checked only after the logo was moved and the old
     * one deleted, then it returned without flushing — the restaurant kept
     * pointing at a deleted logo. Now nothing moves: DB and disk as before.
     */
    public function testNewLogoWithAHeroThatNeedsConfirmationLeavesDbAndDiskUntouched(): void
    {
        [$restaurant, $logo, $hero] = $this->makeRestaurantWithLogoAndHero('Logo + Small Hero Test');
        $listingBefore = $this->uploadDirsListing();

        $this->client->request('POST', '/admin/settings', [
            '_token'  => $this->settingsToken(),
            'section' => 'appearance',
        ], [
            'logo'      => $this->logoUpload(),
            'heroImage' => $this->fixtureUploadedFile('upload_hero_small.jpg', 'hero.jpg', 'image/jpeg'),
        ]);
        self::assertResponseRedirects('/admin/settings');

        $restaurant = $this->reloadRestaurant($restaurant);
        self::assertSame($logo, $restaurant->getLogo());
        self::assertSame($hero, $restaurant->getHeroImage());
        self::assertFileExists($this->logoDir . '/' . $logo, 'the current logo must still be on disk');
        self::assertFileExists($this->heroImageDir . '/' . $hero);
        self::assertSame($listingBefore, $this->uploadDirsListing(), 'no new file left behind either');
    }

    public function testNewLogoWithARejectedHeroLeavesDbAndDiskUntouched(): void
    {
        [$restaurant, $logo, $hero] = $this->makeRestaurantWithLogoAndHero('Logo + Rejected Hero Test');
        $listingBefore = $this->uploadDirsListing();

        $this->client->request('POST', '/admin/settings', [
            '_token'  => $this->settingsToken(),
            'section' => 'appearance',
        ], [
            'logo'      => $this->logoUpload(),
            'heroImage' => $this->fixtureUploadedFile('upload_fake_mime.png', 'hero.png', 'image/png'),
        ]);
        self::assertResponseRedirects('/admin/settings');

        $restaurant = $this->reloadRestaurant($restaurant);
        self::assertSame($logo, $restaurant->getLogo());
        self::assertSame($hero, $restaurant->getHeroImage());
        self::assertSame($listingBefore, $this->uploadDirsListing());
    }

    public function testReplacingLogoAndHeroTogetherStoresBothAndDeletesBothOldFiles(): void
    {
        [$restaurant, $oldLogo, $oldHero] = $this->makeRestaurantWithLogoAndHero('Logo + Hero Replace Test');

        $this->client->request('POST', '/admin/settings', [
            '_token'  => $this->settingsToken(),
            'section' => 'appearance',
        ], [
            'logo'      => $this->logoUpload(),
            'heroImage' => $this->fixtureUploadedFile('upload_hero_wide.jpg', 'hero.jpg', 'image/jpeg'),
        ]);
        self::assertResponseRedirects();

        $restaurant = $this->reloadRestaurant($restaurant);
        $this->logoFilesToRemove[] = $restaurant->getLogo();
        $this->heroImageFilesToRemove[] = $restaurant->getHeroImage();
        self::assertNotSame($oldLogo, $restaurant->getLogo());
        self::assertNotSame($oldHero, $restaurant->getHeroImage());
        self::assertFileExists($this->logoDir . '/' . $restaurant->getLogo());
        self::assertFileExists($this->heroImageDir . '/' . $restaurant->getHeroImage());
        self::assertFileDoesNotExist($this->logoDir . '/' . $oldLogo);
        self::assertFileDoesNotExist($this->heroImageDir . '/' . $oldHero);
    }

    /** Text errors elsewhere + picked files: nothing saved, and Apariencia says the picks must be made again. */
    public function testFormErrorsWithPickedFilesSayTheyWereNotSaved(): void
    {
        $restaurant = $this->makeRestaurant('Discarded Uploads Test');
        $this->client->loginUser($this->makeOwner($restaurant));
        $translator = static::getContainer()->get('translator');

        $cases = [
            'hint.upload_discarded_logo'       => ['logo' => true],
            'hint.upload_discarded_hero_image' => ['heroImage' => true],
            'hint.upload_discarded_both'       => ['logo' => true, 'heroImage' => true],
        ];
        foreach ($cases as $key => $files) {
            $upload = [];
            if (isset($files['logo'])) {
                $upload['logo'] = $this->logoUpload();
            }
            if (isset($files['heroImage'])) {
                $upload['heroImage'] = $this->fixtureUploadedFile('upload_hero_wide.jpg', 'hero.jpg', 'image/jpeg');
            }
            $crawler = $this->client->request('POST', '/admin/settings', [
                '_token'          => $this->settingsToken(),
                'name'            => $restaurant->getName(),
                'currency'        => 'EUR',
                'defaultLanguage' => 'es',
                'phone'           => 'not a phone',
            ], $upload);

            self::assertResponseStatusCodeSame(422);
            $notice = $crawler->filter('#section-appearance .upload-discarded');
            self::assertCount(1, $notice, $key);
            self::assertSame($translator->trans($key, [], 'admin_settings', 'es'), trim($notice->text()));
        }

        $restaurant = $this->reloadRestaurant($restaurant);
        self::assertNull($restaurant->getLogo());
        self::assertNull($restaurant->getHeroImage());
    }

    /**
     * Another section's own button with a logo picked: that section is
     * saved, the logo isn't (only Apariencia's button stores files) — the
     * owner lands on Apariencia with the notice and which button to use.
     */
    public function testAnotherSectionsButtonWithAPickedLogoSaysToUseAppearance(): void
    {
        $restaurant = $this->makeRestaurant('Other Section Upload Test');
        $this->client->loginUser($this->makeOwner($restaurant));
        $translator = static::getContainer()->get('translator');
        $listingBefore = $this->uploadDirsListing();

        $this->client->request('POST', '/admin/settings', [
            '_token'  => $this->settingsToken(),
            'section' => 'contact',
            'address' => 'Calle Mayor 1, Madrid',
            'phone'   => '+34 600 123 456',
        ], [
            'logo' => $this->logoUpload(),
        ]);
        self::assertResponseRedirects('/admin/settings#section-appearance');

        $restaurant = $this->reloadRestaurant($restaurant);
        self::assertSame('+34 600 123 456', $restaurant->getPhone(), 'the section itself is saved');
        self::assertNull($restaurant->getLogo());
        self::assertSame($listingBefore, $this->uploadDirsListing());

        $crawler = $this->client->request('GET', '/admin/settings');
        $notice = preg_replace('/\s+/', ' ', trim($crawler->filter('#section-appearance .upload-discarded')->text()));
        self::assertStringContainsString($translator->trans('hint.upload_discarded_logo', [], 'admin_settings', 'es'), $notice);
        self::assertStringContainsString($translator->trans('hint.upload_discarded_use_appearance', [
            '%button%'  => $translator->trans('action.save_section', [], 'admin_settings', 'es'),
            '%section%' => $translator->trans('section.appearance', [], 'admin_settings', 'es'),
        ], 'admin_settings', 'es'), $notice);

        // Shown once, not on every later visit.
        self::assertCount(0, $this->client->request('GET', '/admin/settings')->filter('.upload-discarded'));
    }

    /** Errors on another section's save with a picked photo: the notice also says which button stores it. */
    public function testAnotherSectionsErrorsWithAPickedHeroAlsoSayToUseAppearance(): void
    {
        $restaurant = $this->makeRestaurant('Other Section Error Upload Test');
        $this->client->loginUser($this->makeOwner($restaurant));
        $translator = static::getContainer()->get('translator');

        $crawler = $this->client->request('POST', '/admin/settings', [
            '_token'  => $this->settingsToken(),
            'section' => 'contact',
            'phone'   => 'not a phone',
        ], [
            'heroImage' => $this->fixtureUploadedFile('upload_hero_wide.jpg', 'hero.jpg', 'image/jpeg'),
        ]);

        self::assertResponseStatusCodeSame(422);
        $notice = preg_replace('/\s+/', ' ', trim($crawler->filter('#section-appearance .upload-discarded')->text()));
        self::assertStringContainsString($translator->trans('hint.upload_discarded_hero_image', [], 'admin_settings', 'es'), $notice);
        self::assertStringContainsString('«' . $translator->trans('action.save_section', [], 'admin_settings', 'es') . '»', $notice);
    }

    /** Apariencia's own button with a valid logo stores it: no notice. */
    public function testAppearanceButtonWithAValidLogoShowsNoDiscardedNotice(): void
    {
        $restaurant = $this->makeRestaurant('Appearance Upload No Notice Test');
        $this->client->loginUser($this->makeOwner($restaurant));

        $this->client->request('POST', '/admin/settings', [
            '_token'  => $this->settingsToken(),
            'section' => 'appearance',
        ], [
            'logo' => $this->logoUpload(),
        ]);
        self::assertResponseRedirects('/admin/settings#section-appearance');
        $this->logoFilesToRemove[] = $this->reloadRestaurant($restaurant)->getLogo();

        self::assertCount(0, $this->client->request('GET', '/admin/settings')->filter('.upload-discarded'));
    }

    public function testFormErrorsWithoutPickedFilesShowNoDiscardedNotice(): void
    {
        $restaurant = $this->makeRestaurant('No Discarded Uploads Test');
        $this->client->loginUser($this->makeOwner($restaurant));

        $crawler = $this->client->request('POST', '/admin/settings', [
            '_token'          => $this->settingsToken(),
            'name'            => $restaurant->getName(),
            'currency'        => 'EUR',
            'defaultLanguage' => 'es',
            'phone'           => 'not a phone',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $crawler->filter('.upload-discarded'));
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

    /** Below the 300px minimum: still needs confirmation, nothing saved, and the text now gives the real size. */
    public function testTooSmallHeroImageStillNeedsConfirmationAndShowsItsSize(): void
    {
        $restaurant = $this->makeRestaurant('Small Hero Image Http Test');
        $owner = $this->makeOwner($restaurant);
        $this->client->loginUser($owner);

        $this->client->request('POST', '/admin/settings', [
            '_token' => $this->settingsToken(),
            'name'            => $restaurant->getName(),
            'currency'        => 'EUR',
            'defaultLanguage' => 'es',
        ], [
            'heroImage' => $this->fixtureUploadedFile('upload_hero_small.jpg', 'hero.jpg', 'image/jpeg'),
        ]);
        $session = $this->client->getRequest()->getSession();
        $restaurant = $this->reloadRestaurant($restaurant);
        $this->heroImageFilesToRemove[] = $restaurant->getHeroImage();

        self::assertResponseRedirects('/admin/settings');
        self::assertNull($restaurant->getHeroImage(), 'unconfirmed: nothing is saved');
        $warnings = $session->getFlashBag()->peek('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('250 × 150', $warnings[0]);
        self::assertStringContainsString('300', $warnings[0]);
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
