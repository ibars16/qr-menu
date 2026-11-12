<?php

namespace App\Tests\Controller;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Tests\Support\FakeStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A reseller's restaurant paying by card (Stripe Connect, step 2): once
 * its reseller has Stripe connected and a price, the owner gets the same
 * trial notice, "Elegir plan", Checkout and subscription screen as a
 * platform customer — in the reseller's account and at its price — and
 * the connected-account webhook keeps the restaurant up to date, never
 * touching another reseller's restaurants. Stripe is FakeStripeGateway.
 */
final class ResellerRestaurantCardTest extends WebTestCase
{
    private const ACCOUNT = 'acct_card_test';
    private const SECRET = 'whsec_fake_connect_webhook_for_tests';

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
        FakeStripeGateway::reset();

        $this->reseller = new Reseller('Card Test Agency');
        $this->reseller->markPaid(Reseller::PLAN_YEARLY);
        $this->reseller->setConnectAccountId(self::ACCOUNT . '_' . uniqid());
        $this->reseller->setConnectChargesEnabled(true);
        $this->reseller->setConnectProductId('prod_card');
        $this->reseller->setRestaurantPriceMonthly(2500, 'price_card_monthly');
        $this->reseller->setRestaurantPriceYearly(25000, 'price_card_yearly');
        $this->em->persist($this->reseller);

        $this->resellerUser = $this->user('card-test-reseller', User::ROLE_RESELLER);
        $this->resellerUser->setReseller($this->reseller);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Card Test Restaurant');
        $this->restaurant->setSlug('card-test-' . uniqid());
        $this->restaurant->setReseller($this->reseller);
        $this->em->persist($this->restaurant);

        $this->owner = $this->user('card-test-owner', User::ROLE_OWNER);
        $this->owner->setRestaurant($this->restaurant);
        $this->em->flush();

