<?php

namespace App\Tests\Service\Billing;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\Billing\StripeBilling;
use App\Tests\Support\FakeStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * StripeBilling for a reseller, against FakeStripeGateway: checkout with
 * the base line and, only when needed, the extra line (restaurants in use
 * and paid at least once over 5, archived and never-paid ones not counted,
 * no trial); the extra line kept to the
 * count; the webhook-side sync finding the reseller by reseller_id.
 */
final class ResellerBillingTest extends KernelTestCase
{
    private const BASE_MONTHLY = 'price_test_reseller_monthly';
    private const BASE_YEARLY = 'price_test_reseller_yearly';
    private const EXTRA_MONTHLY = 'price_test_reseller_extra_monthly';
    private const EXTRA_YEARLY = 'price_test_reseller_extra_yearly';

    private EntityManagerInterface $em;
    private StripeBilling $billing;
    private Reseller $reseller;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        FakeStripeGateway::reset();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->billing = static::getContainer()->get(StripeBilling::class);

        $this->reseller = new Reseller('Reseller Billing Test');
        $this->em->persist($this->reseller);

        $this->user = new User();
        $this->user->setEmail('reseller-billing-test-' . uniqid() . '@example.test');
        $this->user->setPassword('unused-in-tests');
        $this->user->setRoles([User::ROLE_RESELLER]);
        $this->user->setReseller($this->reseller);
        $this->em->persist($this->user);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        foreach ($this->em->getRepository(Restaurant::class)->findBy(['reseller' => $this->reseller->getId()]) as $restaurant) {
            $this->em->remove($restaurant);
        }
        $this->em->remove($this->em->find(User::class, $this->user->getId()));
        $this->em->flush();
        $conn->executeStatement('DELETE FROM reseller WHERE id = ?', [$this->reseller->getId()]);

