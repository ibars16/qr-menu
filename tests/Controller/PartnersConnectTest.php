<?php

namespace App\Tests\Controller;

use App\Entity\Reseller;
use App\Entity\User;
use App\Tests\Support\FakeStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The reseller's own Stripe (Stripe Connect, Accounts v2) from /partners:
 * "Conectar Stripe" creates the account once (country and currency from
 * config) and sends it to Stripe's onboarding, the way back reads whether
 * it can take card payments, account events keep that up to date, and its
 * price for its restaurants becomes a product and prices in ITS account.
 * Stripe is FakeStripeGateway.
 */
final class PartnersConnectTest extends WebTestCase
{
    private const EVENTS_SECRET = 'whsec_fake_connect_account_events_for_tests';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Reseller $reseller;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        FakeStripeGateway::reset();

        $this->reseller = new Reseller('Connect Test Agency');
        $this->reseller->markPaid(Reseller::PLAN_MONTHLY);
        $this->em->persist($this->reseller);

        $this->user = new User();
        $this->user->setEmail('connect-test-' . uniqid() . '@example.test');
        $this->user->setPassword('unused-in-tests');
        $this->user->setRoles([User::ROLE_RESELLER]);
        $this->user->setReseller($this->reseller);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->remove($this->em->find(User::class, $this->user->getId()));
        $this->em->flush();
        $this->em->remove($this->em->find(Reseller::class, $this->reseller->getId()));
        $this->em->flush();

