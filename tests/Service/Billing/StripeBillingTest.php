<?php

namespace App\Tests\Service\Billing;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\Billing\StripeBilling;
use App\Service\DishPhoto\DishPhotoQuota;
use App\Tests\Support\FakeStripeGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * StripeBilling against FakeStripeGateway (never Stripe): checkout params
 * (free trial: charged at once, no trial_end; days paid by hand: trial_end
 * so they aren't charged twice), and the webhook-side sync —
 * forward-only dates, 0 € trial invoice not counted as a payment, repeated
 * and out-of-order events harmless, a second subscription only logged.
 */
final class StripeBillingTest extends KernelTestCase
{
    private const MONTHLY = 'price_test_monthly';
    private const YEARLY = 'price_test_yearly';

    private EntityManagerInterface $em;
    private StripeBilling $billing;
    private Restaurant $restaurant;
    private User $owner;

    protected function setUp(): void
    {
        self::bootKernel();
        FakeStripeGateway::reset();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->billing = static::getContainer()->get(StripeBilling::class);

        $this->restaurant = new Restaurant();
        $this->restaurant->setName('Stripe Billing Test Restaurant');
        $this->restaurant->setSlug('stripe-billing-test-' . uniqid());
        $this->em->persist($this->restaurant);

        $this->owner = new User();
        $this->owner->setEmail('stripe-billing-test-' . uniqid() . '@example.test');
        $this->owner->setPassword('unused-in-tests');
        $this->owner->setRoles([User::ROLE_OWNER]);
        $this->owner->setRestaurant($this->restaurant);
        $this->em->persist($this->owner);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->remove($this->em->find(User::class, $this->owner->getId()));
        $this->em->remove($this->em->find(Restaurant::class, $this->restaurant->getId()));
        $this->em->flush();

        parent::tearDown();
    }

    private function setPaidUntil(string $date): void
    {
        (new \ReflectionProperty(Restaurant::class, 'paidUntil'))->setValue($this->restaurant, new \DateTimeImmutable($date));
        $this->em->flush();
    }

    /** A payment registered by hand (/platform, the reseller): the days up to paidUntil are paid. */
    private function setLastPaidAt(string $date): void
    {
        (new \ReflectionProperty(Restaurant::class, 'lastPaidAt'))->setValue($this->restaurant, new \DateTimeImmutable($date));
        $this->em->flush();
    }

    private function reloaded(): Restaurant
    {
        $this->em->clear();

        return $this->em->find(Restaurant::class, $this->restaurant->getId());
    }

    private static function ts(string $utc): int
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
    }

    // ── Checkout ──

    public function testTrialEndIsEndOfLastPaidDay(): void
    {
        $this->setPaidUntil('2026-11-20');
        $this->setLastPaidAt('2026-10-20');

        self::assertSame(self::ts('2026-11-21 00:00'), $this->billing->trialEndFor($this->restaurant, new \DateTimeImmutable('2026-11-01 10:00', new \DateTimeZone('UTC'))));
    }

    public function testNeverPaidChargesNowEvenWithTrialDaysLeft(): void
    {
        $this->setPaidUntil('2026-11-20');

        self::assertNull($this->billing->trialEndFor($this->restaurant, new \DateTimeImmutable('2026-11-01 10:00', new \DateTimeZone('UTC'))));
    }

    public function testChargesNowWhenUnder48HoursLeft(): void
    {
        $this->setPaidUntil('2026-11-20');
        $this->setLastPaidAt('2026-10-20');

        // 2026-11-21 00:00 is 38 hours away.
        self::assertNull($this->billing->trialEndFor($this->restaurant, new \DateTimeImmutable('2026-11-19 10:00', new \DateTimeZone('UTC'))));
    }

    public function testChargesNowWhenExpired(): void
    {
        $this->setPaidUntil('2026-11-20');
        $this->setLastPaidAt('2026-10-20');

        self::assertNull($this->billing->trialEndFor($this->restaurant, new \DateTimeImmutable('2026-11-25 10:00', new \DateTimeZone('UTC'))));
    }

    public function testStartCheckoutInFreeTrialChargesNow(): void
    {
        $url = $this->billing->startCheckout($this->restaurant, $this->owner, Restaurant::PLAN_YEARLY, 'https://ok', 'https://cancel');

        self::assertStringStartsWith('https://checkout.stripe.test/', $url);
        $params = FakeStripeGateway::$checkoutParams[0];
        self::assertSame('subscription', $params['mode']);
        self::assertSame([['price' => self::YEARLY, 'quantity' => 1]], $params['line_items']);
        self::assertSame((string) $this->restaurant->getId(), $params['client_reference_id']);
        self::assertSame((string) $this->restaurant->getId(), $params['subscription_data']['metadata']['restaurant_id']);
        self::assertFalse($this->restaurant->isExpired(), 'still in the free trial');
        self::assertArrayNotHasKey('trial_end', $params['subscription_data'], 'the trial ends: charged today');
        self::assertArrayNotHasKey('custom_text', $params);
        self::assertNotNull($this->reloaded()->getStripeCustomerId());

        // Second checkout (another tab): same customer, none created.
        $this->billing->startCheckout($this->reloaded(), $this->owner, Restaurant::PLAN_MONTHLY, 'https://ok', 'https://cancel');
        self::assertCount(1, FakeStripeGateway::$createdCustomers);
        self::assertSame(FakeStripeGateway::$checkoutParams[0]['customer'], FakeStripeGateway::$checkoutParams[1]['customer']);
    }

    public function testStartCheckoutWithDaysPaidByHandKeepsThem(): void
    {
        $this->setPaidUntil('today +20 days');
        $this->setLastPaidAt('today -10 days');

        $this->billing->startCheckout($this->restaurant, $this->owner, Restaurant::PLAN_MONTHLY, 'https://ok', 'https://cancel');

        $params = FakeStripeGateway::$checkoutParams[0];
        self::assertSame(self::ts('today +21 days'), $params['subscription_data']['trial_end']);
        self::assertSame('No charge until ' . gmdate('d/m/Y', $params['subscription_data']['trial_end']), $params['custom_text']['submit']['message']);
    }

    public function testStartCheckoutForExpiredRestaurantHasNoTrial(): void
    {
        $this->setPaidUntil('yesterday');

        $this->billing->startCheckout($this->restaurant, $this->owner, Restaurant::PLAN_MONTHLY, 'https://ok', 'https://cancel');

        self::assertArrayNotHasKey('trial_end', FakeStripeGateway::$checkoutParams[0]['subscription_data']);
        self::assertArrayNotHasKey('custom_text', FakeStripeGateway::$checkoutParams[0]);
    }

    // ── Sync ──

    public function testCheckoutCompletedDuringTrialLinksAndActivatesWithoutPayment(): void
    {
        $id = $this->restaurant->getId();
        FakeStripeGateway::subscription('sub_1', $id, self::MONTHLY, 'cus_1');
        // The trial's 0 € invoice, covering up to the trial end.
        FakeStripeGateway::invoice('in_trial', 'sub_1', self::ts('today +31 days'), 0, time());
        FakeStripeGateway::$objects['cs_1'] = ['id' => 'cs_1', 'mode' => 'subscription', 'subscription' => 'sub_1', 'client_reference_id' => (string) $id, 'invoice' => 'in_trial'];

        $this->billing->syncCheckoutSession('cs_1');

        $restaurant = $this->reloaded();
        self::assertSame('sub_1', $restaurant->getStripeSubscriptionId());
        self::assertSame('cus_1', $restaurant->getStripeCustomerId());
        self::assertSame(Restaurant::PLAN_MONTHLY, $restaurant->getPlan());
        self::assertFalse($restaurant->isTrialActive());
        self::assertNull($restaurant->getLastPaidAt(), '0 € invoice is not a payment');
        self::assertSame((new \DateTimeImmutable('today +31 days'))->format('Y-m-d'), $restaurant->getPaidUntil()->format('Y-m-d'));
    }

    public function testCheckoutInFreeTrialCountsAsFirstPaymentAtOnce(): void
    {
        $id = $this->restaurant->getId();
        self::assertTrue(DishPhotoQuota::isLimited($this->restaurant), 'free trial: 2 AI photos');
        FakeStripeGateway::subscription('sub_1', $id, self::MONTHLY, 'cus_1');
        // Charged today: the first period starts now.
        $periodEnd = (new \DateTimeImmutable('today'))->modify('+1 month');
        FakeStripeGateway::invoice('in_first', 'sub_1', $periodEnd->getTimestamp(), 3999, time());
        FakeStripeGateway::$objects['cs_1'] = ['id' => 'cs_1', 'mode' => 'subscription', 'subscription' => 'sub_1', 'client_reference_id' => (string) $id, 'invoice' => 'in_first'];

        $this->billing->syncCheckoutSession('cs_1');

        $restaurant = $this->reloaded();
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $restaurant->getLastPaidAt()?->format('Y-m-d'));
        self::assertSame($periodEnd->format('Y-m-d'), $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertFalse(DishPhotoQuota::isLimited($restaurant), 'paid: the monthly AI photo allowance at once');
        self::assertFalse(DishPhotoQuota::isCardPending($restaurant));
        self::assertNull(static::getContainer()->get(DishPhotoQuota::class)->trialRemaining($restaurant), 'no trial counter');
    }

    public function testCheckoutReturnForAnotherRestaurantIsIgnored(): void
    {
        FakeStripeGateway::subscription('sub_1', 999999, self::MONTHLY);
        FakeStripeGateway::$objects['cs_1'] = ['id' => 'cs_1', 'mode' => 'subscription', 'subscription' => 'sub_1', 'client_reference_id' => '999999'];

        $this->billing->syncCheckoutSession('cs_1', $this->restaurant);

        self::assertNull($this->reloaded()->getStripeSubscriptionId());
    }

    public function testPaidInvoiceSetsPeriodEndPlanAndLastPaidAt(): void
    {
        FakeStripeGateway::subscription('sub_1', $this->restaurant->getId(), self::YEARLY);
        FakeStripeGateway::invoice('in_1', 'sub_1', self::ts('2028-03-15 09:30'), 30000, self::ts('2027-03-15 09:30'));

        $this->billing->syncInvoice('in_1');

        $restaurant = $this->reloaded();
        self::assertSame('2028-03-15', $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertSame(Restaurant::PLAN_YEARLY, $restaurant->getPlan());
        self::assertSame('2027-03-15', $restaurant->getLastPaidAt()->format('Y-m-d'));
        self::assertSame('sub_1', $restaurant->getStripeSubscriptionId());
    }

    public function testRepeatedAndOutOfOrderInvoicesNeverMoveDatesBack(): void
    {
        $id = $this->restaurant->getId();
        FakeStripeGateway::subscription('sub_1', $id, self::MONTHLY);
        FakeStripeGateway::invoice('in_old', 'sub_1', self::ts('2027-02-10'), 3000, self::ts('2027-01-10'));
        FakeStripeGateway::invoice('in_new', 'sub_1', self::ts('2027-03-10'), 3000, self::ts('2027-02-10'));

        $this->billing->syncInvoice('in_new');
        $this->billing->syncInvoice('in_old');
        $this->billing->syncInvoice('in_new');

        $restaurant = $this->reloaded();
        self::assertSame('2027-03-10', $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertSame('2027-02-10', $restaurant->getLastPaidAt()->format('Y-m-d'));
    }

    public function testUnpaidInvoiceChangesNothing(): void
    {
        FakeStripeGateway::subscription('sub_1', $this->restaurant->getId(), self::MONTHLY);
        FakeStripeGateway::invoice('in_1', 'sub_1', self::ts('2030-01-01'), 3000, time(), 'open');
        $before = $this->restaurant->getPaidUntil()->format('Y-m-d');

        $this->billing->syncInvoice('in_1');

        $restaurant = $this->reloaded();
        self::assertSame($before, $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertNull($restaurant->getPlan());
    }

    public function testSecondSubscriptionIsNotLinked(): void
    {
        $id = $this->restaurant->getId();
        FakeStripeGateway::subscription('sub_1', $id, self::MONTHLY);
        FakeStripeGateway::subscription('sub_2', $id, self::MONTHLY);
        FakeStripeGateway::invoice('in_1', 'sub_1', self::ts('2027-01-10'), 3000, self::ts('2026-12-10'));
        FakeStripeGateway::invoice('in_2', 'sub_2', self::ts('2027-01-12'), 3000, self::ts('2026-12-12'));

        $this->billing->syncInvoice('in_1');
        $this->billing->syncInvoice('in_2');

        self::assertSame('sub_1', $this->reloaded()->getStripeSubscriptionId());
    }

    public function testDeletedSubscriptionUnlinksButKeepsDatesAndPlan(): void
    {
        FakeStripeGateway::subscription('sub_1', $this->restaurant->getId(), self::MONTHLY);
        FakeStripeGateway::invoice('in_1', 'sub_1', self::ts('2027-01-10'), 3000, self::ts('2026-12-10'));
        $this->billing->syncInvoice('in_1');

        $this->billing->syncSubscriptionDeleted('sub_1');

        $restaurant = $this->reloaded();
        self::assertNull($restaurant->getStripeSubscriptionId());
        self::assertFalse($restaurant->hasLiveSubscription());
        self::assertSame('2027-01-10', $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertSame(Restaurant::PLAN_MONTHLY, $restaurant->getPlan());
    }

    public function testSubscriptionSetToCancelIsMarkedAndUndoClearsIt(): void
    {
        $this->restaurant->setStripeSubscriptionId('sub_1');
        $this->em->flush();
        FakeStripeGateway::subscription('sub_1', $this->restaurant->getId(), self::MONTHLY);
        $before = $this->restaurant->getPaidUntil()->format('Y-m-d');

        FakeStripeGateway::$objects['sub_1']['cancel_at_period_end'] = true;
        $this->billing->syncSubscription('sub_1');
        self::assertTrue($this->reloaded()->isStripeCancelPending());

        // "No cancelar" in the portal.
        FakeStripeGateway::$objects['sub_1']['cancel_at_period_end'] = false;
        $this->billing->syncSubscription('sub_1');
        self::assertFalse($this->reloaded()->isStripeCancelPending());

        // Newer API versions set a cancel_at date instead.
        FakeStripeGateway::$objects['sub_1']['cancel_at'] = self::ts('2027-01-10');
        $this->billing->syncSubscription('sub_1');
        $restaurant = $this->reloaded();
        self::assertTrue($restaurant->isStripeCancelPending());
        self::assertTrue($restaurant->hasLiveSubscription());
        self::assertSame($before, $restaurant->getPaidUntil()->format('Y-m-d'));
    }

    public function testStaleUpdateIsCorrectedByReReadingStripe(): void
    {
        $this->restaurant->setStripeSubscriptionId('sub_1');
        $this->em->flush();
        FakeStripeGateway::subscription('sub_1', $this->restaurant->getId(), self::MONTHLY);

        // The "cancel" event arrives after the undo: Stripe's current state wins.
        $this->billing->syncSubscription('sub_1');
        $this->billing->syncSubscription('sub_1');

        self::assertFalse($this->reloaded()->isStripeCancelPending());
    }

    public function testUpdateOfUnlinkedSubscriptionIsIgnored(): void
    {
        $this->restaurant->setStripeSubscriptionId('sub_1');
        $this->em->flush();
        FakeStripeGateway::subscription('sub_extra', $this->restaurant->getId(), self::MONTHLY);
        FakeStripeGateway::$objects['sub_extra']['cancel_at_period_end'] = true;

        $this->billing->syncSubscription('sub_extra');

        self::assertFalse($this->reloaded()->isStripeCancelPending());
    }

    public function testCheckoutLinkingPicksUpCancelAndDeleteClearsIt(): void
    {
        $id = $this->restaurant->getId();
        FakeStripeGateway::subscription('sub_1', $id, self::MONTHLY, 'cus_1');
        FakeStripeGateway::$objects['sub_1']['cancel_at'] = self::ts('2027-01-10');
        FakeStripeGateway::$objects['cs_1'] = ['id' => 'cs_1', 'mode' => 'subscription', 'subscription' => 'sub_1', 'client_reference_id' => (string) $id];

        $this->billing->syncCheckoutSession('cs_1');
        self::assertTrue($this->reloaded()->isStripeCancelPending());

        $this->billing->syncSubscriptionDeleted('sub_1');
        self::assertFalse($this->reloaded()->isStripeCancelPending());
    }

    // ── cancelNow (Desactivar / Archivar) ──

    public function testCancelNowEndsAndUnlinks(): void
    {
        FakeStripeGateway::subscription('sub_1', $this->restaurant->getId(), self::MONTHLY);
        $this->restaurant->setStripeSubscriptionId('sub_1');
        $this->em->flush();

        $this->billing->cancelNow($this->restaurant);

        self::assertSame([['cancelSubscription', 'sub_1', []]], FakeStripeGateway::$calls);
        self::assertNull($this->reloaded()->getStripeSubscriptionId());
    }

    public function testCancelNowOfOneStripeAlreadyEndedJustUnlinks(): void
    {
        FakeStripeGateway::subscription('sub_1', $this->restaurant->getId(), self::MONTHLY);
        FakeStripeGateway::$objects['sub_1']['status'] = 'canceled';
        $this->restaurant->setStripeSubscriptionId('sub_1');
        $this->em->flush();

        $this->billing->cancelNow($this->restaurant);

        self::assertSame([], FakeStripeGateway::$calls);
        self::assertNull($this->reloaded()->getStripeSubscriptionId());
    }

    public function testLateInvoiceOfEndedSubscriptionChangesNothing(): void
    {
        $this->setPaidUntil('yesterday');
        FakeStripeGateway::subscription('sub_1', $this->restaurant->getId(), self::MONTHLY);
        FakeStripeGateway::$objects['sub_1']['status'] = 'canceled';
        FakeStripeGateway::invoice('in_1', 'sub_1', self::ts('today +1 month'), 3999, time());

        $this->billing->syncInvoice('in_1');

        $restaurant = $this->reloaded();
        self::assertTrue($restaurant->isExpired());
        self::assertNull($restaurant->getStripeSubscriptionId());
        self::assertNull($restaurant->getLastPaidAt());
    }
}