        parent::tearDown();
    }

    /**
     * $count paid ones in use, then $archived (paid, archived), then
     * $pending in use but never paid ("Pendiente de pago").
     */
    private function restaurants(int $count, int $archived = 0, int $pending = 0): void
    {
        for ($i = 0; $i < $count + $archived + $pending; $i++) {
            $restaurant = new Restaurant();
            $restaurant->setName('Reseller Billing Test ' . $i);
            $restaurant->setSlug('reseller-billing-test-' . uniqid());
            $restaurant->setReseller($this->reseller);
            if ($i < $count + $archived) {
                $restaurant->markPaid('monthly');
            }
            if ($i >= $count && $i < $count + $archived) {
                $restaurant->archive();
            }
            $this->em->persist($restaurant);
        }
        $this->em->flush();
    }

    private function reloaded(): Reseller
    {
        $this->em->clear();

        return $this->em->find(Reseller::class, $this->reseller->getId());
    }

    private function live(string $basePrice = self::BASE_MONTHLY, string $extraPrice = self::EXTRA_MONTHLY, int $extra = 0): void
    {
        FakeStripeGateway::price($basePrice, $basePrice === self::BASE_YEARLY ? 40000 : 4000, $basePrice === self::BASE_YEARLY ? 'year' : 'month');
        FakeStripeGateway::price($extraPrice, $extraPrice === self::EXTRA_YEARLY ? 6000 : 600, $extraPrice === self::EXTRA_YEARLY ? 'year' : 'month');
        FakeStripeGateway::resellerSubscription('sub_r', $this->reseller->getId(), $basePrice, $extraPrice, $extra, 'cus_r');
        $this->reseller->setStripeCustomerId('cus_r');
        $this->reseller->setStripeSubscriptionId('sub_r');
        $this->em->flush();
    }

    /** @return list<array<string, mixed>> the item changes sent to Stripe */
    private static function itemUpdates(): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call[2],
            array_filter(FakeStripeGateway::$calls, static fn (array $call): bool => $call[0] === 'updateSubscription' && isset($call[2]['items'])),
        ));
    }

    // ── Checkout ──

    public function testCheckoutWithFiveOrFewerHasOnlyTheBaseLineAndNoTrial(): void
    {
        $this->restaurants(5, archived: 2);

        $url = $this->billing->startResellerCheckout($this->reseller, $this->user, 'monthly', 'https://ok', 'https://back');

        self::assertStringStartsWith('https://checkout.stripe.test/', $url);
        $params = FakeStripeGateway::$checkoutParams[0];
        self::assertSame([['price' => self::BASE_MONTHLY, 'quantity' => 1]], $params['line_items']);
        self::assertSame('subscription', $params['mode']);
        self::assertSame('reseller-' . $this->reseller->getId(), $params['client_reference_id']);
        self::assertSame(['reseller_id' => (string) $this->reseller->getId()], $params['subscription_data']['metadata']);
        self::assertArrayNotHasKey('trial_end', $params['subscription_data']);
        self::assertSame('cus_fake_1_' . $this->reseller->getId(), $this->reloaded()->getStripeCustomerId());
    }

    public function testCheckoutAddsExtraLineForRestaurantsOverFive(): void
    {
        $this->restaurants(8, archived: 3);

        $this->billing->startResellerCheckout($this->reseller, $this->user, 'yearly', 'https://ok', 'https://back');

        self::assertSame([
            ['price' => self::BASE_YEARLY, 'quantity' => 1],
            ['price' => self::EXTRA_YEARLY, 'quantity' => 3],
        ], FakeStripeGateway::$checkoutParams[0]['line_items']);
    }

    public function testRestaurantsAwaitingFirstPaymentDontCount(): void
    {
        $this->restaurants(5, pending: 4);
        self::assertSame(5, $this->billing->resellerBilledRestaurants($this->reseller));
        self::assertSame(0, $this->billing->resellerExtraQuantity($this->reseller));

        $this->billing->startResellerCheckout($this->reseller, $this->user, 'monthly', 'https://ok', 'https://back');
        self::assertSame([['price' => self::BASE_MONTHLY, 'quantity' => 1]], FakeStripeGateway::$checkoutParams[0]['line_items']);

        $this->live(extra: 2);
        $this->billing->syncResellerExtras($this->reloaded());
        self::assertSame([['id' => 'si_extra_sub_r', 'deleted' => true]], self::itemUpdates()[0]['items']);
    }

    public function testCheckoutReusesCustomer(): void
    {
        $this->billing->startResellerCheckout($this->reseller, $this->user, 'monthly', 'https://ok', 'https://back');
        $this->billing->startResellerCheckout($this->reseller, $this->user, 'monthly', 'https://ok', 'https://back');

        self::assertCount(1, FakeStripeGateway::$createdCustomers);
    }

    // ── Webhook-side sync ──

    public function testCheckoutCompletedLinksReseller(): void
    {
        FakeStripeGateway::price(self::BASE_MONTHLY, 4000, 'month');
        FakeStripeGateway::resellerSubscription('sub_new', $this->reseller->getId(), self::BASE_MONTHLY, self::EXTRA_MONTHLY, 0, 'cus_new');
        FakeStripeGateway::$objects['cs_r'] = ['id' => 'cs_r', 'mode' => 'subscription', 'subscription' => 'sub_new', 'client_reference_id' => 'reseller-' . $this->reseller->getId()];

        $this->billing->syncCheckoutSession('cs_r');

        $reseller = $this->reloaded();
        self::assertSame('sub_new', $reseller->getStripeSubscriptionId());
        self::assertSame('cus_new', $reseller->getStripeCustomerId());
        self::assertSame('monthly', $reseller->getPlan());
    }

    public function testCheckoutReturnOfAnotherCustomerIsIgnored(): void
    {
        FakeStripeGateway::price(self::BASE_MONTHLY, 4000, 'month');
        FakeStripeGateway::resellerSubscription('sub_new', $this->reseller->getId(), self::BASE_MONTHLY, self::EXTRA_MONTHLY, 0);
        FakeStripeGateway::$objects['cs_r'] = ['id' => 'cs_r', 'mode' => 'subscription', 'subscription' => 'sub_new', 'client_reference_id' => (string) $this->reseller->getId()];

        // A bare id is a restaurant's reference, never this reseller's.
        $this->billing->syncCheckoutSession('cs_r', $this->reseller);

        self::assertNull($this->reloaded()->getStripeSubscriptionId());
    }

    public function testPaidInvoiceActivatesReseller(): void
    {
        $this->live(self::BASE_YEARLY, self::EXTRA_YEARLY, 2);
        $end = (new \DateTimeImmutable('2028-05-10 08:00', new \DateTimeZone('UTC')))->getTimestamp();
        $paid = (new \DateTimeImmutable('2027-05-10 08:00', new \DateTimeZone('UTC')))->getTimestamp();
        FakeStripeGateway::invoice('in_r', 'sub_r', $end, 52000, $paid);

        $this->billing->syncInvoice('in_r');

        $reseller = $this->reloaded();
        self::assertFalse($reseller->isExpired(new \DateTimeImmutable('2028-05-10')));
        self::assertSame('2028-05-10', $reseller->getPaidUntil()->format('Y-m-d'));
        self::assertSame('yearly', $reseller->getPlan());
        self::assertSame('2027-05-10', $reseller->getLastPaidAt()->format('Y-m-d'));
    }

    public function testCancelMarkAndDeletion(): void
    {
        $this->live();
        FakeStripeGateway::$objects['sub_r']['cancel_at_period_end'] = true;

        $this->billing->syncSubscription('sub_r');
        self::assertTrue($this->reloaded()->isStripeCancelPending());

        $this->billing->syncSubscriptionDeleted('sub_r');
        $reseller = $this->reloaded();
        self::assertNull($reseller->getStripeSubscriptionId());
        self::assertFalse($reseller->isStripeCancelPending());
    }

    // ── Extra line ──

    public function testExtraLineIsAddedWhenNeeded(): void
    {
        $this->live();
        $this->restaurants(7);

        $this->billing->syncResellerExtras($this->reloaded());

        self::assertSame([[
            'items'              => [['price' => self::EXTRA_MONTHLY, 'quantity' => 2]],
            'proration_behavior' => 'create_prorations',
        ]], self::itemUpdates());
    }

    public function testExtraLineFollowsTheCountAndIsRemovedAtZero(): void
    {
        $this->live(extra: 3);
        $this->restaurants(6, archived: 4);

        $this->billing->syncResellerExtras($this->reloaded());
        self::assertSame([['id' => 'si_extra_sub_r', 'quantity' => 1]], self::itemUpdates()[0]['items']);

        $this->em->clear();
        $one = $this->em->getRepository(Restaurant::class)->findOneBy(['reseller' => $this->reseller->getId(), 'archivedAt' => null]);
        $one->archive();
        $this->em->flush();

        $this->billing->syncResellerExtras($this->reloaded());
        self::assertSame([['id' => 'si_extra_sub_r', 'deleted' => true]], self::itemUpdates()[1]['items']);
        self::assertCount(1, FakeStripeGateway::$objects['sub_r']['items']['data']);
    }

    public function testExtraLineUsesThePlansInterval(): void
    {
        $this->live(self::BASE_YEARLY, self::EXTRA_YEARLY);
        $this->restaurants(6);

        $this->billing->syncResellerExtras($this->reloaded());

        self::assertSame([['price' => self::EXTRA_YEARLY, 'quantity' => 1]], self::itemUpdates()[0]['items']);
    }

    public function testNothingSentWhenCountMatchesOrNoLiveSubscription(): void
    {
        $this->restaurants(7);
        $this->billing->syncResellerExtras($this->reloaded());
        self::assertSame([], FakeStripeGateway::$calls);

        $this->live(extra: 2);
        $this->billing->syncResellerExtras($this->reloaded());
        self::assertSame([], self::itemUpdates());
    }

    // ── Subscription screen ──

    public function testOverviewShowsExtraLineAndTotalWithoutPlanChange(): void
    {
        $this->live(extra: 3);
        FakeStripeGateway::$objects['sub_r']['items']['data'][0]['current_period_end'] = 1900000000;

        $overview = $this->billing->overview($this->reloaded());

        self::assertSame('monthly', $overview['plan']);
        self::assertSame(4000, $overview['amount']);
        self::assertSame(['quantity' => 3, 'unit_amount' => 600], $overview['extra']);
        self::assertSame(['date' => 1900000000, 'amount' => 5800], $overview['next_charge']);
        self::assertNull($overview['other_plan']);
        self::assertNull($overview['pending_change']);
    }
}
