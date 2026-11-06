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
 * restaurants with /platform's cards, status and dates, "+ Nuevo
 * restaurante", "Registrar pago" / "Desactivar" (each restaurant's own
 * paid_until), archive / restore, "Entrar" as one of its owners only
 * (Security\SwitchUserVoter) and "Volver a mi panel".
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

    private function paidUntil(Restaurant $restaurant, string $date): void
    {
        (new \ReflectionProperty(Restaurant::class, 'paidUntil'))->setValue($restaurant, new \DateTimeImmutable($date));
    }

    private function row(Crawler $crawler, string $name): Crawler
    {
        $row = $crawler->filter('.active-list tr')->reduce(fn (Crawler $tr) => $tr->filter('.r-name')->count() && $tr->filter('.r-name')->text() === $name);
        self::assertCount(1, $row);

        return $row;
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

    /** @return array<string, string> the text of each field's own errors, keyed by the field's input name */
    private static function fieldErrors(Crawler $page): array
    {
        $errors = [];
        $page->filter('.field')->each(function (Crawler $field) use (&$errors) {
            $input = $field->filter('input, select');
            if ($input->count() > 0) {
                $errors[$input->attr('name')] = implode(' ', $field->filter('.errors li')->each(fn (Crawler $li) => $li->text()));
            }
        });

        return $errors;
    }

    public function testSignUpErrorsShowNextToTheirFieldAndKeepWhatWasTyped(): void
    {
        $crawler = $this->client->request('GET', '/partners/registro');
        // Turbo would reload this page (turbo-visit-control) and drop the errors.
        self::assertSame('false', $crawler->filter('form')->attr('data-turbo'));

        $form = $crawler->filter('form')->form();
        $form['reseller_registration_form[company]'] = 'Partners Test Kept Agency';
        $form['reseller_registration_form[name]'] = 'Ana';
        $form['reseller_registration_form[email]'] = 'partners-test-kept@example.test';
        $form['reseller_registration_form[password][first]'] = 'short';
        $form['reseller_registration_form[password][second]'] = 'short';
        $form['reseller_registration_form[adminLocale]'] = 'it';
        $page = $this->client->submit($form);

        self::assertResponseIsUnprocessable();
        $errors = self::fieldErrors($page);
        self::assertSame('Mínimo 8 caracteres.', $errors['reseller_registration_form[password][first]']);
        self::assertSame('', $errors['reseller_registration_form[email]']);
        self::assertCount(0, $page->filter('.form-errors'));

        self::assertSame('Partners Test Kept Agency', $page->filter('input[name="reseller_registration_form[company]"]')->attr('value'));
        self::assertSame('Ana', $page->filter('input[name="reseller_registration_form[name]"]')->attr('value'));
        self::assertSame('partners-test-kept@example.test', $page->filter('input[name="reseller_registration_form[email]"]')->attr('value'));
        self::assertSame('it', $page->filter('select[name="reseller_registration_form[adminLocale]"] option[selected]')->attr('value'));
        self::assertNull($page->filter('input[name="reseller_registration_form[password][first]"]')->attr('value'));
    }

    public function testSignUpMismatchedPasswordsShowNextToPassword(): void
    {
        $form = $this->client->request('GET', '/partners/registro')->filter('form')->form();
        $form['reseller_registration_form[company]'] = 'Partners Test Mismatch';
        $form['reseller_registration_form[name]'] = 'Ana';
        $form['reseller_registration_form[email]'] = 'partners-test-mismatch@example.test';
        $form['reseller_registration_form[password][first]'] = 'a-strong-password-1';
        $form['reseller_registration_form[password][second]'] = 'a-strong-password-2';
        $page = $this->client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame('Las contraseñas no coinciden.', self::fieldErrors($page)['reseller_registration_form[password][first]']);
    }

    public function testSignUpExpiredPageShowsGeneralErrorInBrowserLanguage(): void
    {
        $form = $this->client->request('GET', '/partners/registro', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr'])->filter('form')->form();
        $form['reseller_registration_form[_token]'] = 'expired';
        $form['reseller_registration_form[company]'] = 'Partners Test Expired';
        $form['reseller_registration_form[name]'] = 'Ana';
        $form['reseller_registration_form[email]'] = 'partners-test-expired@example.test';
        $form['reseller_registration_form[password][first]'] = 'a-strong-password-1';
        $form['reseller_registration_form[password][second]'] = 'a-strong-password-1';
        $page = $this->client->submit($form, serverParameters: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        self::assertResponseIsUnprocessable();
        self::assertSame('La page a expiré. Veuillez renvoyer le formulaire.', $page->filter('.form-errors[role="alert"] li')->text());
        self::assertSame('Partners Test Expired', $page->filter('input[name="reseller_registration_form[company]"]')->attr('value'));
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
        $form = $crawler->filter('form.login-form')->form(['email' => $user->getEmail(), 'password' => 'a-strong-password-1']);
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

    public function testPanelShowsCardsStatusAndDatesByPaidUntil(): void
    {
        // Bistro: an old 30-day trial (created before resellers' restaurants
        // lost theirs), never paid but still on.
        $paid = $this->restaurant('Partners Test Paid', $this->reseller);
        $paid->markPaid(Restaurant::PLAN_MONTHLY);
        $this->paidUntil($paid, 'today +3 days');
        $expired = $this->restaurant('Partners Test Expired', $this->reseller);
        $expired->markPaid(Restaurant::PLAN_MONTHLY, new \DateTimeImmutable('today -40 days'));
        $this->paidUntil($expired, 'today -2 days');
        // Never paid and off: how a reseller's restaurant starts now.
        $pending = $this->restaurant('Partners Test Pending', $this->reseller);
        $this->paidUntil($pending, 'yesterday');
        $archived = $this->restaurant('Partners Test Archived', $this->reseller);
        $archived->archive();
        $this->paidUntil($archived, 'today -10 days');
        $this->em->flush();

        $crawler = $this->panel();

        // Activos · Pendiente de pago · Vencen en 7 días · Vencidos (archived left out).
        self::assertSame(['2', '1', '1', '1'], $crawler->filter('.summary-card .summary-value')->each(fn (Crawler $n) => $n->text()));
        self::assertSame(['Activos', 'Pendiente de pago', 'Vencen en 7 días', 'Vencidos'], $crawler->filter('.summary-card .summary-label')->each(fn (Crawler $n) => $n->text()));
        self::assertStringNotContainsString('prueba', $crawler->filter('body')->text());

        // Soonest to expire first; archived below, as before.
        self::assertSame(['Partners Test Expired', 'Partners Test Pending', 'Partners Test Paid', 'Partners Test Bistro'], $crawler->filter('.active-list .r-name')->each(fn (Crawler $n) => $n->text()));
        self::assertSame(['Partners Test Archived'], $crawler->filter('.archived-list .r-name')->each(fn (Crawler $n) => $n->text()));

        $row = $this->row($crawler, 'Partners Test Pending');
        self::assertSame('⚪ Pendiente de pago', $row->filter('.status')->text());
        self::assertSame('Sin cobros', $row->filter('.last-paid')->text());
        self::assertSame('—', $row->filter('td.until')->text());

        $row = $this->row($crawler, 'Partners Test Expired');
        self::assertSame('🔴 Vencido', $row->filter('.status')->text());
        self::assertStringContainsString('until-expired', $row->filter('td.until')->attr('class'));
        self::assertSame('venció hace 2 días', $row->filter('td.until .sub')->text());

        $row = $this->row($crawler, 'Partners Test Paid');
        self::assertSame('🟢 Pagado', $row->filter('.status')->text());
        self::assertSame('Último cobro ' . date('d/m/Y'), $row->filter('.last-paid')->text());
        self::assertStringContainsString('until-soon', $row->filter('td.until')->attr('class'));
        self::assertSame((new \DateTimeImmutable('today +3 days'))->format('d/m/Y'), $row->filter('td.until > div')->first()->text());
        self::assertSame('quedan 3 días', $row->filter('td.until .sub')->text());

        $row = $this->row($crawler, 'Partners Test Bistro');
        self::assertSame('🟢 Activo', $row->filter('.status')->text());
        self::assertStringNotContainsString('until-', (string) $row->filter('td.until')->attr('class'));
        self::assertSame('quedan 30 días', $row->filter('td.until .sub')->text());
        self::assertSame('Registrar pago', $row->filter('button.register-payment')->text());
        self::assertCount(1, $row->filter('a.enter'));
        self::assertCount(1, $row->filter('button.deactivate'));
        self::assertCount(1, $row->filter('button.archive'));
    }

    public function testRegisterPayment(): void
    {
        $this->paidUntil($this->restaurant, 'today +2 days');
        $this->em->flush();

        $button = $this->row($this->panel(), 'Partners Test Bistro')->filter('button.register-payment');
        $expected = (new \DateTimeImmutable('today +2 days'))->modify('+1 year')->format('d/m/Y');
        self::assertSame($expected, $button->attr('data-until-yearly'));
        self::assertSelectorExists('#payment-modal input[name="plan"][value="monthly"]');
        self::assertSelectorExists('#payment-sheet input[name="plan"][value="yearly"]');

        $this->client->request('POST', $button->attr('data-action'), ['_token' => $button->attr('data-token'), 'plan' => 'yearly']);

        self::assertResponseRedirects('/partners');
        $restaurant = $this->reloadedRestaurant();
        self::assertSame($expected, $restaurant->getPaidUntil()->format('d/m/Y'));
        self::assertSame('yearly', $restaurant->getPlan());
        self::assertEquals(new \DateTimeImmutable('today'), $restaurant->getLastPaidAt());

        $this->client->followRedirect();
        self::assertSelectorTextSame('.flash-success', 'Pago registrado: Partners Test Bistro queda activo hasta el ' . $expected . '.');
    }

    public function testRegisterPaymentRejectsUnknownPlan(): void
    {
        $button = $this->row($this->panel(), 'Partners Test Bistro')->filter('button.register-payment');

        $this->client->request('POST', $button->attr('data-action'), ['_token' => $button->attr('data-token'), 'plan' => 'weekly']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testDeactivateTurnsMenuOffAndPaymentBringsItBack(): void
    {
        $crawler = $this->panel();
        $form = $this->row($crawler, 'Partners Test Bistro')->filter('form[action$="/desactivar"]');
        self::assertStringContainsString('Partners Test Bistro', $form->attr('onsubmit'));
        self::assertStringContainsString('Su carta dejar\u00e1 de verse', $form->attr('onsubmit'));

        $this->client->request('POST', $form->attr('action'), ['_token' => $form->filter('input[name="_token"]')->attr('value')]);

        self::assertResponseRedirects('/partners');
        $restaurant = $this->reloadedRestaurant();
        self::assertTrue($restaurant->isExpired());
        self::assertFalse($restaurant->isArchived());

        $crawler = $this->client->followRedirect();
        // Never paid: pending, not "vencido".
        self::assertSame('⚪ Pendiente de pago', $this->row($crawler, 'Partners Test Bistro')->filter('.status')->text());
        // Still counted: deactivating isn't archiving.
        self::assertSelectorTextSame('#restaurant-count', 'Restaurantes: 1 de 5 incluidos');

        $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=es');
        self::assertResponseStatusCodeSame(503);

        $button = $this->row($this->panel(), 'Partners Test Bistro')->filter('button.register-payment');
        $this->client->request('POST', $button->attr('data-action'), ['_token' => $button->attr('data-token'), 'plan' => 'monthly']);
        self::assertFalse($this->reloadedRestaurant()->isExpired());
    }

    public function testExpiredResellerCannotPayOrDeactivate(): void
    {
        $row = $this->row($this->panel(), 'Partners Test Bistro');
        $button = $row->filter('button.register-payment');
        $form = $row->filter('form[action$="/desactivar"]');
        $before = $this->reloadedRestaurant()->getPaidUntil();
        $this->expireReseller();

        $crawler = $this->panel();
        $row = $this->row($crawler, 'Partners Test Bistro');
        self::assertNotNull($row->filter('button.register-payment')->attr('disabled'));
        self::assertNotNull($row->filter('button.deactivate')->attr('disabled'));
        self::assertSelectorNotExists('#payment-modal');

        $this->client->request('POST', $button->attr('data-action'), ['_token' => $button->attr('data-token'), 'plan' => 'yearly']);
        self::assertResponseRedirects('/partners');
        $this->client->request('POST', $form->attr('action'), ['_token' => $form->filter('input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects('/partners');

        $restaurant = $this->reloadedRestaurant();
        self::assertEquals($before, $restaurant->getPaidUntil());
        self::assertNull($restaurant->getPlan());
    }

    public function testCannotPayOrDeactivateAnotherResellersRestaurant(): void
    {
        $other = new Reseller('Partners Test Other');
        $this->em->persist($other);
        $theirs = $this->restaurant('Partners Test Theirs', $other);
        $this->em->flush();

        $this->panel();
        $this->client->request('POST', '/partners/restaurantes/' . $theirs->getId() . '/pago', ['_token' => 'x', 'plan' => 'monthly']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/partners/restaurantes/' . $theirs->getId() . '/desactivar', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
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
        // No free trial: off (yesterday) until it's paid.
        self::assertEquals(new \DateTimeImmutable('yesterday'), $restaurant->getPaidUntil());
        self::assertNull($restaurant->getPlan());
        self::assertNull($restaurant->getLastPaidAt());
        self::assertTrue($restaurant->isExpired());
        self::assertTrue($restaurant->isAwaitingFirstPayment());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($owner, 'a-strong-password-1'));

        $this->client->followRedirect();
        self::assertSelectorTextSame('.flash-success', 'Partners Test New Place creado. Entra para preparar su carta. Se activará cuando pague.');
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

    /** The reseller "como" the owner: whole panel to set up an unpaid menu, which stays off for the public. */
    public function testResellerPreparesAnUnpaidRestaurantAsItsOwner(): void
    {
        $restaurant = $this->reloadedRestaurant();
        $restaurant->deactivateNow();
        $this->em->flush();
        self::assertTrue($restaurant->isAwaitingFirstPayment());

        $this->client->loginUser($this->resellerUser);
        $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($this->owner->getEmail()));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#choose-plan');

        $this->client->request('GET', '/r/' . $restaurant->getSlug() . '?lang=es');
        self::assertResponseStatusCodeSame(503);

        // The owner on its own still gets "Elige un plan".
        $this->client->request('GET', '/admin/menu?_switch_user=_exit');
        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/menu');
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextSame('#choose-plan', 'Elige un plan para activar tu carta');
    }

    public function testResellerAsOwnerAlsoGetsThePanelWhenItsPeriodExpired(): void
    {
        $restaurant = $this->reloadedRestaurant();
        $restaurant->markPaid(Restaurant::PLAN_MONTHLY, new \DateTimeImmutable('today -40 days'));
        $this->paidUntil($restaurant, 'today -3 days');
        $this->em->flush();

        $this->client->loginUser($this->resellerUser);
        $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($this->owner->getEmail()));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testNoPanelAsOwnerWhileArchivedOrResellerExpired(): void
    {
        $restaurant = $this->reloadedRestaurant();
        $restaurant->deactivateNow();
        $restaurant->archive();
        $this->em->flush();

        $this->client->loginUser($this->resellerUser);
        $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($this->owner->getEmail()));
        if ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }
        self::assertResponseStatusCodeSame(403);

        $restaurant = $this->reloadedRestaurant();
        $restaurant->unarchive();
        $this->em->flush();
        $this->expireReseller();

        $this->client->request('GET', '/admin/menu?_switch_user=_exit');
        $this->client->loginUser($this->resellerUser);
        $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($this->owner->getEmail()));
        if ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }
        self::assertResponseStatusCodeSame(403);
    }

    public function testSuperAdminAsOwnerPreparesAnUnpaidRestaurant(): void
    {
        $restaurant = $this->reloadedRestaurant();
        $restaurant->deactivateNow();
        $this->em->flush();
        $super = $this->user('partners-test-super', User::ROLE_SUPER_ADMIN);
        $this->em->flush();

        $this->client->loginUser($super);
        $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($this->owner->getEmail()));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
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

    // ── The super-admin "como" a reseller ───────────────────────────────────

    public function testPlatformListsEnterForTheReseller(): void
    {
        $super = $this->user('partners-test-super', User::ROLE_SUPER_ADMIN);
        $this->em->flush();

        $this->client->loginUser($super);
        $crawler = $this->client->request('GET', '/platform');
        self::assertResponseIsSuccessful();
        $href = '/partners?_switch_user=' . $this->resellerUser->getEmail();
        self::assertCount(1, $crawler->filter('#resellers a.enter')->reduce(fn (Crawler $a) => $a->attr('href') === $href));
    }

    public function testSuperAdminEntersResellerPanelAndGoesBackToPlatform(): void
    {
        $super = $this->user('partners-test-super', User::ROLE_SUPER_ADMIN);
        $this->em->flush();

        $this->client->loginUser($super);
        $this->client->request('GET', '/partners?_switch_user=' . urlencode($this->resellerUser->getEmail()));
        self::assertResponseRedirects('http://localhost/partners');

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.partners-title', 'Partners Test Agency');
        self::assertSelectorTextSame('.header-actions a[href="/platform?_switch_user=_exit"]', 'Volver a Plataforma');
        self::assertCount(0, $crawler->filter('a[href="/admin/logout"]'));

        // A second request still works as the reseller, not sent away.
        $this->client->request('GET', '/partners');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/platform?_switch_user=_exit');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.platform-title', 'Plataforma');
    }

    /** No chaining: "Entrar" from the reseller's panel lands as the owner, and "Volver" goes to /platform. */
    public function testSuperAdminAsResellerEntersOwnerDirectly(): void
    {
        $super = $this->user('partners-test-super', User::ROLE_SUPER_ADMIN);
        $this->em->flush();

        $this->client->loginUser($super);
        $this->client->request('GET', '/partners?_switch_user=' . urlencode($this->resellerUser->getEmail()));
        $crawler = $this->client->followRedirect();
        $enter = $this->row($crawler, 'Partners Test Bistro')->filter('a.enter')->attr('href');

        $this->client->request('GET', $enter);
        self::assertResponseRedirects('http://localhost/admin/menu');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.sidebar-footer a.nav-item[href="/platform?_switch_user=_exit"]', 'Volver a Plataforma');
        self::assertSelectorExists('.header-icons a[href="/platform?_switch_user=_exit"][aria-label="Volver a Plataforma"]');

        $this->client->request('GET', '/platform?_switch_user=_exit');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.platform-title', 'Plataforma');
    }

    public function testResellerCannotEnterAsSuperAdminOrAnotherReseller(): void
    {
        $super = $this->user('partners-test-super', User::ROLE_SUPER_ADMIN);
        $other = new Reseller('Partners Test Other');
        $other->markPaid(Reseller::PLAN_MONTHLY);
        $this->em->persist($other);
        $otherUser = $this->user('partners-test-other-reseller', User::ROLE_RESELLER);
        $otherUser->setReseller($other);
        $this->em->flush();

        $this->client->loginUser($this->resellerUser);
        foreach ([$super, $otherUser] as $target) {
            foreach (['/partners', '/admin/menu'] as $path) {
                $this->client->request('GET', $path . '?_switch_user=' . urlencode($target->getEmail()));
                self::assertResponseStatusCodeSame(403, $path . ' ' . $target->getEmail());
            }
        }
    }

    public function testPlatformEntersAResellersRestaurantAsItsOwner(): void
    {
        $super = $this->user('partners-test-super', User::ROLE_SUPER_ADMIN);
        $this->em->flush();

        $this->client->loginUser($super);
        $crawler = $this->client->request('GET', '/platform');
        $row = $crawler->filter('tbody tr')->reduce(fn (Crawler $tr) => $tr->filter('.r-name')->count() && $tr->filter('.r-name')->text() === 'Partners Test Bistro');
        self::assertCount(1, $row);
        self::assertSame('/admin/menu?_switch_user=' . $this->owner->getEmail(), $row->filter('a.enter')->attr('href'));

        $this->client->request('GET', $row->filter('a.enter')->attr('href'));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.sidebar-footer a.nav-item[href="/platform?_switch_user=_exit"]', 'Volver a Plataforma');
    }

    /** Nobody as a super-admin: not another super-admin, a reseller nor an owner. */
    public function testNobodyCanEnterAsASuperAdmin(): void
    {
        $super = $this->user('partners-test-super', User::ROLE_SUPER_ADMIN);
        $otherSuper = $this->user('partners-test-super-other', User::ROLE_SUPER_ADMIN);
        $this->em->flush();

        foreach ([$otherSuper, $this->resellerUser, $this->owner] as $actor) {
            $this->client->loginUser($actor);
            foreach (['/admin/menu', '/platform', '/partners'] as $path) {
                $this->client->request('GET', $path . '?_switch_user=' . urlencode($super->getEmail()));
                self::assertResponseStatusCodeSame(403, $actor->getEmail() . ' ' . $path);
            }
        }

        // Nor the super-admin already "como" a reseller (Symfony exits first, then decides as the super-admin).
        $this->client->loginUser($otherSuper);
        $this->client->request('GET', '/partners?_switch_user=' . urlencode($this->resellerUser->getEmail()));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/partners?_switch_user=' . urlencode($super->getEmail()));
        self::assertResponseStatusCodeSame(403);
    }
}
