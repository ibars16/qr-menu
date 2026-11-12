<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Tests\Support\FakeStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * /admin/billing/*: owner only, CSRF-protected, reachable while expired;
 * never a second subscription (a live one goes to the subscription screen
 * instead). The screen itself (status, card, plan change, cancel,
 * invoices) against FakeStripeGateway. Plus where the owner reaches it:
 * trial badge, sidebar, mobile header, expired page — and staff never
 * sees it.
 */
final class BillingControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Restaurant $restaurant;
    private User $owner;
    private User $staff;
    /** @var list<int> */
    private array $extraUsers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        FakeStripeGateway::reset();

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Billing Test Restaurant');
        $this->restaurant->setSlug('billing-test-' . uniqid());
        $this->restaurant->setAdminLocale('es');
        $this->em->persist($this->restaurant);

        $this->owner = $this->user(User::ROLE_OWNER);
        $this->staff = $this->user(User::ROLE_STAFF);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->remove($this->em->find(User::class, $this->owner->getId()));
        $this->em->remove($this->em->find(User::class, $this->staff->getId()));
        foreach ($this->extraUsers as $id) {
            $this->em->remove($this->em->find(User::class, $id));
        }
        $this->em->remove($this->em->find(Restaurant::class, $this->restaurant->getId()));
        $this->em->flush();

        parent::tearDown();
    }

    private function user(string $role): User
    {
        $user = new User();
        $user->setEmail('billing-test-' . uniqid() . '@example.test');
        $user->setPassword('unused-in-tests');
        $user->setRoles([$role]);
        $user->setRestaurant($this->restaurant);
        $this->em->persist($user);

        return $user;
    }

    private function managed(): Restaurant
    {
        $this->em->clear();

        return $this->em->find(Restaurant::class, $this->restaurant->getId());
    }

    private function expire(): void
    {
        $restaurant = $this->managed();
        $restaurant->deactivateNow();
        $this->em->flush();
    }

    private function linkSubscription(): void
    {
        $restaurant = $this->managed();
        $restaurant->setStripeCustomerId('cus_live_' . uniqid());
        $restaurant->setStripeSubscriptionId('sub_live');
        $this->em->flush();
    }

    private function checkoutToken(Crawler $crawler): string
    {
        return $crawler->filter('form[action="/admin/billing/checkout"] input[name="_token"]')->first()->attr('value');
    }

    public function testOwnerInTrialSeesChoosePlanSidebarLinkAndMobileIcons(): void
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/menu');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.trial-choose', 'Elegir plan');
        self::assertSelectorExists('.sidebar-footer a.nav-item[href="/admin/billing"]');
        self::assertSelectorTextContains('.bottom-nav a.bottom-nav-tab[href="/admin/billing"]', 'Plan');
        self::assertSelectorNotExists('.header-icons a[href="/admin/billing"]');
        self::assertSelectorTextSame('body > a.billing-strip[href="/admin/billing"] .billing-strip-text', $crawler->filter('.trial-badge')->text());
        self::assertSelectorTextSame('.billing-strip:not(.is-renewal) .billing-strip-action', 'Elegir plan →');
        self::assertSelectorExists('.header-icons a[aria-label="Cerrar sesión"]');
        self::assertCount(2, $crawler->filter('.billing-dialog form[action="/admin/billing/checkout"]'));
        self::assertStringContainsString('Mensual · 39,99 €/mes', $crawler->filter('.billing-dialog')->text());
        self::assertStringContainsString('Anual · 399,99 €/año', $crawler->filter('.billing-dialog')->text());
        self::assertCount(7, $crawler->filter('#billing-modal .plan-included li'), 'the home\'s 6 + AI photos');
        self::assertStringContainsString('Camarero con IA para tus clientes', $crawler->filter('#billing-modal .plan-included')->text());
        self::assertStringContainsString('Fotos de tus platos con IA', $crawler->filter('#billing-modal .plan-included')->text());
        self::assertStringNotContainsString('ilimitad', $crawler->filter('#billing-modal .plan-included')->text(), 'no limit or "unlimited" in the plan list');
    }

    public function testChoosePlanDialogAndExpiredPageShowPricesInEnglish(): void
    {
        $this->managed()->setAdminLocale('en');
        $this->em->flush();
        $this->client->loginUser($this->owner);

        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertStringContainsString('Monthly · €39.99/month', $crawler->filter('.billing-dialog')->text());
        self::assertStringContainsString('Yearly · €399.99/year', $crawler->filter('.billing-dialog')->text());
        self::assertStringContainsString('AI photos of your dishes', $crawler->filter('#billing-modal .plan-included')->text());
        self::assertStringNotContainsString('Unlimited', $crawler->filter('#billing-modal .plan-included')->text());

        $this->expire();
        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $crawler->selectButton('Monthly · €39.99/month'));
        self::assertCount(1, $crawler->selectButton('Yearly · €399.99/year'));
    }

    public function testExpiredPageShowsPlatformPrices(): void
    {
        $this->expire();
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/menu');

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $crawler->selectButton('Mensual · 39,99 €/mes'));
        self::assertCount(1, $crawler->selectButton('Anual · 399,99 €/año'));
    }

    public function testOwnerWithLiveSubscriptionGetsScreenLinkAndNoDialog(): void
    {
        $this->linkSubscription();

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/menu');

        self::assertSelectorNotExists('.billing-dialog');
        self::assertCount(2, $crawler->filter('.sidebar-footer a[href="/admin/billing"], .bottom-nav a[href="/admin/billing"]'));
        self::assertCount(0, $crawler->filter('form[action="/admin/billing/portal"]'));
    }

    public function testStaffSeesNoSubscriptionButLogoutIcon(): void
    {
        $this->client->loginUser($this->staff);
        $this->client->request('GET', '/admin/menu');

        self::assertSelectorNotExists('.trial-choose');
        self::assertSelectorNotExists('.billing-dialog');
        self::assertSelectorNotExists('.header-icons [aria-label="Suscripción"]');
        self::assertSelectorExists('.header-icons a[aria-label="Cerrar sesión"]');
    }

    public function testCheckoutRedirectsToStripe(): void
    {
        $this->client->loginUser($this->owner);
        $token = $this->checkoutToken($this->client->request('GET', '/admin/menu'));

        $this->client->request('POST', '/admin/billing/checkout', ['_token' => $token, 'plan' => 'yearly']);

        self::assertResponseStatusCodeSame(303);
        self::assertStringStartsWith('https://checkout.stripe.test/', $this->client->getResponse()->headers->get('Location'));
        self::assertSame('price_test_yearly', FakeStripeGateway::$checkoutParams[0]['line_items'][0]['price']);
        self::assertStringContainsString('/admin/billing/return?session_id={CHECKOUT_SESSION_ID}', FakeStripeGateway::$checkoutParams[0]['success_url']);
    }

    public function testExpiredOwnerCanPayFromExpiredPage(): void
    {
        $this->expire();

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertResponseStatusCodeSame(403);
        self::assertCount(2, $crawler->filter('.plans form[action="/admin/billing/checkout"]'));

        $this->client->submit($crawler->selectButton('Mensual · 39,99 €/mes')->form());

        self::assertResponseStatusCodeSame(303);
        self::assertArrayNotHasKey('trial_end', FakeStripeGateway::$checkoutParams[0]['subscription_data']);
    }

    public function testExpiredStaffIsAskedToTellTheOwner(): void
    {
        $this->expire();

        $this->client->loginUser($this->staff);
        $crawler = $this->client->request('GET', '/admin/menu');

        self::assertSelectorTextSame('.hint', 'Pide al propietario que renueve la suscripción');
        self::assertCount(0, $crawler->filter('form[action^="/admin/billing"]'));
    }

    public function testStaffCannotStartCheckout(): void
    {
        $this->client->loginUser($this->staff);
        $this->client->request('POST', '/admin/billing/checkout', ['plan' => 'monthly']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], FakeStripeGateway::$checkoutParams);
    }

    public function testCheckoutWithoutValidCsrfIsRejected(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request('POST', '/admin/billing/checkout', ['_token' => 'bogus', 'plan' => 'monthly']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], FakeStripeGateway::$checkoutParams);
    }

    public function testLiveSubscriptionNeverGetsASecondCheckout(): void
    {
        $this->client->loginUser($this->owner);
        $token = $this->checkoutToken($this->client->request('GET', '/admin/menu'));
        $this->linkSubscription();

        $this->client->request('POST', '/admin/billing/checkout', ['_token' => $token, 'plan' => 'monthly']);

        self::assertResponseRedirects('/admin/billing');
        self::assertSame([], FakeStripeGateway::$checkoutParams);
    }

    public function testStripeFailureShowsErrorInsteadOf500(): void
    {
        $this->client->loginUser($this->owner);
        $token = $this->checkoutToken($this->client->request('GET', '/admin/menu'));
        FakeStripeGateway::$fail = true;

        $this->client->request('POST', '/admin/billing/checkout', ['_token' => $token, 'plan' => 'monthly']);
        self::assertResponseRedirects('/admin/menu');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'No se pudo conectar con Stripe');
    }

    public function testReturnFromCheckoutSyncsRightAway(): void
    {
        $id = $this->restaurant->getId();
        FakeStripeGateway::subscription('sub_ret', $id, 'price_test_monthly', 'cus_ret');
        FakeStripeGateway::$objects['cs_ret'] = ['id' => 'cs_ret', 'mode' => 'subscription', 'subscription' => 'sub_ret', 'client_reference_id' => (string) $id];

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/billing/return?session_id=cs_ret');

        self::assertResponseRedirects('/admin/menu');
        $restaurant = $this->managed();
        self::assertSame('sub_ret', $restaurant->getStripeSubscriptionId());
        self::assertSame(Restaurant::PLAN_MONTHLY, $restaurant->getPlan());
    }


    public function testReturnFromPortalSyncsCancelRightAway(): void
    {
        $this->linkSubscription();
        FakeStripeGateway::subscription('sub_live', $this->restaurant->getId(), 'price_test_monthly');
        FakeStripeGateway::$objects['sub_live']['cancel_at_period_end'] = true;

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/billing/portal-return');

        self::assertResponseRedirects('/admin/billing');
        self::assertTrue($this->managed()->isStripeCancelPending());
    }

    // ── Payment link (/admin/billing/pay/{id}, copied from /platform) ──────

    private function payPath(): string
    {
        return '/admin/billing/pay/' . $this->restaurant->getId();
    }

    public function testPayLinkOpensPlanDialogForOwner(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request('GET', $this->payPath());

        self::assertResponseRedirects('/admin/menu?billing=choose');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.billing-dialog');
    }

    public function testPayLinkSendsAnonymousToLoginAndBackAfterIt(): void
    {
        $this->client->request('GET', $this->payPath());
        self::assertResponseRedirects();
        self::assertStringContainsString('/admin/login', $this->client->getResponse()->headers->get('Location'));

        $this->owner->setPassword(static::getContainer()->get('security.user_password_hasher')->hashPassword($this->owner, 'secret-pass'));
        $this->em->flush();

        $crawler = $this->client->followRedirect();
        $form = $crawler->filter('form.login-form')->form(['email' => $this->owner->getEmail(), 'password' => 'secret-pass']);
        $this->client->submit($form);

        self::assertResponseRedirects('http://localhost' . $this->payPath());
        $this->client->followRedirect();
        self::assertResponseRedirects('/admin/menu?billing=choose');
    }

    public function testPayLinkGoesToScreenWhenAlreadySubscribed(): void
    {
        $this->linkSubscription();

        $this->client->loginUser($this->owner);
        $this->client->request('GET', $this->payPath());

        self::assertResponseRedirects('/admin/billing');
    }

    public function testPayLinkForExpiredOwnerShowsExpiredPageWithPlans(): void
    {
        $this->expire();

        $this->client->loginUser($this->owner);
        $this->client->request('GET', $this->payPath());
        self::assertResponseRedirects('/admin/menu?billing=choose');

        $crawler = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(403);
        self::assertCount(2, $crawler->filter('form[action="/admin/billing/checkout"]'));
    }

    public function testPayLinkTellsStaffToAskTheOwner(): void
    {
        $this->client->loginUser($this->staff);
        $this->client->request('GET', $this->payPath());

        self::assertResponseRedirects('/admin/menu');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-warning', 'Pide al propietario que renueve la suscripción');
        self::assertSelectorNotExists('.billing-dialog');
    }

    public function testPayLinkForExpiredStaffShowsExpiredHintOnly(): void
    {
        $this->expire();

        $this->client->loginUser($this->staff);
        $this->client->request('GET', $this->payPath());
        $this->client->followRedirect();

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextSame('.hint', 'Pide al propietario que renueve la suscripción');
    }

    public function testPayLinkOfAnotherRestaurantDoesNothing(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/billing/pay/' . ($this->restaurant->getId() + 100000));

        self::assertResponseRedirects('/admin/menu');
        self::assertSame([], FakeStripeGateway::$portalCustomers);
        self::assertSame([], FakeStripeGateway::$checkoutParams);
    }

    private function superAdmin(): User
    {
        $super = new User();
        $super->setEmail('billing-test-super-' . uniqid() . '@example.test');
        $super->setPassword('unused-in-tests');
        $super->setRoles([User::ROLE_SUPER_ADMIN]);
        $this->em->persist($super);
        $this->em->flush();
        $this->extraUsers[] = $super->getId();

        return $super;
    }

    /** The super-admin opening the link: switched to the owner, then the owner's own flow. */
    private function openPayLinkAsSuperAdmin(): Crawler
    {
        $this->client->loginUser($this->superAdmin());
        $this->client->request('GET', $this->payPath());

        self::assertResponseRedirects($this->payPath() . '?_switch_user=' . $this->owner->getEmail());
        $this->client->followRedirect();
        self::assertResponseRedirects('http://localhost' . $this->payPath());
        $this->client->followRedirect();

        return $this->client->getCrawler();
    }

    public function testSuperAdminSeesPlanDialogAsTheOwner(): void
    {
        $this->openPayLinkAsSuperAdmin();
        self::assertResponseRedirects('/admin/menu?billing=choose');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.billing-dialog');
        self::assertSelectorNotExists('.impersonation-bar');
        // Logout leaves impersonation instead: sidebar (desktop) and header icon (mobile).
        self::assertSelectorTextSame('.sidebar-footer a.nav-item[href="/platform?_switch_user=_exit"]', 'Volver a Plataforma');
        self::assertSelectorExists('.header-icons a[href="/platform?_switch_user=_exit"][aria-label="Volver a Plataforma"]');
        self::assertSelectorNotExists('a[href="/admin/logout"]');
    }

    public function testSuperAdminAsOwnerIsNotSentToPlatformFromAdmin(): void
    {
        $this->openPayLinkAsSuperAdmin();

        $this->client->request('GET', '/admin/menu');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/platform?_switch_user=_exit"]');
    }

    public function testSuperAdminAsOwnerGetsScreenWhenSubscribed(): void
    {
        $this->linkSubscription();
        $this->openPayLinkAsSuperAdmin();

        self::assertResponseRedirects('/admin/billing');
    }

    public function testOwnerSeesExpiredPageWithLogout(): void
    {
        $this->expire();
        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/menu');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextSame('a.logout[href="/admin/logout"]', 'Cerrar sesión');
        self::assertSelectorNotExists('a[href="/platform?_switch_user=_exit"]');
    }

    public function testSuperAdminAsOwnerOfExpiredRestaurantGetsThePanel(): void
    {
        // "Como" the owner the super-admin can set things up while it's
        // expired (SubscriptionGate::preparingAsOwner()); the owner can't.
        $this->expire();
        $this->openPayLinkAsSuperAdmin();

        self::assertResponseRedirects('/admin/menu?billing=choose');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.billing-dialog');
        self::assertSelectorNotExists('.impersonation-bar');
        self::assertSelectorTextSame('.sidebar-footer a.nav-item[href="/platform?_switch_user=_exit"]', 'Volver a Plataforma');
        self::assertSelectorNotExists('a[href="/admin/logout"]');
    }

    public function testVolverAPlataformaLeavesImpersonation(): void
    {
        $this->openPayLinkAsSuperAdmin();

        $this->client->request('GET', '/platform?_switch_user=_exit');
        self::assertResponseRedirects('http://localhost/platform');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        // Back to being the super-admin: /admin sends to /platform again.
        $this->client->request('GET', '/admin/menu');
        self::assertResponseRedirects('/platform');
    }

    private function tokenRoles(): array
    {
        return static::getContainer()->get('security.token_storage')->getToken()?->getRoleNames() ?? [];
    }

    public function testPreviousAdminMarkerOnlyOnSessionTokenAndGoneAfterExit(): void
    {
        $this->openPayLinkAsSuperAdmin();
        self::assertContains('ROLE_PREVIOUS_ADMIN', $this->tokenRoles());

        $this->em->clear();
        self::assertSame([User::ROLE_OWNER, 'ROLE_USER'], $this->em->find(User::class, $this->owner->getId())->getRoles());

        $this->client->request('GET', '/platform?_switch_user=_exit');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertNotContains('ROLE_PREVIOUS_ADMIN', $this->tokenRoles());
        self::assertContains(User::ROLE_SUPER_ADMIN, $this->tokenRoles());
    }

    public function testSuperAdminPayLinkWithoutOwnerGoesToPlatform(): void
    {
        $this->client->loginUser($this->superAdmin());
        $this->client->request('GET', '/admin/billing/pay/' . ($this->restaurant->getId() + 100000));

        self::assertResponseRedirects('/platform');
    }

    public function testSuperAdminAsOwnerOpeningOwnerlessLinkLeavesAndGoesToPlatform(): void
    {
        $this->openPayLinkAsSuperAdmin();

        $this->client->request('GET', '/admin/billing/pay/' . ($this->restaurant->getId() + 100000));
        self::assertResponseRedirects('/platform?_switch_user=_exit');
        $this->client->followRedirect();
        self::assertResponseRedirects('http://localhost/platform');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testOwnerCannotSwitchUser(): void
    {
        $super = $this->superAdmin();
        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/menu?_switch_user=' . urlencode($super->getEmail()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerDoesNotSeeImpersonationBar(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/menu');

        self::assertSelectorNotExists('a[href="/platform?_switch_user=_exit"]');
        self::assertSelectorTextSame('.sidebar-footer a.nav-item[href="/admin/logout"]', 'Cerrar sesión');
        self::assertSelectorExists('.header-icons a[href="/admin/logout"][aria-label="Cerrar sesión"]');
    }

    // ── Subscription screen (/admin/billing) ────────────────────────────────

    /**
     * A live Stripe subscription with a card, prices of 39,99 € / 399,99 €.
     * $status 'trialing' ends the trial on $periodEnd.
     */
    private function stripeSubscription(string $status = 'active', string $periodEnd = '+20 days'): void
    {
        $this->linkSubscription();
        FakeStripeGateway::price('price_test_monthly', 3999, 'month');
        FakeStripeGateway::price('price_test_yearly', 39999, 'year');
        FakeStripeGateway::subscription('sub_live', $this->restaurant->getId(), 'price_test_monthly');
        $end = (new \DateTimeImmutable('today ' . $periodEnd, new \DateTimeZone('UTC')))->getTimestamp();
        FakeStripeGateway::$objects['sub_live']['status'] = $status;
        FakeStripeGateway::$objects['sub_live']['trial_end'] = $status === 'trialing' ? $end : null;
        FakeStripeGateway::$objects['sub_live']['items']['data'][0]['current_period_start'] = time() - 86400;
        FakeStripeGateway::$objects['sub_live']['items']['data'][0]['current_period_end'] = $end;
        FakeStripeGateway::$objects['sub_live']['default_payment_method'] = FakeStripeGateway::card('pm_old', '4242', 1000);
    }

    private function screen(): Crawler
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/billing');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function post(string $path, array $params = []): void
    {
        $token = $this->screen()->filter('form[action="/admin/billing/cancel"] input[name="_token"], form[action="/admin/billing/resume"] input[name="_token"], form[action="/admin/billing/card"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', $path, ['_token' => $token] + $params);
        self::assertResponseRedirects('/admin/billing');
    }

    private static function day(string $relative): string
    {
        return (new \DateTimeImmutable('today ' . $relative, new \DateTimeZone('UTC')))->format('d/m/Y');
    }

    public function testScreenInTrialWithoutStripeShowsDaysLeftAndChoosePlan(): void
    {
        $crawler = $this->screen();

        self::assertStringStartsWith('Prueba gratis · quedan ', $crawler->filter('#billing-status')->text());
        self::assertCount(1, $crawler->filter('.billing-actions button[onclick="BillingUI.open()"]'));
        self::assertCount(2, $crawler->filter('.billing-dialog form[action="/admin/billing/checkout"]'));
        self::assertSelectorNotExists('.invoice-list');
        self::assertSame([], FakeStripeGateway::$calls);
    }

    public function testScreenWithManualPaymentShowsActiveUntil(): void
    {
        $restaurant = $this->managed();
        $restaurant->markPaid(Restaurant::PLAN_MONTHLY);
        $this->em->flush();

        self::assertSame('Activa hasta el ' . $restaurant->getPaidUntil()->format('d/m/Y'), $this->screen()->filter('#billing-status')->text());
    }

    public function testExpiredOwnerReachesScreenWithChoosePlan(): void
    {
        $this->expire();

        $crawler = $this->screen();
        self::assertSame('Vencida', $crawler->filter('#billing-status')->text());
        self::assertCount(1, $crawler->filter('.billing-actions button[onclick="BillingUI.open()"]'));
    }

    public function testStaffCannotSeeScreen(): void
    {
        $this->client->loginUser($this->staff);
        $this->client->request('GET', '/admin/billing');

        self::assertResponseStatusCodeSame(403);
    }

    public function testScreenWithStripeShowsPlanNextChargeCardAndInvoices(): void
    {
        $this->stripeSubscription();
        FakeStripeGateway::$invoices = [
            ['created' => strtotime('2026-10-01 10:00 UTC'), 'total' => 3999, 'currency' => 'eur', 'status' => 'paid', 'hosted_invoice_url' => 'https://invoice.stripe.test/1', 'invoice_pdf' => null],
            ['created' => strtotime('2026-10-02 10:00 UTC'), 'total' => 3999, 'currency' => 'eur', 'status' => 'draft', 'hosted_invoice_url' => null, 'invoice_pdf' => null],
        ];

        $crawler = $this->screen();

        self::assertSame('Mensual · 39,99 €/mes', $crawler->filter('#billing-plan')->text());
        self::assertSame('Activa', $crawler->filter('#billing-status')->text());
        self::assertSame('39,99 € el ' . self::day('+20 days'), $crawler->filter('#billing-next-charge')->text());
        self::assertSame('Visa •••• 4242 · caduca 04/30', $crawler->filter('#billing-card')->text());
        self::assertSame('Pasar a anual', $crawler->filter('form[action="/admin/billing/plan"] button')->text());
        self::assertStringContainsString('al terminar el periodo actual, el ' . self::day('+20 days') . '. Ese día se cobrarán 399,99 €.', $crawler->filter('form[action="/admin/billing/plan"]')->attr('data-confirm'));
        self::assertStringContainsString('Seguirá activa hasta el ' . $this->managed()->getPaidUntil()->format('d/m/Y') . ' y no se renovará.', $crawler->filter('form[action="/admin/billing/cancel"]')->attr('data-confirm'));
        self::assertCount(1, $crawler->filter('.invoice-list li'), 'drafts left out');
        self::assertStringContainsString('01/10/2026', $crawler->filter('.invoice-list li')->text());
        self::assertStringContainsString('Pagada', $crawler->filter('.invoice-list li')->text());
        self::assertSame('https://invoice.stripe.test/1', $crawler->filter('.invoice-list a')->attr('href'));
        self::assertSelectorNotExists('.billing-dialog');
    }

    /** Still on the old 30 € price: the screen knows the plan and offers the current yearly price. */
    public function testScreenWithSubscriptionOnOldPrice(): void
    {
        $this->stripeSubscription();
        FakeStripeGateway::price('price_old_monthly', 3000, 'month');
        FakeStripeGateway::$objects['sub_live']['items']['data'][0]['price'] = FakeStripeGateway::$objects['price_old_monthly'];

        $crawler = $this->screen();

        self::assertSame('Mensual · 30,00 €/mes', $crawler->filter('#billing-plan')->text());
        self::assertSame('30,00 € el ' . self::day('+20 days'), $crawler->filter('#billing-next-charge')->text());
        self::assertSame('Pasar a anual', $crawler->filter('form[action="/admin/billing/plan"] button')->text());
        self::assertStringContainsString('Ese día se cobrarán 399,99 €.', $crawler->filter('form[action="/admin/billing/plan"]')->attr('data-confirm'));
    }

    public function testInvoiceFailureDoesNotBreakScreen(): void
    {
        $this->stripeSubscription();
        FakeStripeGateway::$failInvoices = true;

        $crawler = $this->screen();

        self::assertSame('No se pudieron cargar las facturas.', $crawler->filter('#invoices-error')->text());
        self::assertSame('Activa', $crawler->filter('#billing-status')->text());
    }

    public function testStripeDownStillShowsLocalStatus(): void
    {
        $this->stripeSubscription();
        FakeStripeGateway::$fail = true;
        FakeStripeGateway::$failInvoices = true;

        $crawler = $this->screen();

        self::assertCount(1, $crawler->filter('.billing-notice .ti-alert-triangle'));
        self::assertSelectorExists('#billing-status');
        self::assertSame('No se pudieron cargar las facturas.', $crawler->filter('#invoices-error')->text());
    }

    public function testChangePlanInTrialIsImmediateWithoutCharge(): void
    {
        $this->stripeSubscription('trialing', '+20 days');
        $crawler = $this->screen();
        self::assertStringContainsString('ahora, sin cargo. El primer cobro será de 399,99 € el ' . self::day('+20 days'), $crawler->filter('form[action="/admin/billing/plan"]')->attr('data-confirm'));

        $this->post('/admin/billing/plan', ['plan' => 'yearly']);

        [$method, $id, $params] = FakeStripeGateway::$calls[0];
        self::assertSame(['updateSubscription', 'sub_live'], [$method, $id]);
        self::assertSame([['id' => 'si_sub_live', 'price' => 'price_test_yearly']], $params['items']);
        self::assertSame('none', $params['proration_behavior']);
        self::assertSame(FakeStripeGateway::$objects['sub_live']['trial_end'], $params['trial_end']);
        self::assertSame(Restaurant::PLAN_YEARLY, $this->managed()->getPlan());
        self::assertSame('Anual · 399,99 €/año', $this->screen()->filter('#billing-plan')->text());
    }

    public function testChangePlanWithPaidPeriodIsScheduledAtPeriodEndAndCanBeUndone(): void
    {
        $this->stripeSubscription('active', '+20 days');
        $this->managed()->setPlan(Restaurant::PLAN_MONTHLY);
        $this->em->flush();

        $this->post('/admin/billing/plan', ['plan' => 'yearly']);

        self::assertSame('createSubscriptionSchedule', FakeStripeGateway::$calls[0][0]);
        [, , $params] = FakeStripeGateway::$calls[1];
        self::assertSame('release', $params['end_behavior']);
        self::assertSame('none', $params['proration_behavior']);
        self::assertSame('price_test_monthly', $params['phases'][0]['items'][0]['price']);
        self::assertSame(FakeStripeGateway::$objects['sub_live']['items']['data'][0]['current_period_end'], $params['phases'][0]['end_date']);
        self::assertSame('price_test_yearly', $params['phases'][1]['items'][0]['price']);
        self::assertSame(['interval' => 'year', 'interval_count' => 1], $params['phases'][1]['duration']);
        self::assertSame(Restaurant::PLAN_MONTHLY, $this->managed()->getPlan(), 'still monthly until the period ends');

        $crawler = $this->screen();
        self::assertSame('Pasará al plan anual el ' . self::day('+20 days') . ' (399,99 €).', $crawler->filter('#billing-pending-change')->text());
        self::assertSame('399,99 € el ' . self::day('+20 days'), $crawler->filter('#billing-next-charge')->text());
        self::assertSelectorNotExists('form[action="/admin/billing/plan"]');
        self::assertStringContainsString('Se anulará el cambio de plan programado.', $crawler->filter('form[action="/admin/billing/cancel"]')->attr('data-confirm'));

        $this->post('/admin/billing/plan/undo');

        self::assertSame('releaseSubscriptionSchedule', FakeStripeGateway::$calls[2][0]);
        self::assertSelectorNotExists('#billing-pending-change');
        self::assertCount(1, $this->screen()->filter('form[action="/admin/billing/plan"]'));
    }

    public function testCancelThenResume(): void
    {
        $this->stripeSubscription();

        $this->post('/admin/billing/cancel');

        self::assertSame(['updateSubscription', 'sub_live', ['cancel_at_period_end' => true]], FakeStripeGateway::$calls[0]);
        self::assertTrue($this->managed()->isStripeCancelPending());
        $crawler = $this->screen();
        self::assertSame('Activa hasta el ' . $this->managed()->getPaidUntil()->format('d/m/Y') . ', no se renovará.', $crawler->filter('#billing-cancel-notice')->text());
        self::assertSelectorNotExists('#billing-next-charge');
        self::assertSelectorNotExists('form[action="/admin/billing/plan"]');
        self::assertSelectorNotExists('form[action="/admin/billing/cancel"]');

        $this->post('/admin/billing/resume');

        self::assertSame(['updateSubscription', 'sub_live', ['cancel_at_period_end' => false]], FakeStripeGateway::$calls[1]);
        self::assertFalse($this->managed()->isStripeCancelPending());
        self::assertSelectorNotExists('#billing-cancel-notice');
    }

    public function testCancelWithScheduledChangeReleasesScheduleFirst(): void
    {
        $this->stripeSubscription();
        $this->post('/admin/billing/plan', ['plan' => 'yearly']);
        $schedule = FakeStripeGateway::$objects['sub_live']['schedule'];

        $this->post('/admin/billing/cancel');

        self::assertSame(['releaseSubscriptionSchedule', $schedule, []], FakeStripeGateway::$calls[2]);
        self::assertSame(['updateSubscription', 'sub_live', ['cancel_at_period_end' => true]], FakeStripeGateway::$calls[3]);
    }

    public function testResumeClearsCancelAtDateToo(): void
    {
        $this->stripeSubscription();
        FakeStripeGateway::$objects['sub_live']['cancel_at'] = time() + 86400 * 10;
        $this->managed()->setStripeCancelPending(true);
        $this->em->flush();

        $this->post('/admin/billing/resume');

        self::assertSame(['updateSubscription', 'sub_live', ['cancel_at' => '']], FakeStripeGateway::$calls[0]);
        self::assertFalse($this->managed()->isStripeCancelPending());
    }

    public function testChangePlanRefusedWhileCancelled(): void
    {
        $this->stripeSubscription();
        FakeStripeGateway::$objects['sub_live']['cancel_at_period_end'] = true;

        $this->post('/admin/billing/plan', ['plan' => 'yearly']);

        self::assertSame([], FakeStripeGateway::$calls);
    }

    public function testActionsNeedCsrf(): void
    {
        $this->stripeSubscription();
        $this->client->loginUser($this->owner);

        foreach (['/admin/billing/plan', '/admin/billing/plan/undo', '/admin/billing/cancel', '/admin/billing/resume', '/admin/billing/card'] as $path) {
            $this->client->request('POST', $path, ['_token' => 'wrong', 'plan' => 'yearly']);
            self::assertResponseStatusCodeSame(403, $path);
        }
        self::assertSame([], FakeStripeGateway::$calls);
        self::assertSame([], FakeStripeGateway::$portalCustomers);
    }

    public function testStaffCannotManage(): void
    {
        $this->stripeSubscription();
        $token = $this->screen()->filter('form[action="/admin/billing/cancel"] input[name="_token"]')->attr('value');
        $this->client->loginUser($this->staff);

        $this->client->request('POST', '/admin/billing/cancel', ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], FakeStripeGateway::$calls);
    }

    public function testChangeCardOpensPortalCardFormAndComesBackHere(): void
    {
        $this->stripeSubscription();

        $token = $this->screen()->filter('form[action="/admin/billing/card"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/billing/card', ['_token' => $token]);

        self::assertResponseStatusCodeSame(303);
        self::assertStringStartsWith('https://billing.stripe.test/portal/', $this->client->getResponse()->headers->get('Location'));
        self::assertSame(['http://localhost/admin/billing/portal-return'], FakeStripeGateway::$portalReturnUrls);
        self::assertSame([
            'type' => 'payment_method_update',
            'after_completion' => ['type' => 'redirect', 'redirect' => ['return_url' => 'http://localhost/admin/billing/portal-return']],
        ], FakeStripeGateway::$portalFlows[0]);
    }

    public function testPortalReturnMakesNewerCustomerCardTheSubscriptions(): void
    {
        $this->stripeSubscription();
        $customer = FakeStripeGateway::$objects['sub_live']['customer'];
        FakeStripeGateway::$objects[$customer] = ['id' => $customer, 'invoice_settings' => ['default_payment_method' => FakeStripeGateway::card('pm_new', '1881', 2000, 'mastercard')]];

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/billing/portal-return');

        self::assertResponseRedirects('/admin/billing');
        self::assertSame(['updateSubscription', 'sub_live', ['default_payment_method' => 'pm_new']], FakeStripeGateway::$calls[0]);
        self::assertSame('Mastercard •••• 1881 · caduca 04/30', $this->screen()->filter('#billing-card')->text());
    }

    public function testPortalReturnKeepsSubscriptionCardWhenCustomerCardIsOlder(): void
    {
        $this->stripeSubscription();
        $customer = FakeStripeGateway::$objects['sub_live']['customer'];
        FakeStripeGateway::$objects[$customer] = ['id' => $customer, 'invoice_settings' => ['default_payment_method' => FakeStripeGateway::card('pm_ancient', '0005', 500)]];

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/billing/portal-return');

        self::assertSame([], FakeStripeGateway::$calls);
        self::assertSame('Visa •••• 4242 · caduca 04/30', $this->screen()->filter('#billing-card')->text());
    }

    public function testCardShownFallsBackToCustomerDefault(): void
    {
        $this->stripeSubscription();
        FakeStripeGateway::$objects['sub_live']['default_payment_method'] = null;
        $customer = FakeStripeGateway::$objects['sub_live']['customer'];
        FakeStripeGateway::$objects[$customer] = ['id' => $customer, 'invoice_settings' => ['default_payment_method' => FakeStripeGateway::card('pm_cust', '3220', 2000)]];

        self::assertSame('Visa •••• 3220 · caduca 04/30', $this->screen()->filter('#billing-card')->text());
    }

    public function testExpiredPageWithLiveSubscriptionKeepsPortalButton(): void
    {
        $this->stripeSubscription();
        $this->expire();

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/menu');
        $this->client->submit($crawler->filter('form[action="/admin/billing/portal"]')->form());

        self::assertResponseStatusCodeSame(303);
        self::assertSame([null], FakeStripeGateway::$portalFlows);
        self::assertSame(['http://localhost/admin/billing/portal-return'], FakeStripeGateway::$portalReturnUrls);
    }

    public function testSuperAdminAsOwnerSeesScreen(): void
    {
        $this->stripeSubscription();
        $this->openPayLinkAsSuperAdmin();
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSame('Activa', $this->client->getCrawler()->filter('#billing-status')->text());
    }

    // ── "Vence pronto" notice ───────────────────────────────────────────────

    private function paidUntil(string $relative, bool $live = false, bool $cancelPending = false): void
    {
        $restaurant = $this->managed();
        $restaurant->setPlan(Restaurant::PLAN_MONTHLY);
        (new \ReflectionProperty(Restaurant::class, 'paidUntil'))->setValue($restaurant, new \DateTimeImmutable('today ' . $relative));
        $restaurant->setStripeSubscriptionId($live ? 'sub_live' : null);
        $restaurant->setStripeCancelPending($cancelPending);
        $this->em->flush();
    }

    private function renewalNotice(User $user): ?string
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertResponseIsSuccessful();
        $badge = $crawler->filter('.renewal-badge');

        return $badge->count() > 0 ? $badge->text() : null;
    }

    #[DataProvider('renewalCases')]
    public function testRenewalNotice(string $until, bool $live, bool $cancelPending, ?string $expected): void
    {
        $this->paidUntil($until, $live, $cancelPending);

        self::assertSame($expected, $this->renewalNotice($this->owner));
        if ($expected !== null) {
            self::assertSelectorTextSame('.sidebar-header a.trial-choose[href="/admin/billing"]', 'Renovar');
            self::assertSelectorNotExists('.sidebar-header button.trial-choose');
        }
    }

    public static function renewalCases(): iterable
    {
        yield 'manual, 7 days' => ['+7 days', false, false, 'Tu suscripción vence en 7 días'];
        yield 'manual, 1 day' => ['+1 day', false, false, 'Tu suscripción vence en 1 día'];
        yield 'manual, last day' => ['+0 days', false, false, 'Tu suscripción vence hoy'];
        yield 'manual, 8 days' => ['+8 days', false, false, null];
        yield 'cancelled, 3 days' => ['+3 days', true, true, 'Tu suscripción termina en 3 días'];
        yield 'cancelled, last day' => ['+0 days', true, true, 'Tu suscripción termina hoy'];
        yield 'Stripe renews' => ['+3 days', true, false, null];
    }

    public function testStaffNeverSeesRenewalNotice(): void
    {
        $this->paidUntil('+3 days');

        self::assertNull($this->renewalNotice($this->staff));
    }

    public function testTrialNoticeUnchangedAndNeverBoth(): void
    {
        self::assertNull($this->renewalNotice($this->owner));
        self::assertSelectorTextSame('.trial-choose', 'Elegir plan');
        self::assertCount(1, $this->client->getCrawler()->filter('.trial-badge'));
    }

    public function testSuperAdminAsOwnerSeesRenewalNotice(): void
    {
        $this->paidUntil('+2 days');
        $this->openPayLinkAsSuperAdmin();
        $this->client->request('GET', '/admin/menu');

        self::assertSelectorTextSame('.renewal-badge', 'Tu suscripción vence en 2 días');
    }

    public function testMobileStripRepeatsEndingSoonNotice(): void
    {
        $this->paidUntil('+3 days');
        $this->renewalNotice($this->owner);

        self::assertSelectorTextSame('body > a.billing-strip.is-renewal[href="/admin/billing"] .billing-strip-text', 'Tu suscripción vence en 3 días');
        self::assertSelectorTextSame('.billing-strip .billing-strip-action', 'Renovar →');
        self::assertSelectorTextContains('.bottom-nav a.bottom-nav-tab[href="/admin/billing"]', 'Plan');
        self::assertSelectorNotExists('.header-icons a[href="/admin/billing"]');
    }

    public function testNoMobileStripWithoutNotice(): void
    {
        $this->paidUntil('+20 days');
        $this->renewalNotice($this->owner);

        self::assertSelectorNotExists('.billing-strip');
        self::assertSelectorTextContains('.bottom-nav a.bottom-nav-tab[href="/admin/billing"]', 'Plan');
        self::assertSelectorNotExists('.header-icons a[href="/admin/billing"]');
    }

    public function testStaffInTrialGetsNoMobileStrip(): void
    {
        $this->client->loginUser($this->staff);
        $this->client->request('GET', '/admin/menu');

        self::assertSelectorExists('.trial-badge');
        self::assertSelectorNotExists('.billing-strip');
    }

    public function testSuperAdminAsOwnerGetsMobileStripAtTopOfPage(): void
    {
        $this->paidUntil('+2 days');
        $this->openPayLinkAsSuperAdmin();
        $this->client->request('GET', '/admin/menu');

        self::assertSelectorTextSame('body > .billing-strip .billing-strip-text', 'Tu suscripción vence en 2 días');
        $html = $this->client->getResponse()->getContent();
        self::assertLessThan(strpos($html, 'class="admin-layout"'), strpos($html, 'class="billing-strip'));
    }

    /** The strip sits at the top of the page, before the layout, on every admin screen. */
    #[DataProvider('adminScreens')]
    public function testMobileStripIsAtTopOfPageOnEveryScreen(string $path): void
    {
        $this->paidUntil('+3 days');
        $this->managed()->setSetMenusEnabled(true);
        $this->em->flush();
        $this->openPayLinkAsSuperAdmin();
        $crawler = $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('body > a.billing-strip[href="/admin/billing"] .billing-strip-text', 'Tu suscripción vence en 3 días');
        self::assertCount(1, $crawler->filter('.billing-strip'));
        self::assertCount(0, $crawler->filter('.admin-layout .billing-strip'));
        self::assertSelectorExists('.header-icons a[aria-label="Volver a Plataforma"]');
    }

    public static function adminScreens(): iterable
    {
        foreach (['/admin/menu', '/admin/menus', '/admin/theme', '/admin/estadisticas', '/admin/qr', '/admin/usuarios', '/admin/settings', '/admin/billing', '/admin/menu/import'] as $path) {
            yield $path => [$path];
        }
    }
}
