<?php

namespace App\Tests\Entity;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use PHPUnit\Framework\TestCase;

/**
 * Restaurant::isExpired()'s card-renewal grace: a live Stripe subscription
 * (not set to cancel) whose renewal hasn't been paid keeps everything on
 * until paidUntil + 3 days. Never for the trial, payments by hand, a
 * cancelled subscription, Desactivar / Archivar or an expired reseller.
 */
final class RestaurantCardRenewalGraceTest extends TestCase
{
    private const TODAY = '2026-11-15';

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::TODAY);
    }

    private static function set(Restaurant $restaurant, string $property, mixed $value): void
    {
        (new \ReflectionProperty(Restaurant::class, $property))->setValue($restaurant, $value);
    }

    /** Paid by card once, its subscription still live, paidUntil = $paidUntil. */
    private static function byCard(string $paidUntil): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setPlan(Restaurant::PLAN_MONTHLY);
        self::set($restaurant, 'lastPaidAt', new \DateTimeImmutable('2026-10-01'));
        self::set($restaurant, 'paidUntil', new \DateTimeImmutable($paidUntil));
        $restaurant->setStripeSubscriptionId('sub_grace');

        return $restaurant;
    }

    public function testFailedRenewalKeepsItOnForThreeDays(): void
    {
        foreach (['2026-11-15', '2026-11-14', '2026-11-13', '2026-11-12'] as $paidUntil) {
            $restaurant = self::byCard($paidUntil);
            self::assertFalse($restaurant->isExpired(self::today()), $paidUntil);
            self::assertFalse($restaurant->isOwnPeriodExpired(self::today()), $paidUntil);
        }
        self::assertTrue(self::byCard('2026-11-14')->isInCardRenewalGrace(self::today()));
        self::assertFalse(self::byCard('2026-11-15')->isInCardRenewalGrace(self::today()), 'paidUntil itself is an ordinary active day');
    }

    public function testOffOnTheFourthDay(): void
    {
        $restaurant = self::byCard('2026-11-11');

        self::assertTrue($restaurant->isExpired(self::today()));
        self::assertFalse($restaurant->isInCardRenewalGrace(self::today()));
    }

    public function testTrialHasNoGrace(): void
    {
        $restaurant = new Restaurant();
        self::set($restaurant, 'paidUntil', new \DateTimeImmutable('2026-11-14'));

        self::assertTrue($restaurant->isExpired(self::today()));
    }

    public function testPaidByHandHasNoGrace(): void
    {
        $restaurant = self::byCard('2026-11-14');
        $restaurant->setStripeSubscriptionId(null);

        self::assertTrue($restaurant->isExpired(self::today()));
    }

    public function testCancelledSubscriptionHasNoGrace(): void
    {
        $restaurant = self::byCard('2026-11-14');
        $restaurant->setStripeCancelPending(true);

        self::assertTrue($restaurant->isExpired(self::today()));
    }

    /** Desactivar / Archivar end the subscription first (StripeBilling::cancelNow() unlinks it). */
    public function testDeactivatedHasNoGrace(): void
    {
        $restaurant = self::byCard('2026-12-01');
        $restaurant->setStripeSubscriptionId(null);
        $restaurant->deactivateNow(self::today());

        self::assertTrue($restaurant->isExpired(self::today()));
    }

    public function testArchivedOrResellerExpiredHasNoGrace(): void
    {
        $restaurant = self::byCard('2026-11-14');
        $restaurant->archive();
        self::assertTrue($restaurant->isExpired(self::today()));

        $restaurant = self::byCard('2026-11-14');
        $reseller = new Reseller('Grace Test Agency');
        $reseller->deactivateNow(self::today());
        $restaurant->setReseller($reseller);
        self::assertTrue($restaurant->isExpired(self::today()), 'its reseller expired');
    }

    public function testNoNoticesDuringGrace(): void
    {
        $restaurant = self::byCard('2026-11-14');

        self::assertFalse($restaurant->isEndingSoon(self::today()));
        self::assertFalse($restaurant->isTrialActive(self::today()));
    }
}
