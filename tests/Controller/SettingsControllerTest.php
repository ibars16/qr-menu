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
 * Functional coverage for the logo-replacement path in
 * SettingsController::settings. Written after a9b46c2/8ea147a found that,
 * unlike MenuAdminController::uploadProductImage, replacing a restaurant's
 * logo left the previous file on disk in public/uploads/logos/ — an
 * accumulating, URL-reachable orphan. These tests pin down that the old
 * file is now deleted on a real replacement, and that the two edge cases
 * (no previous logo, previous file already missing from disk) don't crash.
 *
 * NOTE: not run in this environment — the project's DATABASE_URL targets
 * pdo_pgsql, which isn't installed here. Verified instead via a standalone
 * simulation of the exact move/unlink logic (see task report) covering the
 * same three cases. Left here so it runs wherever pdo_pgsql is available.
 */
final class SettingsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $hasher;
    private string $logoDir;

    /** @var int[] restaurant ids, not entities — logging in via loginUser() can detach the original object */
    private array $restaurantIdsToRemove = [];

    /** @var string[] logo filenames created during a test, cleaned up regardless of pass/fail */
    private array $logoFilesToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->logoDir = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/logos';
    }

    protected function tearDown(): void
    {
        foreach ($this->logoFilesToRemove as $filename) {
            $path = $this->logoDir . '/' . $filename;
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
}
