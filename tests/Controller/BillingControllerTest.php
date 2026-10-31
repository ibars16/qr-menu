<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Tests\Support\FakeStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * /admin/billing/*: owner only, CSRF-protected, reachable while expired;
 * never a second subscription (a live one goes to the portal instead). Plus
 * where the owner reaches it: trial badge, sidebar, mobile header, expired
 * page — and staff never sees it.
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
        self::assertSelectorExists('.sidebar-footer button.nav-item[onclick="BillingUI.open()"]');
        self::assertSelectorExists('.header-icons button[aria-label="Suscripción"]');
        self::assertSelectorExists('.header-icons a[aria-label="Cerrar sesión"]');
        self::assertCount(2, $crawler->filter('.billing-dialog form[action="/admin/billing/checkout"]'));
        self::assertStringContainsString('Mensual · 30 €/mes', $crawler->filter('.billing-dialog')->text());
        self::assertStringContainsString('Anual · 300 €/año', $crawler->filter('.billing-dialog')->text());
    }

    public function testOwnerWithLiveSubscriptionGetsPortalInsteadOfDialog(): void
    {
        $this->linkSubscription();

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/menu');

        self::assertSelectorNotExists('.billing-dialog');
        self::assertCount(2, $crawler->filter('form[action="/admin/billing/portal"]'));
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

        $this->client->submit($crawler->selectButton('Mensual · 30 €/mes')->form());

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

        self::assertResponseStatusCodeSame(303);
        self::assertStringStartsWith('https://billing.stripe.test/portal/', $this->client->getResponse()->headers->get('Location'));
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

    public function testPortalReturnsThroughSyncRoute(): void
    {
        $this->linkSubscription();

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/menu');
        $this->client->submit($crawler->filter('form[action="/admin/billing/portal"]')->first()->form());

        self::assertSame(['http://localhost/admin/billing/portal-return'], FakeStripeGateway::$portalReturnUrls);
    }

    public function testReturnFromPortalSyncsCancelRightAway(): void
    {
        $this->linkSubscription();
        FakeStripeGateway::subscription('sub_live', $this->restaurant->getId(), 'price_test_monthly');
        FakeStripeGateway::$objects['sub_live']['cancel_at_period_end'] = true;

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/billing/portal-return');

        self::assertResponseRedirects('/admin/menu');
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
        $form = $crawler->filter('form')->form(['email' => $this->owner->getEmail(), 'password' => 'secret-pass']);
        $this->client->submit($form);

        self::assertResponseRedirects('http://localhost' . $this->payPath());
        $this->client->followRedirect();
        self::assertResponseRedirects('/admin/menu?billing=choose');
    }

    public function testPayLinkGoesToPortalWhenAlreadySubscribed(): void
    {
        $this->linkSubscription();

        $this->client->loginUser($this->owner);
        $this->client->request('GET', $this->payPath());

        self::assertResponseStatusCodeSame(303);
        self::assertStringStartsWith('https://billing.stripe.test/portal/', $this->client->getResponse()->headers->get('Location'));
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
        self::assertSelectorTextContains('.impersonation-bar', 'Viendo como Billing Test Restaurant');
        self::assertSelectorExists('.impersonation-bar a[href="/platform?_switch_user=_exit"]');
    }

    public function testSuperAdminAsOwnerIsNotSentToPlatformFromAdmin(): void
    {
        $this->openPayLinkAsSuperAdmin();

        $this->client->request('GET', '/admin/menu');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.impersonation-bar');
    }

    public function testSuperAdminAsOwnerGetsPortalWhenSubscribed(): void
    {
        $this->linkSubscription();
        $this->openPayLinkAsSuperAdmin();

        self::assertResponseStatusCodeSame(303);
        self::assertStringStartsWith('https://billing.stripe.test/portal/', $this->client->getResponse()->headers->get('Location'));
    }

    public function testSuperAdminAsOwnerSeesExpiredPageWithBar(): void
    {
        $this->expire();
        $this->openPayLinkAsSuperAdmin();

        $crawler = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(403);
        self::assertCount(2, $crawler->filter('form[action="/admin/billing/checkout"]'));
        self::assertSelectorTextContains('.impersonation-bar', 'Viendo como Billing Test Restaurant');
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

        self::assertSelectorNotExists('.impersonation-bar');
    }
}
