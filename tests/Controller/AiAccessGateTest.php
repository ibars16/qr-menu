<?php

namespace App\Tests\Controller;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * EventSubscriber\AiAccessGate + Service\AiAccess in /admin, as the reseller
 * (or the super-admin) "como" the owner — the only ones who reach the
 * panel of a restaurant that's off:
 *
 * - "Pendiente de pago" (never paid): "Importar carta con IA" (and translating
 *   that import into the main language) goes on, and the free AI photos
 *   while any are left; every other AI (language change…) is refused.
 * - Trial over (never paid): only the free AI photos left.
 * - Expired after paying: every AI refused, import included.
 *
 * No request here gets to the AI: the allowed ones stop at their own
 * validation (empty name, no files, bad CSRF token) right after the gate.
 */
final class AiAccessGateTest extends WebTestCase
{
    private const BLOCKED = 'Tu suscripción no está activa. Elige un plan para seguir usando la IA.';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Reseller $reseller;
    private User $resellerUser;
    private Restaurant $restaurant;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->reseller = new Reseller('Ai Gate Test Agency');
        $this->reseller->markPaid(Reseller::PLAN_MONTHLY);
        $this->em->persist($this->reseller);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Ai Gate Test Bistro');
        $this->restaurant->setSlug('ai-gate-test-' . uniqid());
        $this->restaurant->setDefaultLanguage('es');
        $this->restaurant->setAdminLocale('es');
        $this->restaurant->setReseller($this->reseller);
        $this->em->persist($this->restaurant);

