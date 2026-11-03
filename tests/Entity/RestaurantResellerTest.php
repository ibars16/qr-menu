<?php

namespace App\Tests\Entity;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use PHPUnit\Framework\TestCase;

/**
 * A reseller's restaurant is active exactly while its reseller is
 * (Restaurant::isExpired()), never shows trial or "vence pronto" notices,
 * and an archived one is off whatever anyone paid. A new reseller has no
 * trial: expired until its first payment.
 */
final class RestaurantResellerTest extends TestCase
{
    private function paidUntil(Restaurant|Reseller $customer, string $date): void
    {
        (new \ReflectionProperty($customer::class, 'paidUntil'))->setValue($customer, new \DateTimeImmutable($date));
    }

    private function restaurantOf(Reseller $reseller): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setReseller($reseller);

        return $restaurant;
    }

    public function testNewResellerIsExpiredUntilItPays(): void
    {
        $reseller = new Reseller('Agencia');

        self::assertTrue($reseller->isExpired());
        self::assertFalse($reseller->isTrialActive());

        $reseller->markPaid(Reseller::PLAN_MONTHLY);

        self::assertFalse($reseller->isExpired());
        self::assertSame('monthly', $reseller->getPlan());
    }

    public function testRestaurantFollowsItsResellerNotItsOwnDate(): void
    {
        $reseller   = new Reseller('Agencia');
        $restaurant = $this->restaurantOf($reseller);

        // Its own trial date is still ahead, yet the unpaid reseller turns it off.
        self::assertTrue($restaurant->isBilledByReseller());
        self::assertGreaterThan(new \DateTimeImmutable('today'), $restaurant->getPaidUntil());
        self::assertTrue($restaurant->isExpired());

        $reseller->markPaid(Reseller::PLAN_YEARLY);
        $this->paidUntil($restaurant, 'yesterday');

        self::assertFalse($restaurant->isExpired());
    }

    /** With a reseller, its own paid_until (the 30-day trial) never counts: only archived or reseller expired. */
    public function testResellerRestaurantIgnoresItsOwnPaidUntil(): void
    {
        $reseller = new Reseller('Agencia');
        $reseller->markPaid(Reseller::PLAN_MONTHLY);
        $restaurant = $this->restaurantOf($reseller);

        foreach (['-1 year', 'yesterday', 'today', '+30 days'] as $own) {
            $this->paidUntil($restaurant, $own);
            self::assertFalse($restaurant->isExpired(), "own paid_until $own, reseller up to date");
        }

        $reseller->deactivateNow();
        foreach (['yesterday', '+30 days', '+5 years'] as $own) {
            $this->paidUntil($restaurant, $own);
            self::assertTrue($restaurant->isExpired(), "own paid_until $own, reseller expired");
        }
    }

    public function testResellerRestaurantNeverShowsTrialOrEndingSoon(): void
    {
        $reseller = new Reseller('Agencia');
        $reseller->markPaid(Reseller::PLAN_MONTHLY);
        $this->paidUntil($reseller, '+2 days');
        $restaurant = $this->restaurantOf($reseller);

        self::assertNull($restaurant->getPlan());
        self::assertFalse($restaurant->isTrialActive());
        self::assertSame(0, $restaurant->getTrialDaysRemaining());
        self::assertFalse($restaurant->isEndingSoon());
        self::assertTrue($reseller->isEndingSoon());
    }

    public function testArchivedRestaurantIsOffEvenWhenPaid(): void
    {
        $reseller = new Reseller('Agencia');
        $reseller->markPaid(Reseller::PLAN_MONTHLY);
        $restaurant = $this->restaurantOf($reseller);

        $restaurant->archive();
        self::assertTrue($restaurant->isArchived());
        self::assertTrue($restaurant->isExpired());

        $restaurant->unarchive();
        self::assertFalse($restaurant->isExpired());
    }

    public function testSelfBilledRestaurantKeepsItsOwnRules(): void
    {
        $restaurant = new Restaurant();

        self::assertFalse($restaurant->isBilledByReseller());
        self::assertTrue($restaurant->isTrialActive());
        self::assertSame(30, $restaurant->getTrialDaysRemaining());

        $this->paidUntil($restaurant, 'yesterday');
        self::assertTrue($restaurant->isExpired());
    }
}