        FakeStripeGateway::price('price_card_monthly', 2500, 'month');
        FakeStripeGateway::price('price_card_yearly', 25000, 'year');
    }

    public function testSubscriptionScreenSaysAwaitingPaymentWhenNeverPaid(): void
    {
        $this->em->clear();
        $this->em->find(Restaurant::class, $this->restaurant->getId())->deactivateNow();
        $this->em->flush();

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/billing');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#billing-status', 'Pendiente de pago');
    }

    public function testNeverPaidChoosesAmongTheResellersPlans(): void
    {
        $this->em->clear();
        $this->em->find(Restaurant::class, $this->restaurant->getId())->deactivateNow();
        $this->em->flush();

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/menu');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Elige un plan para activar tu carta', $crawler->filter('#choose-plan')->text());
        self::assertSame(['monthly', 'yearly'], $crawler->filter('form[action="/admin/billing/checkout"] input[name="plan"]')->each(fn ($n) => $n->attr('value')));
        self::assertSame('Mensual · 25 €/mes Anual · 250 €/año', $crawler->filter('.plans')->text());
        self::assertStringNotContainsString('venció', $crawler->filter('body')->text());
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM "user" WHERE email LIKE \'card-test-%\'');
        $conn->executeStatement("DELETE FROM restaurant WHERE slug LIKE 'card-test-%'");
        $conn->executeStatement("DELETE FROM reseller WHERE name LIKE 'Card Test%'");

        parent::tearDown();
    }

    private function user(string $prefix, string $role): User
    {
        $user = new User();
        $user->setEmail($prefix . '-' . uniqid() . '@example.test');
        $user->setPassword('unused-in-tests');
        $user->setRoles([$role]);
        $this->em->persist($user);

        return $user;
    }

    private function reloaded(): Restaurant
    {
        $this->em->clear();

        return $this->em->find(Restaurant::class, $this->restaurant->getId());
    }

    private function account(): string
    {
        return $this->reseller->getConnectAccountId();
    }

    /** The restaurant already paying in the reseller's account. */
    private function subscribed(string $priceId = 'price_card_monthly', string $status = 'active'): void
    {
        $restaurant = $this->reloaded();
        $restaurant->setStripeAccountId($this->account());
        $restaurant->setStripeCustomerId('cus_card_' . uniqid());
        $restaurant->setStripeSubscriptionId('sub_card');
        $restaurant->setPlan(Restaurant::PLAN_MONTHLY);
        $this->em->flush();
        FakeStripeGateway::subscription('sub_card', $restaurant->getId(), $priceId, $restaurant->getStripeCustomerId());
        FakeStripeGateway::$objects['sub_card']['status'] = $status;
        FakeStripeGateway::$objects['sub_card']['items']['data'][0]['current_period_end'] = strtotime('+20 days');
    }

    private function postEvent(string $type, string $objectId, ?string $account, string $secret = self::SECRET): void
    {
        $payload = json_encode(array_filter([
            'id' => 'evt_' . uniqid(), 'object' => 'event', 'type' => $type, 'account' => $account,
            'data' => ['object' => ['id' => $objectId]],
        ]));
        $time = time();
        $signature = 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, $secret);

        $this->client->request('POST', '/stripe/connect-webhook', server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], content: $payload);
    }

    // ── Owner's panel ───────────────────────────────────────────────────────

    public function testOwnerGetsTrialNoticeAndResellerPlans(): void
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/menu');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sidebar-header .trial-badge', 'Prueba gratuita · quedan 30 días');
        self::assertSelectorExists('.sidebar-header button.trial-choose');
        self::assertSelectorNotExists('.reseller-notice');
        $labels = $crawler->filter('#billing-modal .plan-option span')->each(fn (Crawler $n) => $n->text());
        self::assertSame(['Mensual · 25 €/mes', 'Anual · 250 €/año'], $labels);
    }

    public function testOnlyMonthlyWhenResellerHasNoYearlyPrice(): void
    {
        $reseller = $this->em->find(Reseller::class, $this->reseller->getId());
        $reseller->setRestaurantPriceMonthly(2550, 'price_card_monthly');
        $reseller->setRestaurantPriceYearly(null, null);
        $this->em->flush();

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/menu');

        self::assertSame(['monthly'], $crawler->filter('#billing-modal input[name="plan"]')->each(fn (Crawler $n) => $n->attr('value')));
        self::assertSame('Mensual · 25,50 €/mes', $crawler->filter('#billing-modal .plan-option span')->text());
    }

    public function testNoCardPaymentWithoutStripeOrPrice(): void
    {
        foreach (['charges' => fn (Reseller $r) => $r->setConnectChargesEnabled(false), 'price' => fn (Reseller $r) => $r->setRestaurantPriceMonthly(null, null)] as $why => $undo) {
            $this->em->clear();
            $reseller = $this->em->find(Reseller::class, $this->reseller->getId());
            $undo($reseller);
            $this->em->flush();

            $this->client->loginUser($this->owner);
            $this->client->request('GET', '/admin/menu');
            self::assertSelectorNotExists('.billing-dialog', $why);
            self::assertSelectorNotExists('.trial-badge', $why);
            $this->client->request('GET', '/admin/billing');
            self::assertSelectorExists('#billing-reseller', $why);
        }
    }

    public function testCheckoutInResellerAccountWithItsPriceChargesNowInFreeTrial(): void
    {
        $this->client->loginUser($this->owner);
        $token = $this->client->request('GET', '/admin/menu')->filter('#billing-modal input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/billing/checkout', ['_token' => $token, 'plan' => 'yearly']);

        self::assertResponseRedirects('https://checkout.stripe.test/session/1', 303);
        $params = FakeStripeGateway::$checkoutParams[0];
        self::assertSame([['price' => 'price_card_yearly', 'quantity' => 1]], $params['line_items']);
        self::assertSame((string) $this->restaurant->getId(), $params['subscription_data']['metadata']['restaurant_id']);
        // Never paid: its free trial ends, charged today.
        self::assertArrayNotHasKey('trial_end', $params['subscription_data']);
        self::assertArrayNotHasKey('application_fee_percent', $params['subscription_data']);
        // Customer and session both in the reseller's account.
        self::assertSame([['createCustomer', $this->account()], ['createCheckoutSession', $this->account()]], FakeStripeGateway::$accountLog);
        $restaurant = $this->reloaded();
        self::assertSame($this->account(), $restaurant->getStripeAccountId());
        self::assertNotNull($restaurant->getStripeCustomerId());
    }

    public function testCheckoutKeepsDaysTheResellerRegisteredAsPaid(): void
    {
        $restaurant = $this->reloaded();
        $restaurant->markPaid(Restaurant::PLAN_MONTHLY);
        $this->em->flush();
        $expectedEnd = (new \DateTimeImmutable($restaurant->getPaidUntil()->format('Y-m-d') . ' 00:00:00', new \DateTimeZone('UTC')))->modify('+1 day')->getTimestamp();

        $this->client->loginUser($this->owner);
        $token = $this->client->request('GET', '/admin/menu')->filter('#billing-modal input[name="_token"]')->attr('value');
        self::assertSelectorNotExists('.plan-charge-now');
        $this->client->request('POST', '/admin/billing/checkout', ['_token' => $token, 'plan' => 'yearly']);

        self::assertSame($expectedEnd, FakeStripeGateway::$checkoutParams[0]['subscription_data']['trial_end']);
    }

    public function testCheckoutRefusesPlanResellerDoesNotOffer(): void
    {
        $reseller = $this->em->find(Reseller::class, $this->reseller->getId());
        $reseller->setRestaurantPriceYearly(null, null);
        $this->em->flush();
        $this->client->loginUser($this->owner);
        $token = $this->client->request('GET', '/admin/menu')->filter('#billing-modal input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/billing/checkout', ['_token' => $token, 'plan' => 'yearly']);

        self::assertResponseRedirects('/admin/menu');
        self::assertSame([], FakeStripeGateway::$checkoutParams);
    }

    public function testOwnReturnFromCheckoutLinksSubscription(): void
    {
        $restaurant = $this->reloaded();
        $restaurant->setStripeAccountId($this->account());
        $restaurant->setStripeCustomerId('cus_card_return');
        $this->em->flush();
        FakeStripeGateway::subscription('sub_card', $restaurant->getId(), 'price_card_yearly', 'cus_card_return');
        FakeStripeGateway::$objects['cs_card'] = ['id' => 'cs_card', 'mode' => 'subscription', 'subscription' => 'sub_card', 'client_reference_id' => (string) $restaurant->getId(), 'invoice' => null];

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/billing/return?session_id=cs_card');

        $restaurant = $this->reloaded();
        self::assertSame('sub_card', $restaurant->getStripeSubscriptionId());
        self::assertSame('yearly', $restaurant->getPlan());
        self::assertSame(['retrieveCheckoutSession', $this->account()], FakeStripeGateway::$accountLog[0]);
    }

    public function testFullSubscriptionScreenWithPlanChangeAndManagedBy(): void
    {
        $this->subscribed();

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/billing');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#billing-plan', 'Mensual · 25,00 €/mes');
        self::assertSelectorTextSame('#billing-status', 'Activa');
        self::assertCount(1, $crawler->filter('form[action="/admin/billing/plan"] input[name="plan"][value="yearly"]'));
        self::assertCount(1, $crawler->filter('form[action="/admin/billing/cancel"]'));
        self::assertCount(1, $crawler->filter('form[action="/admin/billing/card"]'));
        self::assertStringStartsWith('Tu carta la gestiona Card Test Agency · card-test-reseller-', $crawler->filter('#billing-managed-by')->text());
        self::assertSelectorNotExists('#billing-reseller');
        // Every Stripe read in the reseller's account.
        self::assertSame([$this->account()], array_values(array_unique(array_column(FakeStripeGateway::$accountLog, 1))));
    }

    public function testNoPlanChangeWithoutTheOtherPrice(): void
    {
        $this->subscribed();
        $reseller = $this->em->find(Reseller::class, $this->reseller->getId());
        $reseller->setRestaurantPriceYearly(null, null);
        $this->em->flush();

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/billing');

        self::assertCount(0, $crawler->filter('form[action="/admin/billing/plan"]'));
    }

    public function testCancelInResellerAccount(): void
    {
        $this->subscribed();
        $this->client->loginUser($this->owner);
        $token = $this->client->request('GET', '/admin/billing')->filter('form[action="/admin/billing/cancel"] input[name="_token"]')->attr('value');
        FakeStripeGateway::$accountLog = [];

        $this->client->request('POST', '/admin/billing/cancel', ['_token' => $token]);

        self::assertResponseRedirects('/admin/billing');
        self::assertTrue($this->reloaded()->isStripeCancelPending());
        self::assertSame([['updateSubscription', 'sub_card', ['cancel_at_period_end' => true]]], FakeStripeGateway::$calls);
        self::assertSame([$this->account()], array_values(array_unique(array_column(FakeStripeGateway::$accountLog, 1))));
    }

    public function testCardChangeUsesResellerAccountPortal(): void
    {
        $this->subscribed();
        $this->client->loginUser($this->owner);
        $token = $this->client->request('GET', '/admin/billing')->filter('form[action="/admin/billing/card"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/billing/card', ['_token' => $token]);

        self::assertResponseRedirects();
        self::assertContains(['createPortalSession', $this->account()], FakeStripeGateway::$accountLog);
    }

    public function testExpiredOwnDatePaysWithResellerPlans(): void
    {
        $restaurant = $this->reloaded();
        $restaurant->deactivateNow();
        $this->em->flush();

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/menu');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['Mensual · 25 €/mes', 'Anual · 250 €/año'], $crawler->filter('form[action="/admin/billing/checkout"] button')->each(fn (Crawler $n) => $n->text()));
    }

    public function testExpiredResellerStillOnlyContact(): void
    {
        $reseller = $this->em->find(Reseller::class, $this->reseller->getId());
        $reseller->deactivateNow();
        $this->em->flush();

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/menu');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextSame('.hint', 'Contacta con Card Test Agency.');
        self::assertSelectorNotExists('form[action="/admin/billing/checkout"]');
    }

    public function testPaymentLinkOpensPlanDialog(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/admin/billing/pay/' . $this->restaurant->getId());

        self::assertResponseRedirects('/admin/menu?billing=choose');
    }

    // ── /partners ───────────────────────────────────────────────────────────

    public function testPartnersShowsPaymentLinkAndAutoPay(): void
    {
        $this->client->loginUser($this->resellerUser);
        $crawler = $this->client->request('GET', '/partners');
        $row = $crawler->filter('.active-list tr')->first();
        self::assertSame('http://localhost/admin/billing/pay/' . $this->restaurant->getId(), $row->filter('button.copy-link')->attr('data-url'));
        self::assertSame('Copiar enlace de pago', $row->filter('button.copy-link')->text());
        self::assertCount(0, $row->filter('.auto-pay'));

        $this->subscribed();
        $row = $this->client->request('GET', '/partners')->filter('.active-list tr')->first();
        self::assertCount(0, $row->filter('button.copy-link'));
        self::assertSame('💳 Pago automático', $row->filter('.auto-pay')->text());

        $restaurant = $this->reloaded();
        $restaurant->setStripeCancelPending(true);
        $this->em->flush();
        $row = $this->client->request('GET', '/partners')->filter('.active-list tr')->first();
        self::assertSame('💳 Cancelada · no renovará', $row->filter('.auto-pay')->text());
    }

    public function testPartnersNoPaymentLinkWithoutPrice(): void
    {
        $reseller = $this->em->find(Reseller::class, $this->reseller->getId());
        $reseller->setRestaurantPriceMonthly(null, null);
        $this->em->flush();

        $this->client->loginUser($this->resellerUser);
        $this->client->request('GET', '/partners');

        self::assertSelectorNotExists('button.copy-link');
    }

    // ── Connected-account webhook (/stripe/connect-webhook) ────────────────

    public function testPaidInvoiceFromResellerAccountExtendsPaidUntil(): void
    {
        $this->subscribed();
        $periodEnd = strtotime('+40 days');
        FakeStripeGateway::invoice('in_card', 'sub_card', $periodEnd, 2500, time());

        $this->postEvent('invoice.paid', 'in_card', $this->account());

        self::assertResponseIsSuccessful();
        $restaurant = $this->reloaded();
        self::assertSame(gmdate('Y-m-d', $periodEnd), $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertSame('monthly', $restaurant->getPlan());
        self::assertSame(gmdate('Y-m-d'), $restaurant->getLastPaidAt()?->format('Y-m-d'));
        self::assertSame(['retrieveInvoice', $this->account()], FakeStripeGateway::$accountLog[0]);
    }

    public function testCheckoutCompletedFromResellerAccount(): void
    {
        $restaurant = $this->reloaded();
        $restaurant->setStripeAccountId($this->account());
        $restaurant->setStripeCustomerId('cus_card_hook');
        $this->em->flush();
        FakeStripeGateway::subscription('sub_card', $restaurant->getId(), 'price_card_yearly', 'cus_card_hook');
        FakeStripeGateway::$objects['cs_hook'] = ['id' => 'cs_hook', 'mode' => 'subscription', 'subscription' => 'sub_card', 'client_reference_id' => (string) $restaurant->getId(), 'invoice' => null];

        $this->postEvent('checkout.session.completed', 'cs_hook', $this->account());

        $restaurant = $this->reloaded();
        self::assertSame('sub_card', $restaurant->getStripeSubscriptionId());
        self::assertSame('yearly', $restaurant->getPlan());
    }

    public function testSubscriptionUpdatedAndDeleted(): void
    {
        $this->subscribed();
        FakeStripeGateway::$objects['sub_card']['cancel_at_period_end'] = true;

        $this->postEvent('customer.subscription.updated', 'sub_card', $this->account());
        self::assertTrue($this->reloaded()->isStripeCancelPending());

        $this->postEvent('customer.subscription.deleted', 'sub_card', $this->account());
        $restaurant = $this->reloaded();
        self::assertNull($restaurant->getStripeSubscriptionId());
        self::assertFalse($restaurant->isStripeCancelPending());
    }

    public function testEventFromAnotherAccountChangesNothing(): void
    {
        $this->subscribed();
        $before = $this->reloaded()->getPaidUntil();
        FakeStripeGateway::invoice('in_card', 'sub_card', strtotime('+400 days'), 2500, time());

        // Another reseller's account, or the platform webhook's lack of one.
        $this->postEvent('invoice.paid', 'in_card', 'acct_someone_else');
        self::assertResponseIsSuccessful();
        FakeStripeGateway::$objects['sub_card']['cancel_at_period_end'] = true;
        $this->postEvent('customer.subscription.updated', 'sub_card', 'acct_someone_else');
        $this->postEvent('customer.subscription.deleted', 'sub_card', 'acct_someone_else');

        $restaurant = $this->reloaded();
        self::assertEquals($before, $restaurant->getPaidUntil());
        self::assertSame('sub_card', $restaurant->getStripeSubscriptionId());
        self::assertFalse($restaurant->isStripeCancelPending());
    }

    public function testPlatformWebhookIgnoresResellerRestaurant(): void
    {
        $this->subscribed();
        $before = $this->reloaded()->getPaidUntil();
        FakeStripeGateway::invoice('in_card', 'sub_card', strtotime('+400 days'), 2500, time());
        $payload = json_encode(['id' => 'evt_' . uniqid(), 'object' => 'event', 'type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_card']]]);
        $time = time();
        $signature = 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, 'whsec_fake_for_tests');

        $this->client->request('POST', '/stripe/webhook', server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], content: $payload);

        self::assertResponseIsSuccessful();
        self::assertEquals($before, $this->reloaded()->getPaidUntil());
    }

    public function testConnectWebhookBadSignatureOrNoAccount(): void
    {
        $this->subscribed();
        FakeStripeGateway::invoice('in_card', 'sub_card', strtotime('+40 days'), 2500, time());
        $before = $this->reloaded()->getPaidUntil();

        $this->postEvent('invoice.paid', 'in_card', $this->account(), 'whsec_wrong');
        self::assertResponseStatusCodeSame(400);

        $this->postEvent('invoice.paid', 'in_card', null);
        self::assertResponseIsSuccessful();

        self::assertEquals($before, $this->reloaded()->getPaidUntil());
    }

    // ── Desactivar / Archivar a restaurant paying by card ───────────────────

    private function partnersForm(string $action): \Symfony\Component\DomCrawler\Crawler
    {
        $this->client->loginUser($this->resellerUser);
        $crawler = $this->client->request('GET', '/partners');
        self::assertResponseIsSuccessful();

        return $crawler->filter('form[action="/partners/restaurantes/' . $this->restaurant->getId() . '/' . $action . '"]');
    }

    public function testDeactivateByCardCancelsInResellerAccountAndLateEventsChangeNothing(): void
    {
        $this->subscribed();
        $form = $this->partnersForm('desactivar');
        self::assertStringContainsString('Este restaurante paga con tarjeta. Se cancelará su suscripción y no se le volverá a cobrar.', self::confirmText($form));

        $this->client->submit($form->form());

        self::assertResponseRedirects('/partners');
        self::assertContains(['cancelSubscription', 'sub_card', []], FakeStripeGateway::$calls);
        self::assertContains(['cancelSubscription', $this->account()], FakeStripeGateway::$accountLog);
        $restaurant = $this->reloaded();
        self::assertNull($restaurant->getStripeSubscriptionId());
        self::assertTrue($restaurant->isExpired());

        // A late invoice.paid and checkout.session.completed of it: still off, still unlinked.
        FakeStripeGateway::invoice('in_late', 'sub_card', strtotime('+1 month'), 2500, time());
        $this->postEvent('invoice.paid', 'in_late', $this->account());
        FakeStripeGateway::$objects['cs_late'] = ['id' => 'cs_late', 'mode' => 'subscription', 'subscription' => 'sub_card', 'client_reference_id' => (string) $this->restaurant->getId(), 'invoice' => 'in_late'];
        $this->postEvent('checkout.session.completed', 'cs_late', $this->account());

        $restaurant = $this->reloaded();
        self::assertNull($restaurant->getStripeSubscriptionId());
        self::assertTrue($restaurant->isExpired());
        self::assertSame((new \DateTimeImmutable('yesterday'))->format('Y-m-d'), $restaurant->getPaidUntil()->format('Y-m-d'));
    }

    public function testArchiveByCardCancelsAndRestoredOwnerChoosesAPlan(): void
    {
        $this->subscribed();
        $form = $this->partnersForm('archivar');
        self::assertStringContainsString('¿Archivar Card Test Restaurant? Este restaurante paga con tarjeta.', self::confirmText($form));

        $this->client->submit($form->form());

        self::assertContains(['cancelSubscription', 'sub_card', []], FakeStripeGateway::$calls);
        $restaurant = $this->reloaded();
        self::assertTrue($restaurant->isArchived());
        self::assertNull($restaurant->getStripeSubscriptionId());
        self::assertTrue($restaurant->isOwnPeriodExpired(), 'off for good: restoring it means choosing a plan');

        $this->client->submit($this->partnersForm('restaurar')->form());
        self::assertFalse($this->reloaded()->isArchived());

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/admin/menu');
        self::assertResponseStatusCodeSame(403);
        self::assertCount(2, $crawler->filter('form[action="/admin/billing/checkout"] input[name="plan"]'));
    }

    public function testStripeFailureLeavesTheRestaurantOn(): void
    {
        $this->subscribed();
        $form = $this->partnersForm('desactivar');
        $before = $this->reloaded()->getPaidUntil()->format('Y-m-d');
        FakeStripeGateway::$fail = true;

        $this->client->submit($form->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'No se pudo cancelar la suscripción de Card Test Restaurant en Stripe');
        $restaurant = $this->reloaded();
        self::assertSame('sub_card', $restaurant->getStripeSubscriptionId());
        self::assertSame($before, $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertFalse($restaurant->isExpired());
    }

    public function testWithoutCardNothingGoesToStripe(): void
    {
        $form = $this->partnersForm('desactivar');
        self::assertStringNotContainsString('tarjeta', self::confirmText($form));
        self::assertStringNotContainsString('tarjeta', self::confirmText($this->client->getCrawler()->filter('form[action="/partners/restaurantes/' . $this->restaurant->getId() . '/archivar"]')));
        FakeStripeGateway::$accountLog = [];

        $this->client->submit($form->form());

        self::assertTrue($this->reloaded()->isExpired());
        self::assertSame([], FakeStripeGateway::$accountLog);
    }

    /** The text of a form's onsubmit="return confirm(…)" (JSON-escaped in the page). */
    private static function confirmText(\Symfony\Component\DomCrawler\Crawler $form): string
    {
        return json_decode(substr($form->attr('onsubmit'), strlen('return confirm('), -1));
    }
}
