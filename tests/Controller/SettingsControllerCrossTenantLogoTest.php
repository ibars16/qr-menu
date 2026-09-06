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
 * Deterministic, request-level reproduction for a cross-tenant content leak
 * suspected during manual browser-automation testing of the logo/heroImage
 * upload flow: one restaurant's uploaded logo file was, on one occasion,
 * observed to contain another restaurant's actual photo bytes. That
 * occurrence did not reproduce under strict (hash-verified) re-testing, and
 * no code path was found that could explain it — the working hypothesis is
 * a test-harness artifact (a double form submission from the browser
 * automation tool), not a defect in SettingsController/UploadValidator.
 *
 * This test rules out (or confirms) the code-level possibility directly:
 * it drives the exact same production path (SettingsController::settings())
 * via $client->request with real UploadedFile instances built fresh each
 * iteration — no browser, no automation tooling, nothing that could
 * duplicate a submission — alternating between two distinct restaurants
 * across N iterations. Each restaurant uploads a static fixture file (real
 * bytes on disk, not GD-generated, since GD is unavailable in this
 * environment) with a known, distinct SHA-256. After every single request,
 * the file on disk *and* the DB column are re-read (never the in-memory
 * entity from before the request) and hashed, for BOTH restaurants — the
 * one that just uploaded, and the one that didn't (to catch a write
 * silently landing on the wrong tenant). A single mismatch anywhere is a
 * real, reportable cross-tenant leak.
 *
 * NOTE: like SettingsControllerTest, this requires pdo_pgsql (present in
 * the qr-menu-php container, targeting the qrmenu_test database) but not
 * GD — no imagecreatetruecolor() call anywhere in this file.
 */
final class SettingsControllerCrossTenantLogoTest extends WebTestCase
{
    private const ITERATIONS = 10;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $hasher;
    private string $logoDir;

    private array $restaurantIdsToRemove = [];
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
        $restaurant->setSlug('crosscheck-' . uniqid());
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
        $user->setEmail('crosscheck-' . uniqid() . '@example.test');
        $user->setPassword($this->hasher->hashPassword($user, 'irrelevant-password-1'));
        $user->setRoles([User::ROLE_OWNER]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * A fresh UploadedFile instance every call, backed by a fresh temp COPY
     * of the fixture — never the fixture path itself. UploadedFile::move()
     * (called by SettingsController) physically moves its source file off
     * disk even in test mode, so pointing it straight at the master fixture
     * would consume it on the first upload and break every later iteration
     * that reuses the same restaurant's fixture.
     */
    private function fixtureUpload(string $fixturePath): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'crosscheck_upload_');
        copy($fixturePath, $tmpPath);

        return new UploadedFile($tmpPath, basename($fixturePath), 'image/png', null, true);
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

    /** Re-reads the restaurant row fresh from the DB (never the stale in-memory entity) and hashes its logo file on disk. */
    private function currentLogoHash(int $restaurantId): ?string
    {
        $this->em->clear();
        $restaurant = $this->em->getRepository(Restaurant::class)->find($restaurantId);
        $logo = $restaurant?->getLogo();
        if (!$logo) {
            return null;
        }

        $path = $this->logoDir . '/' . $logo;
        self::assertFileExists($path, "logo file for restaurant {$restaurantId} ({$logo}) missing from disk");

        return hash_file('sha256', $path);
    }

    public function testAlternatingUploadsNeverCrossTenants(): void
    {
        $fixtureA = __DIR__ . '/../fixtures/upload_crosscheck_a.png';
        $fixtureB = __DIR__ . '/../fixtures/upload_crosscheck_b.png';
        self::assertFileExists($fixtureA);
        self::assertFileExists($fixtureB);

        $hashA = hash_file('sha256', $fixtureA);
        $hashB = hash_file('sha256', $fixtureB);
        self::assertNotSame($hashA, $hashB, 'fixtures must be distinct for this test to mean anything');

        $restaurantA = $this->makeRestaurant('Crosscheck A');
        $ownerA = $this->makeOwner($restaurantA);
        $restaurantB = $this->makeRestaurant('Crosscheck B');
        $ownerB = $this->makeOwner($restaurantB);

        $idA = $restaurantA->getId();
        $idB = $restaurantB->getId();

        $aUploadedAtLeastOnce = false;
        $bUploadedAtLeastOnce = false;
        $newFilenamesAssigned = [];
        $lastFilenameA = null;
        $lastFilenameB = null;
        $iterationLog = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $useA = $i % 2 === 0;

            if ($useA) {
                $this->client->loginUser($ownerA);
                $this->submitLogo($restaurantA, $this->fixtureUpload($fixtureA));
                $aUploadedAtLeastOnce = true;
            } else {
                $this->client->loginUser($ownerB);
                $this->submitLogo($restaurantB, $this->fixtureUpload($fixtureB));
                $bUploadedAtLeastOnce = true;
            }

            $actualHashA = $this->currentLogoHash($idA);
            $actualHashB = $this->currentLogoHash($idB);

            $iterationLog[] = [
                'iteration' => $i,
                'uploaded'  => $useA ? 'A' : 'B',
                'hashA'     => $actualHashA,
                'hashB'     => $actualHashB,
            ];

            if ($aUploadedAtLeastOnce) {
                self::assertSame(
                    $hashA,
                    $actualHashA,
                    "iteration {$i}: restaurant A's logo does not match fixture A's bytes — " . json_encode($iterationLog)
                );
            }
            if ($bUploadedAtLeastOnce) {
                self::assertSame(
                    $hashB,
                    $actualHashB,
                    "iteration {$i}: restaurant B's logo does not match fixture B's bytes — " . json_encode($iterationLog)
                );
            }

            // The two tenants' current files must never be each other's, and
            // never each other's filename either.
            self::assertNotSame($actualHashA, $hashB, "iteration {$i}: restaurant A ended up holding restaurant B's fixture bytes");
            self::assertNotSame($actualHashB, $hashA, "iteration {$i}: restaurant B ended up holding restaurant A's fixture bytes");

            $this->em->clear();
            $freshA = $this->em->getRepository(Restaurant::class)->find($idA);
            $freshB = $this->em->getRepository(Restaurant::class)->find($idB);
            $currentFilenameA = $freshA?->getLogo();
            $currentFilenameB = $freshB?->getLogo();

            // Only a genuinely NEW filename (one this restaurant didn't
            // already have last iteration) counts as "assigned" — the
            // restaurant that wasn't touched this iteration keeps its old
            // filename unchanged, which is expected, not a collision.
            if ($currentFilenameA !== null && $currentFilenameA !== $lastFilenameA) {
                $newFilenamesAssigned[] = $currentFilenameA;
                $this->logoFilesToRemove[] = $currentFilenameA;
            }
            if ($currentFilenameB !== null && $currentFilenameB !== $lastFilenameB) {
                $newFilenamesAssigned[] = $currentFilenameB;
                $this->logoFilesToRemove[] = $currentFilenameB;
            }
            $lastFilenameA = $currentFilenameA;
            $lastFilenameB = $currentFilenameB;
        }

        // Every NEWLY assigned filename across all 10 iterations must be
        // unique — a repeat would mean the random name generator collided
        // or, worse, that some state was reused across requests/tenants.
        self::assertSame(
            count($newFilenamesAssigned),
            count(array_unique($newFilenamesAssigned)),
            'a logo filename was reused across iterations: ' . json_encode($newFilenamesAssigned)
        );
    }
}