        $this->resellerUser = $this->user(User::ROLE_RESELLER);
        $this->resellerUser->setReseller($this->reseller);
        $this->owner = $this->user(User::ROLE_OWNER, $this->restaurant);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM "user" WHERE email LIKE \'ai-gate-test-%\'');
        $conn->executeStatement('DELETE FROM restaurant WHERE id = ?', [$this->restaurant->getId()]);
        $conn->executeStatement('DELETE FROM reseller WHERE id = ?', [$this->reseller->getId()]);

        parent::tearDown();
    }

    private function user(string $role, ?Restaurant $restaurant = null): User
    {
        $user = new User();
        $user->setEmail('ai-gate-test-' . uniqid() . '@example.test');
        $user->setPassword('unused-in-tests');
        $user->setRoles([$role]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);

        return $user;
    }

    private function awaitingFirstPayment(): void
    {
        $this->restaurant->deactivateNow();
        $this->em->flush();
        self::assertTrue($this->restaurant->isAwaitingFirstPayment());
    }

    private function expiredAfterPaying(): void
    {
        $this->restaurant->markPaid(Restaurant::PLAN_MONTHLY);
        (new \ReflectionProperty(Restaurant::class, 'paidUntil'))->setValue($this->restaurant, new \DateTimeImmutable('today -10 days'));
        $this->em->flush();
        self::assertTrue($this->restaurant->isExpired());
        self::assertFalse($this->restaurant->isAwaitingFirstPayment());
    }

    private function enterAs(User $who): void
    {
        $this->client->loginUser($who);
        $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($this->owner->getEmail()));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function generatePhoto(): void
    {
        $this->client->request('POST', '/admin/products/ai-photo/generate', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['name' => '']));
    }

    /** The upload with no files: past the gate it's "Elige al menos una foto o un PDF", never the AI. */
    private function uploadPageAfterImport(): string
    {
        $this->client->request('POST', '/admin/menu/import/upload');
        self::assertResponseRedirects('/admin/menu/import');
        $this->client->followRedirect();

        return (string) $this->client->getResponse()->getContent();
    }

    private function assertBlockedJson(): void
    {
        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(self::BLOCKED, $data['error'] ?? null);
        self::assertSame(self::BLOCKED, $data['message'] ?? null);
    }

    public function testActiveOwnerPassesTheGate(): void
    {
        $this->client->loginUser($this->owner);
        $this->generatePhoto();

        self::assertResponseStatusCodeSame(400, 'the action itself ran: empty name');
    }

    public function testAwaitingFirstPaymentResellerMayImport(): void
    {
        $this->awaitingFirstPayment();
        $this->enterAs($this->resellerUser);

        self::assertStringNotContainsString(self::BLOCKED, $this->uploadPageAfterImport());
    }

    public function testAwaitingFirstPaymentSuperAdminMayImport(): void
    {
        $this->awaitingFirstPayment();
        $super = $this->user(User::ROLE_SUPER_ADMIN);
        $this->em->flush();
        $this->enterAs($super);

        self::assertStringNotContainsString(self::BLOCKED, $this->uploadPageAfterImport());
    }

    /** Never paid: the free AI photos left still work (the action itself answers: empty name). */
    public function testAwaitingFirstPaymentKeepsItsFreePhotos(): void
    {
        $this->awaitingFirstPayment();
        $this->enterAs($this->resellerUser);

        $this->generatePhoto();
        self::assertResponseStatusCodeSame(400);
    }

    public function testTrialOverKeepsItsFreePhotosButNotTheImport(): void
    {
        $this->restaurant->setReseller(null);
        $this->restaurant->deactivateNow();
        $this->em->flush();
        $super = $this->user(User::ROLE_SUPER_ADMIN);
        $this->em->flush();
        $this->enterAs($super);

        $this->generatePhoto();
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString(self::BLOCKED, $this->uploadPageAfterImport());

        $this->usedFreePhotos();
        $this->generatePhoto();
        $this->assertBlockedJson();
    }

    private function usedFreePhotos(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE restaurant SET ai_photo_count = 2 WHERE id = ?', [$this->restaurant->getId()]);
    }

    public function testAwaitingFirstPaymentBlocksPhotosOnceTheFreeOnesAreUsed(): void
    {
        $this->awaitingFirstPayment();
        $this->usedFreePhotos();
        $this->enterAs($this->resellerUser);

        $this->generatePhoto();
        $this->assertBlockedJson();

        $this->client->request('POST', '/admin/products/1/ai-photo/enhance');
        $this->assertBlockedJson();

        $this->client->request('POST', '/admin/menu/import/1/photos/step');
        $this->assertBlockedJson();
    }

    public function testAwaitingFirstPaymentBlocksLanguageChange(): void
    {
        $this->awaitingFirstPayment();
        $this->enterAs($this->resellerUser);

        $this->client->request('POST', '/admin/idioma/convertir');
        $this->assertBlockedJson();
    }

    /** Translating the imported menu into the main language is part of the import. */
    public function testAwaitingFirstPaymentMayTranslateTheImport(): void
    {
        $this->awaitingFirstPayment();
        $this->enterAs($this->resellerUser);

        $this->client->request('POST', '/admin/menu/import/999999999/language/translate');

        self::assertResponseStatusCodeSame(404, 'past the gate: the batch itself is looked up (none), no AI');
        self::assertStringNotContainsString(self::BLOCKED, (string) $this->client->getResponse()->getContent());
    }

    public function testAwaitingFirstPaymentSettingsDoesNotChangeTheLanguage(): void
    {
        $this->awaitingFirstPayment();
        $this->enterAs($this->resellerUser);

        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/admin/settings', [
            '_token'          => $crawler->filter('input[name="_token"]')->attr('value'),
            'section'         => 'language',
            'name'            => 'Ai Gate Test Bistro',
            'tagline'         => '',
            'currency'        => 'EUR',
            'defaultLanguage' => 'fr',
            'adminLocale'     => 'es',
        ]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertStringContainsString(self::BLOCKED, (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        $restaurant = $this->em->find(Restaurant::class, $this->restaurant->getId());
        self::assertSame('es', $restaurant->getDefaultLanguage());
        self::assertNull($restaurant->getLanguageChangeTo());
    }

    public function testExpiredAfterPayingBlocksEverythingAsReseller(): void
    {
        $this->expiredAfterPaying();
        $this->enterAs($this->resellerUser);

        self::assertStringContainsString(self::BLOCKED, $this->uploadPageAfterImport());

        $this->generatePhoto();
        $this->assertBlockedJson();

        $this->client->request('POST', '/admin/idioma/convertir');
        $this->assertBlockedJson();

        $this->client->request('POST', '/admin/menu/import/1/language/translate');
        $this->assertBlockedJson();
    }

    public function testExpiredAfterPayingBlocksEverythingAsSuperAdmin(): void
    {
        $this->expiredAfterPaying();
        $super = $this->user(User::ROLE_SUPER_ADMIN);
        $this->em->flush();
        $this->enterAs($super);

        self::assertStringContainsString(self::BLOCKED, $this->uploadPageAfterImport());

        $this->generatePhoto();
        $this->assertBlockedJson();
    }
}
