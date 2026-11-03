<?php

namespace App\Tests\Controller;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The reseller's sign-up (/partners/registro) and panel (/partners): its
 * restaurants, "+ Nuevo restaurante", archive / restore, "Entrar" as one of
 * its owners only (Security\SwitchUserVoter) and "Volver a mi panel".
 */
final class PartnersControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Reseller $reseller;
    private User $resellerUser;
    private Restaurant $restaurant;
    private User $owner;

    /** @var list<string> emails of users created through the app, removed in tearDown() */
    private array $createdEmails = [];

    protected function setUp(): void
    {
        parent::setUp();
        // As a browser would: the logged-out sign-up follows it.
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->reseller = new Reseller('Partners Test Agency');
        $this->reseller->markPaid(Reseller::PLAN_MONTHLY);
        $this->em->persist($this->reseller);

        $this->resellerUser = $this->user('partners-test-reseller', User::ROLE_RESELLER);
        $this->resellerUser->setReseller($this->reseller);

        $this->restaurant = $this->restaurant('Partners Test Bistro', $this->reseller);
        $this->owner = $this->user('partners-test-owner', User::ROLE_OWNER, $this->restaurant);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        // Users, then restaurants, then resellers: the foreign keys are RESTRICT.
        $this->em->clear();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM "user" WHERE email LIKE \'partners-test-%\' OR email IN (?)', [$this->createdEmails ?: ['']], [\Doctrine\DBAL\ArrayParameterType::STRING]);
        foreach ($this->em->getRepository(Restaurant::class)->createQueryBuilder('r')->where('r.slug LIKE :p')->setParameter('p', 'partners-test-%')->getQuery()->getResult() as $restaurant) {
            $this->em->remove($restaurant);
        }
        $this->em->flush();
        $conn->executeStatement("DELETE FROM reseller WHERE name LIKE 'Partners Test%' OR name IN ('Signup Test Agency', 'Dup Agency')");

        parent::tearDown();
    }

    /** Follows every redirect and returns the path finally shown. */
    private function finalPath(): string
    {
        while ($this->client->getResponse()->isRedirection()) {
            $this->client->followRedirect();
        }

        return (string) parse_url($this->client->getRequest()->getUri(), PHP_URL_PATH);
    }

    private function user(string $prefix, string $role, ?Restaurant $restaurant = null): User
    {
        $user = new User();
        $user->setEmail($prefix . '-' . uniqid() . '@example.test');
        $user->setPassword('unused-in-tests');
        $user->setRoles([$role]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);

        return $user;
    }

    private function restaurant(string $name, ?Reseller $reseller): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName($name);
        $restaurant->setSlug('partners-test-' . uniqid());
        $restaurant->setReseller($reseller);
        $this->em->persist($restaurant);

        return $restaurant;
    }

    private function panel(): Crawler
    {
        $this->client->loginUser($this->resellerUser);
        $crawler = $this->client->request('GET', '/partners');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function expireReseller(): void
    {
        $this->em->clear();
        $this->em->find(Reseller::class, $this->reseller->getId())->deactivateNow();
        $this->em->flush();
    }

    private function createRestaurant(string $name, string $email, string $password = 'a-strong-password-1'): void
    {
        $form = $this->panel()->filter('form.new-restaurant-form')->first();
        $this->client->request('POST', '/partners/restaurantes', [
            '_token'   => $form->filter('input[name="_token"]')->attr('value'),
            'name'     => $name,
            'email'    => $email,
            'password' => $password,
        ]);
    }

    private function reloadedRestaurant(): Restaurant
    {
        $this->em->clear();

        return $this->em->find(Restaurant::class, $this->restaurant->getId());
    }

    // ── Sign-up ─────────────────────────────────────────────────────────────

    public function testSignUpCreatesExpiredResellerAndLogsIn(): void
    {
        $email = 'partners-signup-' . uniqid() . '@example.test';
        $this->createdEmails[] = $email;

        $crawler = $this->client->request('GET', '/partners/registro');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.card-title', 'Alta de revendedor');

        $form = $crawler->filter('form')->form();
        $form['reseller_registration_form[company]'] = 'Signup Test Agency';
        $form['reseller_registration_form[name]'] = 'Ana';
        $form['reseller_registration_form[email]'] = $email;
        $form['reseller_registration_form[password][first]'] = 'a-strong-password-1';
        $form['reseller_registration_form[password][second]'] = 'a-strong-password-1';
        $form['reseller_registration_form[adminLocale]'] = 'en';
        $this->client->submit($form);

        self::assertResponseRedirects('/partners');

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertSame([User::ROLE_RESELLER, 'ROLE_USER'], $user->getRoles());
        self::assertNull($user->getRestaurant());
        self::assertSame('Ana', $user->getFirstName());
        self::assertSame('Signup Test Agency', $user->getReseller()->getName());
        self::assertSame('en', $user->getReseller()->getAdminLocale());
        self::assertTrue($user->getReseller()->isExpired(), 'no trial: expired until it pays');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.partners-title', 'Signup Test Agency');
        self::assertSelectorExists('#account-inactive');
    }

    public function testSignUpFollowsBrowserLanguage(): void
    {
        $this->client->request('GET', '/partners/registro', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']);

        self::assertSelectorTextSame('.card-title', 'Inscription revendeur');
        self::assertSelectorExists('select[name="reseller_registration_form[adminLocale]"] option[value="fr"][selected]');
    }

    public function testSignUpRejectsTakenEmail(): void
    {
        $crawler = $this->client->request('GET', '/partners/registro');
        $form = $crawler->filter('form')->form();
        $form['reseller_registration_form[company]'] = 'Dup Agency';
        $form['reseller_registration_form[name]'] = 'Ana';
        $form['reseller_registration_form[email]'] = $this->owner->getEmail();
        $form['reseller_registration_form[password][first]'] = 'a-strong-password-1';
        $form['reseller_registration_form[password][second]'] = 'a-strong-password-1';
        $this->client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertContains('Ya hay una cuenta con este email.', $this->client->getCrawler()->filter('.errors li')->each(fn (Crawler $li) => $li->text()));
        $this->em->clear();
        self::assertNull($this->em->getRepository(Reseller::class)->findOneBy(['name' => 'Dup Agency']));
    }

    public function testSignUpValidatesLikeRegister(): void
    {
        $crawler = $this->client->request('GET', '/partners/registro');
        $form = $crawler->filter('form')->form();
        $form['reseller_registration_form[company]'] = '';
        $form['reseller_registration_form[name]'] = 'Ana';
        $form['reseller_registration_form[email]'] = 'not-an-email';
        $form['reseller_registration_form[password][first]'] = 'short';
        $form['reseller_registration_form[password][second]'] = 'short';
        $this->client->submit($form);

        self::assertResponseIsUnprocessable();
        $errors = $this->client->getCrawler()->filter('.errors li')->each(fn (Crawler $li) => $li->text());
        self::assertContains('Introduce el nombre de la empresa.', $errors);
        self::assertContains('El email no es válido.', $errors);
        self::assertContains('Mínimo 8 caracteres.', $errors);
    }

    public function testSignUpIsLinkedFromNothingRestaurantsSee(): void
    {
        foreach (['/', '/register', '/admin/login', '/r/' . $this->restaurant->getSlug() . '?lang=es'] as $path) {
            $this->client->request('GET', $path);
            self::assertStringNotContainsString('href="/partners', (string) $this->client->getResponse()->getContent(), $path);
            self::assertStringNotContainsString('/partners/registro', (string) $this->client->getResponse()->getContent(), $path);
        }
    }

    // ── Access ──────────────────────────────────────────────────────────────

    public function testResellerOpeningAdminGoesToPartners(): void
    {
        $this->client->loginUser($this->resellerUser);

        foreach (['/admin', '/admin/menu', '/admin/settings', '/admin/login'] as $path) {
            $this->client->request('GET', $path);
            self::assertSame('/partners', $this->finalPath(), $path);
        }
    }

    public function testLoginSendsResellerToPartners(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = $this->em->find(User::class, $this->resellerUser->getId());
        $user->setPassword($hasher->hashPassword($user, 'a-strong-password-1'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/login');
        $form = $crawler->filter('form')->form(['email' => $user->getEmail(), 'password' => 'a-strong-password-1']);
        $this->client->submit($form);

        self::assertSame('/partners', $this->finalPath());
        self::assertSelectorTextSame('.partners-title', 'Partners Test Agency');
    }

    public function testOthersCannotOpenPartners(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/partners');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/partners/registro');
        self::assertResponseRedirects();
    }

    // ── Panel ───────────────────────────────────────────────────────────────

    public function testPanelListsOwnRestaurantsOnly(): void
    {
        $this->restaurant('Partners Test Someone Else', null);
        $this->em->flush();

        $crawler = $this->panel();

        self::assertSelectorTextSame('.partners-title', 'Partners Test Agency');
        self::assertSelectorExists('header a[href="/admin/logout"]');
        self::assertSame(['Partners Test Bistro'], $crawler->filter('.active-list .r-name')->each(fn (Crawler $n) => $n->text()));
        $row = $crawler->filter('.active-list tr')->first();
        self::assertStringContainsString($this->owner->getEmail(), $row->text());
        self::assertCount(1, $row->filter('a[href="/r/' . $this->restaurant->getSlug() . '"]'));
        self::assertSame('/admin/menu?_switch_user=' . $this->owner->getEmail(), $row->filter('a.enter')->attr('href'));
        self::assertSelectorTextSame('#restaurant-count', 'Restaurantes: 1 de 5 incluidos');
        self::assertSelectorNotExists('#account-inactive');
    }

    public function testCountShowsExtraOverFive(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->restaurant('Partners Test Extra ' . $i, $this->reseller);
        }
        $archived = $this->restaurant('Partners Test Archived', $this->reseller);
        $archived->archive();
        $this->em->flush();

        $this->panel();

        self::assertSelectorTextSame('#restaurant-count', 'Restaurantes: 7 · 2 extra');
    }

    public function testExpiredResellerSeesListWithEverythingDisabled(): void
    {
        $archived = $this->restaurant('Partners Test Archived', $this->reseller);
        $archived->archive();
        $this->em->flush();
        $this->expireReseller();

        $crawler = $this->panel();

        self::assertSelectorTextContains('#account-inactive', 'Tu cuenta no está activa');
        self::assertCount(1, $crawler->filter('.active-list .r-name'));
        self::assertNotNull($crawler->filter('#new-restaurant')->attr('disabled'));
        self::assertNotNull($crawler->filter('.active-list .enter')->attr('disabled'));
        self::assertNotNull($crawler->filter('.active-list button.archive')->attr('disabled'));
        self::assertNotNull($crawler->filter('.archived-list button.restore')->attr('disabled'));
        self::assertSelectorNotExists('form.new-restaurant-form');
        self::assertSelectorNotExists('a[href*="_switch_user"]');
    }

    public function testCreateRestaurantWithOwner(): void
    {
        $email = 'partners-new-owner-' . uniqid() . '@example.test';
        $this->createdEmails[] = $email;

        $this->createRestaurant('Partners Test New Place', $email);

        self::assertResponseRedirects('/partners');
        $this->em->clear();
        $owner = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertSame([User::ROLE_OWNER, 'ROLE_USER'], $owner->getRoles());
        $restaurant = $owner->getRestaurant();
        self::assertSame('Partners Test New Place', $restaurant->getName());
        self::assertSame('partners-test-new-place', $restaurant->getSlug());
        self::assertSame($this->reseller->getId(), $restaurant->getReseller()->getId());
        self::assertSame('EUR', $restaurant->getCurrency());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($owner, 'a-strong-password-1'));

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Partners Test New Place');
        self::assertSelectorTextSame('#restaurant-count', 'Restaurantes: 2 de 5 incluidos');
    }

    public function testCreateRestaurantSlugIsUnique(): void
    {
        $email = 'partners-new-owner-' . uniqid() . '@example.test';
        $this->createdEmails[] = $email;

        $this->createRestaurant('Partners Test Bistro', $email);

        $this->em->clear();
        $slug = $this->em->getRepository(User::class)->findOneBy(['email' => $email])->getRestaurant()->getSlug();
        self::assertNotSame($this->restaurant->getSlug(), $slug);
        self::assertStringStartsWith('partners-test-bistro', $slug);
    }

    public function testCreateRestaurantWithTakenEmailShowsClearError(): void
    {
        $this->createRestaurant('Partners Test Dup', $this->resellerUser->getEmail());

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextSame('#new-modal .form-error', 'Ya hay una cuenta con ese email. Usa otro para el dueño.');
        self::assertSame('Partners Test Dup', $this->client->getCrawler()->filter('#new-modal input[name="name"]')->attr('value'));
        self::assertSame('', (string) $this->client->getCrawler()->filter('#new-modal input[name="password"]')->attr('value'));
        $this->em->clear();
        self::assertNull($this->em->getRepository(Restaurant::class)->findOneBy(['name' => 'Partners Test Dup']));
    }

    public function testCreateRestaurantRejectsShortPassword(): void
    {
        $this->createRestaurant('Partners Test Short', 'partners-test-short-' . uniqid() . '@example.test', 'short');

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextSame('#new-modal .form-error', 'La contraseña debe tener al menos 8 caracteres.');
    }

    public function testExpiredResellerCannotCreate(): void
    {
        $token = $this->panel()->filter('form.new-restaurant-form input[name="_token"]')->first()->attr('value');
        $this->expireReseller();

        $this->client->request('POST', '/partners/restaurantes', ['_token' => $token, 'name' => 'Partners Test Late', 'email' => 'partners-test-late-' . uniqid() . '@example.test', 'password' => 'a-strong-password-1']);

        self::assertResponseRedirects('/partners');
        $this->em->clear();
        self::assertNull($this->em->getRepository(Restaurant::class)->findOneBy(['name' => 'Partners Test Late']));
    }

    public function testArchiveAndRestore(): void
    {
        $crawler = $this->panel();
        $form = $crawler->filter('.active-list form[action$="/archivar"]');
        self::assertStringContainsString('La carta dejar\\u00e1 de verse', $form->attr('onsubmit'));

        $this->client->request('POST', $form->attr('action'), ['_token' => $form->filter('input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects('/partners');
        self::assertTrue($this->reloadedRestaurant()->isArchived());

        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('.active-list .r-name'));
        self::assertSame(['Partners Test Bistro'], $crawler->filter('.archived-list .r-name')->each(fn (Crawler $n) => $n->text()));
        self::assertSelectorTextSame('#restaurant-count', 'Restaurantes: 0 de 5 incluidos');

        $restore = $crawler->filter('.archived-list form[action$="/restaurar"]');
        $this->client->request('POST', $restore->attr('action'), ['_token' => $restore->filter('input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects('/partners');
        self::assertFalse($this->reloadedRestaurant()->isArchived());
    }

    public function testCannotArchiveAnotherResellersRestaurant(): void
    {
        $other = new Reseller('Partners Test Other');
        $this->em->persist($other);
        $theirs = $this->restaurant('Partners Test Theirs', $other);
        $this->em->flush();

        $this->panel();
        $this->client->request('POST', '/partners/restaurantes/' . $theirs->getId() . '/archivar', ['_token' => 'x']);

        self::assertResponseStatusCodeSame(404);
        $this->em->clear();
        self::assertFalse($this->em->find(Restaurant::class, $theirs->getId())->isArchived());
    }

    // ── "Entrar" ────────────────────────────────────────────────────────────

    public function testEnterOwnRestaurantAndComeBack(): void
    {
        $this->client->loginUser($this->resellerUser);
        $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($this->owner->getEmail()));
        self::assertResponseRedirects('http://localhost/admin/menu');

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        // Desktop sidebar and mobile header: back to the panel, not logout.
        self::assertSelectorTextSame('.sidebar-footer a.nav-item[href="/partners?_switch_user=_exit"]', 'Volver a mi panel');
        self::assertSelectorExists('.header-icons a[href="/partners?_switch_user=_exit"][aria-label="Volver a mi panel"]');
        self::assertCount(0, $crawler->filter('a[href="/admin/logout"]'));
        self::assertCount(0, $crawler->filter('a[href*="/platform"]'));

        // A second request still works (SwitchUserMarker keeps the session).
        $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/partners?_switch_user=_exit');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.partners-title', 'Partners Test Agency');
    }

    public function testEnterExpiredRestaurantShowsWayBack(): void
    {
        $this->em->clear();
        $this->em->find(Restaurant::class, $this->restaurant->getId())->archive();
        $this->em->flush();

        $this->client->loginUser($this->resellerUser);
        $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($this->owner->getEmail()));
        $this->client->followRedirect();

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextSame('a.logout[href="/partners?_switch_user=_exit"]', 'Volver a mi panel');
    }

    public function testCannotEnterOtherOwners(): void
    {
        $selfBilled = $this->restaurant('Partners Test Self Billed', null);
        $selfBilledOwner = $this->user('partners-test-self-owner', User::ROLE_OWNER, $selfBilled);
        $other = new Reseller('Partners Test Other');
        $other->markPaid(Reseller::PLAN_MONTHLY);
        $this->em->persist($other);
        $otherUser = $this->user('partners-test-other-reseller', User::ROLE_RESELLER);
        $otherUser->setReseller($other);
        $staff = $this->user('partners-test-staff', User::ROLE_STAFF, $this->restaurant);
        $this->em->flush();

        $this->client->loginUser($this->resellerUser);
        foreach ([$selfBilledOwner, $otherUser, $staff] as $target) {
            $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($target->getEmail()));
            self::assertResponseStatusCodeSame(403, $target->getEmail());
        }
    }

    public function testExpiredResellerCannotEnter(): void
    {
        $this->expireReseller();

        $this->client->loginUser($this->resellerUser);
        $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($this->owner->getEmail()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerCannotSwitch(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($this->resellerUser->getEmail()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testSuperAdminStillEntersAnyOwnerAndGoesBackToPlatform(): void
    {
        $super = $this->user('partners-test-super', User::ROLE_SUPER_ADMIN);
        $this->em->flush();

        $this->client->loginUser($super);
        $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($this->owner->getEmail()));
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.sidebar-footer a.nav-item[href="/platform?_switch_user=_exit"]', 'Volver a Plataforma');
        self::assertSelectorExists('.header-icons a[href="/platform?_switch_user=_exit"][aria-label="Volver a Plataforma"]');
    }
}