        parent::tearDown();
    }

    private function reloaded(): Reseller
    {
        $this->em->clear();

        return $this->em->find(Reseller::class, $this->reseller->getId());
    }

    private function token(string $id): string
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/partners');
        self::assertResponseIsSuccessful();

        return $crawler->filter('form[action="' . $id . '"] input[name="_token"]')->attr('value');
    }

    private function connect(): void
    {
        $this->client->request('POST', '/partners/stripe/conectar', ['_token' => $this->token('/partners/stripe/conectar')]);
    }

    private function connected(bool $ready): Reseller
    {
        $reseller = $this->reloaded();
        $reseller->setConnectAccountId('acct_test_' . uniqid());
        $reseller->setConnectChargesEnabled($ready);
        $this->em->flush();
        FakeStripeGateway::connectedAccount($reseller->getConnectAccountId(), $ready);

        return $reseller;
    }

    private function postPrices(string $monthly, string $yearly = ''): void
    {
        $this->client->request('POST', '/partners/stripe/precio', ['_token' => $this->token('/partners/stripe/precio'), 'monthly' => $monthly, 'yearly' => $yearly]);
    }

    /** @param array<string, mixed> $relatedObject */
    private function postEvent(string $type, array $relatedObject, string $secret = self::EVENTS_SECRET): void
    {
        // A thin event notification, as Stripe sends it to the event destination.
        $payload = json_encode([
            'id' => 'evt_test_' . uniqid(), 'object' => 'v2.core.event', 'type' => $type,
            'livemode' => false, 'created' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'related_object' => $relatedObject,
        ]);
        $time = time();
        $signature = 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, $secret);

        $this->client->request('POST', '/stripe/connect-account-events', server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], content: $payload);
    }

    // ── Panel ───────────────────────────────────────────────────────────────

    public function testNotConnectedShowsConnectButton(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/partners');

        self::assertSelectorTextContains('#connect', 'Conecta tu Stripe para cobrar con tarjeta');
        self::assertSelectorTextSame('#connect-start', 'Conectar Stripe');
        self::assertSelectorNotExists('#connect-prices');
    }

    public function testExpiredResellerCannotConnect(): void
    {
        $token = $this->token('/partners/stripe/conectar');
        $reseller = $this->reloaded();
        $reseller->deactivateNow();
        $this->em->flush();

        $this->client->request('GET', '/partners');
        self::assertNotNull($this->client->getCrawler()->filter('#connect-start')->attr('disabled'));

        $this->client->request('POST', '/partners/stripe/conectar', ['_token' => $token]);
        self::assertResponseRedirects('/partners');
        self::assertSame([], FakeStripeGateway::$accountParams);
    }

    // ── Connecting ──────────────────────────────────────────────────────────

    public function testConnectCreatesAccountOnceAndGoesToOnboarding(): void
    {
        $this->connect();

        self::assertResponseRedirects('https://connect.stripe.test/setup/acct_fake_1/1', 303);
        self::assertSame([[
            'contact_email' => $this->user->getEmail(),
            'display_name'  => 'Connect Test Agency',
            'dashboard'     => 'full',
            'identity'      => ['country' => 'es'],
            'defaults'      => [
                'currency'         => 'eur',
                'responsibilities' => ['fees_collector' => 'stripe', 'losses_collector' => 'stripe'],
            ],
            'configuration' => ['merchant' => ['capabilities' => ['card_payments' => ['requested' => true]]]],
            'metadata'      => ['reseller_id' => (string) $this->reseller->getId()],
        ]], FakeStripeGateway::$accountParams);
        self::assertSame([['acct_fake_1', 'http://localhost/partners/stripe/vuelta', 'http://localhost/partners/stripe/reintentar']], FakeStripeGateway::$accountLinks);
        $reseller = $this->reloaded();
        self::assertSame('acct_fake_1', $reseller->getConnectAccountId());
        self::assertFalse($reseller->isConnectReady());

        // Unfinished: "Continuar alta" reuses the same account.
        $this->client->request('GET', '/partners');
        self::assertSelectorTextSame('#connect-start', 'Continuar alta en Stripe');
        $this->connect();
        self::assertCount(1, FakeStripeGateway::$accountParams);
        self::assertSame('acct_fake_1', FakeStripeGateway::$accountLinks[1][0]);

        // Stripe's refresh_url: a new link for the same account.
        $this->client->request('GET', '/partners/stripe/reintentar');
        self::assertResponseRedirects('https://connect.stripe.test/setup/acct_fake_1/3', 303);
    }

    public function testReturnUnfinishedSaysSo(): void
    {
        $this->connected(false);
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/partners/stripe/vuelta');

        self::assertResponseRedirects('/partners#connect');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'aún no está completa');
        self::assertSelectorTextSame('.connect-status', 'Alta en Stripe a medias');
    }

    public function testReturnFinishedShowsConnected(): void
    {
        $reseller = $this->connected(false);
        FakeStripeGateway::connectedAccount($reseller->getConnectAccountId(), true);
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/partners/stripe/vuelta');

        self::assertTrue($this->reloaded()->isConnectReady());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Stripe conectado');
        self::assertSelectorTextSame('.connect-status', '✓ Stripe conectado');
        self::assertSelectorNotExists('#connect-start');
        self::assertSelectorExists('#connect-prices');
    }

    public function testConnectStripeFailureShowsError(): void
    {
        $token = $this->token('/partners/stripe/conectar');
        FakeStripeGateway::$fail = true;

        $this->client->request('POST', '/partners/stripe/conectar', ['_token' => $token]);

        self::assertResponseRedirects('/partners#connect');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'No se ha podido conectar con Stripe');
        self::assertNull($this->reloaded()->getConnectAccountId());
    }

    // ── Account events (/stripe/connect-account-events) ────────────────────

    public function testAccountEventUpdatesStatus(): void
    {
        $reseller = $this->connected(false);
        FakeStripeGateway::connectedAccount($reseller->getConnectAccountId(), true);

        $this->postEvent('v2.core.account[configuration.merchant].capability_status_updated', ['id' => $reseller->getConnectAccountId(), 'type' => 'v2.core.account', 'url' => '/v2/core/accounts/' . $reseller->getConnectAccountId()]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->reloaded()->isConnectReady());

        // And back, e.g. Stripe needs more information.
        FakeStripeGateway::connectedAccount($reseller->getConnectAccountId(), false);
        $this->postEvent('v2.core.account[requirements].updated', ['id' => $reseller->getConnectAccountId(), 'type' => 'v2.core.account', 'url' => '/v2/core/accounts/' . $reseller->getConnectAccountId()]);
        self::assertFalse($this->reloaded()->isConnectReady());
    }

    public function testAccountEventWithBadSignatureIsRejected(): void
    {
        $reseller = $this->connected(false);
        FakeStripeGateway::connectedAccount($reseller->getConnectAccountId(), true);

        $this->postEvent('v2.core.account[requirements].updated', ['id' => $reseller->getConnectAccountId(), 'type' => 'v2.core.account'], 'whsec_wrong');

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->reloaded()->isConnectReady());
    }

    public function testUnknownAccountOrOtherEventIsIgnored(): void
    {
        $this->postEvent('v2.core.account[requirements].updated', ['id' => 'acct_nobody', 'type' => 'v2.core.account']);
        self::assertResponseIsSuccessful();

        $this->postEvent('v1.billing.meter.error_report_triggered', ['id' => 'mtr_1', 'type' => 'billing.meter']);
        self::assertResponseIsSuccessful();
    }

    // ── Price for its restaurants ───────────────────────────────────────────

    public function testSetPricesCreatesProductAndPricesInItsAccount(): void
    {
        $account = $this->connected(true)->getConnectAccountId();

        $this->postPrices('25', '250,50');

        self::assertResponseRedirects('/partners#connect');
        $calls = FakeStripeGateway::$connectCalls;
        self::assertSame(['createProduct', 'createPrice', 'createPrice'], array_column($calls, 0));
        self::assertSame([$account, $account, $account], array_column($calls, 1));
        self::assertSame('Carta digital QR', $calls[0][2]['name']);
        self::assertSame(['product' => 'prod_fake_1', 'unit_amount' => 2500, 'currency' => 'eur', 'recurring' => ['interval' => 'month']], array_intersect_key($calls[1][2], array_flip(['product', 'unit_amount', 'currency', 'recurring'])));
        self::assertSame(['unit_amount' => 25050, 'recurring' => ['interval' => 'year']], array_intersect_key($calls[2][2], array_flip(['unit_amount', 'recurring'])));

        $reseller = $this->reloaded();
        self::assertSame('prod_fake_1', $reseller->getConnectProductId());
        self::assertSame(2500, $reseller->getRestaurantPriceMonthly());
        self::assertSame('price_fake_2', $reseller->getConnectPriceMonthlyId());
        self::assertSame(25050, $reseller->getRestaurantPriceYearly());
        self::assertSame('price_fake_3', $reseller->getConnectPriceYearlyId());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Precio guardado');
        self::assertSame('25,00', $this->client->getCrawler()->filter('#price-monthly')->attr('value'));
        self::assertSame('250,50', $this->client->getCrawler()->filter('#price-yearly')->attr('value'));
    }

    public function testChangingPriceMakesNewPriceAndDeactivatesOld(): void
    {
        $this->connected(true);
        $this->postPrices('25', '250');
        FakeStripeGateway::$connectCalls = [];

        // Monthly changes, yearly unchanged: only a new monthly price.
        $this->postPrices('30', '250');
        self::assertSame(['createPrice', 'deactivatePrice'], array_column(FakeStripeGateway::$connectCalls, 0));
        self::assertSame(3000, FakeStripeGateway::$connectCalls[0][2]['unit_amount']);
        self::assertSame(['price' => 'price_fake_2'], FakeStripeGateway::$connectCalls[1][2]);
        $reseller = $this->reloaded();
        self::assertSame(3000, $reseller->getRestaurantPriceMonthly());
        self::assertSame('price_fake_3', $reseller->getConnectPriceYearlyId());

        // No yearly plan any more: its price deactivated, none created.
        FakeStripeGateway::$connectCalls = [];
        $this->postPrices('30', '');
        self::assertSame([['deactivatePrice', $reseller->getConnectAccountId(), ['price' => 'price_fake_3']]], FakeStripeGateway::$connectCalls);
        $reseller = $this->reloaded();
        self::assertNull($reseller->getRestaurantPriceYearly());
        self::assertNull($reseller->getConnectPriceYearlyId());
    }

    public function testInvalidPriceChangesNothing(): void
    {
        $this->connected(true);

        foreach (['', '0,50', 'abc', '10000', '25,555'] as $monthly) {
            $this->postPrices($monthly);
            self::assertResponseRedirects('/partners#connect');
        }
        $this->postPrices('25', 'x');

        self::assertSame([], FakeStripeGateway::$connectCalls);
        self::assertNull($this->reloaded()->getRestaurantPriceMonthly());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Escribe un precio entre 1 y 9999');
    }

    public function testCannotSetPricesWithoutAccount(): void
    {
        // The token from a moment when it had one; then no account.
        $this->connected(true);
        $token = $this->token('/partners/stripe/precio');
        $reseller = $this->reloaded();
        $reseller->setConnectAccountId(null);
        $this->em->flush();

        $this->client->request('GET', '/partners');
        self::assertSelectorNotExists('#connect-prices');
        $this->client->request('POST', '/partners/stripe/precio', ['_token' => $token, 'monthly' => '25']);

        self::assertResponseRedirects('/partners');
        self::assertSame([], FakeStripeGateway::$connectCalls);
    }
}
