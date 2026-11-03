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
 * The reseller's Stripe subscription: "Elegir plan" while expired, the
 * checkout, /partners/billing (the owner's screen with the extra line and
 * no plan change), the extra line kept to the count on create / archive /
 * restore, the webhook and the mark in /platform.
 */
final class PartnersBillingTest extends WebTestCase
{
    private const SECRET = 'whsec_fake_for_tests';
    private const BASE_MONTHLY = 'price_test_reseller_monthly';
    private const EXTRA_MONTHLY = 'price_test_reseller_extra_monthly';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Reseller $reseller;
    private User $resellerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        FakeStripeGateway::reset();

        $this->reseller = new Reseller('Partners Billing Test Agency');
        $this->reseller->markPaid(Reseller::PLAN_MONTHLY);
        $this->em->persist($this->reseller);

        $this->resellerUser = $this->user('partners-billing-test-reseller', User::ROLE_RESELLER);
        $this->resellerUser->setReseller($this->reseller);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM "user" WHERE email LIKE \'partners-billing-test-%\'');
        foreach ($this->em->getRepository(Restaurant::class)->createQueryBuilder('r')->where('r.slug LIKE :p')->setParameter('p', 'partners-billing-test-%')->getQuery()->getResult() as $restaurant) {
            $this->em->remove($restaurant);
        }
        $this->em->flush();
        $conn->executeStatement("DELETE FROM reseller WHERE name LIKE 'Partners Billing Test%'");

        parent::tearDown();
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

    /** @return list<Restaurant> */
    private function restaurants(int $count, int $archived = 0): array
    {
        $created = [];
        for ($i = 0; $i < $count + $archived; $i++) {
            $restaurant = new Restaurant();
            $restaurant->setName('Partners Billing Test ' . $i);
            $restaurant->setSlug('partners-billing-test-' . uniqid());
            $restaurant->setReseller($this->em->find(Reseller::class, $this->reseller->getId()));
            if ($i >= $count) {
                $restaurant->archive();
            }
            $this->em->persist($restaurant);
            $created[] = $restaurant;
        }
        $this->em->flush();

        return $created;
    }

    private function reloaded(): Reseller
    {
        $this->em->clear();

        return $this->em->find(Reseller::class, $this->reseller->getId());
    }

    private function expire(): void
    {
        $this->reloaded()->deactivateNow();
        $this->em->flush();
    }

    private function live(int $extra = 0): void
    {
        FakeStripeGateway::price(self::BASE_MONTHLY, 4000, 'month');
        FakeStripeGateway::price(self::EXTRA_MONTHLY, 600, 'month');
        FakeStripeGateway::resellerSubscription('sub_pb', $this->reseller->getId(), self::BASE_MONTHLY, self::EXTRA_MONTHLY, $extra, 'cus_pb');
        FakeStripeGateway::$objects['sub_pb']['items']['data'][0]['current_period_end'] = 1900000000;
        $reseller = $this->reloaded();
        $reseller->setStripeCustomerId('cus_pb');
        $reseller->setStripeSubscriptionId('sub_pb');
        $this->em->flush();
    }

    private function get(string $path): Crawler
    {
        $this->client->loginUser($this->resellerUser);
        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return list<array<string, mixed>> */
    private static function itemUpdates(): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call[2]['items'],
            array_filter(FakeStripeGateway::$calls, static fn (array $call): bool => $call[0] === 'updateSubscription' && isset($call[2]['items'])),
        ));
    }

    // ── Choosing a plan ──

    public function testExpiredPanelOffersPlanWithResellerPrices(): void
    {
        $this->expire();

        $crawler = $this->get('/partners');

        self::assertCount(1, $crawler->filter('#account-inactive #choose-plan'));
        $form = $crawler->filter('#billing-modal form');
        self::assertSame('/partners/billing/checkout', $form->attr('action'));

        $cards = $crawler->filter('#billing-modal .plan-option');
        self::assertSame(['monthly', 'yearly'], $cards->filter('input[name="plan"]')->each(fn (Crawler $i) => $i->attr('value')));
        $monthly = $cards->eq(0);
        self::assertSame('Mensual', $monthly->filter('.plan-name')->text());
        self::assertSame('40 €', $monthly->filter('.plan-amount')->text());
        self::assertSame('/mes', $monthly->filter('.plan-period')->text());
        self::assertSame(['5 restaurantes incluidos', '+6 €/mes por cada restaurante más'], $monthly->filter('.plan-feature')->each(fn (Crawler $f) => $f->text()));
        self::assertCount(0, $monthly->filter('.plan-badge'));

        $yearly = $cards->eq(1);
        self::assertSame('400 €', $yearly->filter('.plan-amount')->text());
        self::assertSame('/año', $yearly->filter('.plan-period')->text());
        self::assertSame('Ahorras 80 €', $yearly->filter('.plan-badge')->text());
        self::assertSame('+60 €/año por cada restaurante más', $yearly->filter('.plan-feature')->eq(1)->text());

        // No restaurants yet: nothing to estimate.
        self::assertCount(0, $cards->filter('.plan-estimate'));
        self::assertStringContainsString('Stripe', $crawler->filter('#billing-modal .plan-note')->text());
    }

    public function testPlanCardsShowWhatTheResellerWouldPayToday(): void
    {
        $this->expire();
        $this->restaurants(7, archived: 2);

        $cards = $this->get('/partners')->filter('#billing-modal .plan-option');

        self::assertSame('Con tus 7 restaurantes de ahora: 52 €/mes', $cards->eq(0)->filter('.plan-estimate')->text());
        self::assertSame('Con tus 7 restaurantes de ahora: 520 €/año', $cards->eq(1)->filter('.plan-estimate')->text());
    }

    public function testActivePanelHasNoPlanDialogButLinksSubscription(): void
    {
        $crawler = $this->get('/partners');

        self::assertCount(0, $crawler->filter('#choose-plan'));
        self::assertCount(0, $crawler->filter('#billing-modal'));
        self::assertSame('/partners/billing', $crawler->filter('#billing-link')->attr('href'));
    }

    public function testCheckoutGoesToStripeWithBaseAndExtraLines(): void
    {
        $this->expire();
        $this->restaurants(7, archived: 2);
        $crawler = $this->get('/partners');

        $this->client->request('POST', '/partners/billing/checkout', [
            '_token' => $crawler->filter('#billing-modal input[name="_token"]')->attr('value'),
            'plan'   => 'monthly',
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertStringStartsWith('https://checkout.stripe.test/', $this->client->getResponse()->headers->get('Location'));
        self::assertSame([
            ['price' => self::BASE_MONTHLY, 'quantity' => 1],
            ['price' => self::EXTRA_MONTHLY, 'quantity' => 2],
        ], FakeStripeGateway::$checkoutParams[0]['line_items']);
    }

    public function testCheckoutNeedsToken(): void
    {
        $this->client->loginUser($this->resellerUser);
        $this->client->request('POST', '/partners/billing/checkout', ['_token' => 'nope', 'plan' => 'monthly']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], FakeStripeGateway::$checkoutParams);
    }

    public function testOwnersCannotOpenResellerBilling(): void
    {
        $restaurant = $this->restaurants(1)[0];
        $owner = $this->user('partners-billing-test-owner', User::ROLE_OWNER, $restaurant);
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/partners/billing');

        self::assertResponseStatusCodeSame(403);
    }

    // ── Subscription screen ──

    public function testSubscriptionScreenShowsExtrasWithoutPlanChange(): void
    {
        $this->live(extra: 3);

        $crawler = $this->get('/partners/billing');

        self::assertStringContainsString('40,00 €', $crawler->filter('#billing-plan')->text());
        self::assertSame('3 extra × 6,00 €', $crawler->filter('#billing-extras')->text());
        self::assertStringContainsString('58,00 €', $crawler->filter('#billing-next-charge')->text());
        self::assertCount(1, $crawler->filter('form[action="/partners/billing/cancel"]'));
        self::assertCount(0, $crawler->filter('input[name="plan"]'));
    }

    public function testCancelAndResume(): void
    {
        $this->live();
        $crawler = $this->get('/partners/billing');

        $this->client->request('POST', '/partners/billing/cancel', ['_token' => $crawler->filter('form[action="/partners/billing/cancel"] input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects('/partners/billing');
        self::assertTrue($this->reloaded()->isStripeCancelPending());

        $crawler = $this->client->request('GET', '/partners/billing');
        self::assertCount(1, $crawler->filter('#billing-cancel-notice'));
        $this->client->request('POST', '/partners/billing/resume', ['_token' => $crawler->filter('form[action="/partners/billing/resume"] input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects('/partners/billing');
        self::assertFalse($this->reloaded()->isStripeCancelPending());
    }

    // ── Extra line kept to the count ──

    public function testCreateArchiveRestoreUpdateTheExtraLine(): void
    {
        $this->live();
        $restaurants = $this->restaurants(5);

        $crawler = $this->get('/partners');
        $this->client->request('POST', '/partners/restaurantes', [
            '_token'   => $crawler->filter('form.new-restaurant-form input[name="_token"]')->first()->attr('value'),
            'name'     => 'Partners Billing Test New',
            'email'    => 'partners-billing-test-new-' . uniqid() . '@example.test',
            'password' => 'a-strong-password-1',
        ]);
        self::assertResponseRedirects('/partners');
        // RestaurantCreator makes its own slug: give it ours for tearDown().
        $this->em->clear();
        $new = $this->em->getRepository(Restaurant::class)->findOneBy(['name' => 'Partners Billing Test New']);
        $new->setSlug('partners-billing-test-' . uniqid());
        $this->em->flush();
        self::assertSame([['price' => self::EXTRA_MONTHLY, 'quantity' => 1]], self::itemUpdates()[0]);

        $id = $restaurants[0]->getId();
        $crawler = $this->client->request('GET', '/partners');
        $this->client->request('POST', '/partners/restaurantes/' . $id . '/archivar', [
            '_token' => $crawler->filter('form[action="/partners/restaurantes/' . $id . '/archivar"] input[name="_token"]')->attr('value'),
        ]);
        self::assertSame([['id' => 'si_added_1', 'deleted' => true]], self::itemUpdates()[1]);

        $crawler = $this->client->request('GET', '/partners');
        $this->client->request('POST', '/partners/restaurantes/' . $id . '/restaurar', [
            '_token' => $crawler->filter('form[action="/partners/restaurantes/' . $id . '/restaurar"] input[name="_token"]')->attr('value'),
        ]);
        self::assertSame([['price' => self::EXTRA_MONTHLY, 'quantity' => 1]], self::itemUpdates()[2]);
    }

    public function testStripeFailureStillArchives(): void
    {
        $this->live(extra: 1);
        $restaurants = $this->restaurants(6);
        $id = $restaurants[0]->getId();
        $crawler = $this->get('/partners');

        FakeStripeGateway::$fail = true;
        $this->client->request('POST', '/partners/restaurantes/' . $id . '/archivar', [
            '_token' => $crawler->filter('form[action="/partners/restaurantes/' . $id . '/archivar"] input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseRedirects('/partners');
        $this->em->clear();
        self::assertTrue($this->em->find(Restaurant::class, $id)->isArchived());

        // The next change sets the count from scratch: 5 in use, no extra line.
        FakeStripeGateway::$fail = false;
        $crawler = $this->client->request('GET', '/partners');
        $this->client->request('POST', '/partners/restaurantes/' . $id . '/restaurar', [
            '_token' => $crawler->filter('form[action="/partners/restaurantes/' . $id . '/restaurar"] input[name="_token"]')->attr('value'),
        ]);
        self::assertSame([], self::itemUpdates(), 'back to 6 = 1 extra, as Stripe already has');
    }

    // ── Webhook and /platform ──

    public function testWebhookPaidInvoiceUpdatesReseller(): void
    {
        $this->expire();
        $this->live();
        $end = (new \DateTimeImmutable('2030-02-01 08:00', new \DateTimeZone('UTC')))->getTimestamp();
        FakeStripeGateway::invoice('in_pb', 'sub_pb', $end, 4000, time());

        $payload = json_encode(['id' => 'evt_' . uniqid(), 'object' => 'event', 'type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_pb']]]);
        $time = time();
        $this->client->request('POST', '/stripe/webhook', server: [
            'HTTP_STRIPE_SIGNATURE' => 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, self::SECRET),
            'CONTENT_TYPE'          => 'application/json',
        ], content: $payload);

        self::assertResponseIsSuccessful();
        $reseller = $this->reloaded();
        self::assertSame('2030-02-01', $reseller->getPaidUntil()->format('Y-m-d'));
        self::assertSame('monthly', $reseller->getPlan());
        self::assertNotNull($reseller->getLastPaidAt());
        self::assertFalse($reseller->isExpired());
    }

    public function testPlatformShowsResellerAutoPayMark(): void
    {
        $admin = $this->user('partners-billing-test-admin', User::ROLE_SUPER_ADMIN);
        $this->em->flush();
        $this->live();
        $this->client->loginUser($admin);

        $row = fn (): Crawler => $this->client->request('GET', '/platform')
            ->filter('#resellers tr')->reduce(fn (Crawler $tr) => str_contains($tr->text(), 'Partners Billing Test Agency'));

        self::assertStringContainsString('💳 Pago automático', $row()->text());

        $reseller = $this->reloaded();
        $reseller->setStripeCancelPending(true);
        $this->em->flush();
        self::assertStringContainsString('💳 Cancelada · no renovará', $row()->text());
    }

    public function testPlatformShowsNeverPaidResellerApartFromExpired(): void
    {
        $admin = $this->user('partners-billing-test-admin', User::ROLE_SUPER_ADMIN);
        $this->em->persist(new Reseller('Partners Billing Test Never Paid'));
        $this->em->flush();
        $this->expire();
        $this->client->loginUser($admin);

        $page = $this->client->request('GET', '/platform');
        $row = fn (string $name): Crawler => $page->filter('#resellers tbody tr')->reduce(fn (Crawler $tr) => $tr->filter('.r-name')->text() === $name);

        // Signed up, never chose a plan: no "Vencida", no date of its own.
        $never = $row('Partners Billing Test Never Paid');
        self::assertSame('⚪ Aún no ha pagado', $never->filter('.status')->text());
        self::assertSame('—', $never->filter('.until > div')->first()->text());
        self::assertStringNotContainsString('venció', $never->text());

        // Paid once and lapsed: still "Vencida".
        $lapsed = $row('Partners Billing Test Agency');
        self::assertSame('🔴 Vencida', $lapsed->filter('.status')->text());
        self::assertStringContainsString('venció hace', $lapsed->filter('.until')->text());
    }
}
