<?php

namespace App\Tests\Entity;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use PHPUnit\Framework\TestCase;

/**
 * A reseller's restaurant is active while its reseller is AND its own
 * paid_until (set by the reseller in /partners) hasn't passed
 * (Restaurant::isExpired()); it never shows the Stripe trial or "vence
 * pronto" notices, only its own "contacta con tu revendedor" one, and an
 * archived one is off whatever anyone paid. A new reseller has no trial:
 * expired until its first payment.
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

    public function testUnpaidResellerTurnsItOffDespiteItsOwnDate(): void
    {
        $reseller   = new Reseller('Agencia');
        $restaurant = $this->restaurantOf($reseller);

        // Its own trial date is still ahead, yet the unpaid reseller turns it off.
        self::assertTrue($restaurant->isBilledByReseller());
        self::assertGreaterThan(new \DateTimeImmutable('today'), $restaurant->getPaidUntil());
        self::assertTrue($restaurant->isExpired());
        self::assertFalse($restaurant->isOwnPeriodExpired());

        $reseller->markPaid(Reseller::PLAN_YEARLY);

        self::assertFalse($restaurant->isExpired());
    }

    /** Expired = archived OR reseller expired OR its own paid_until passed. */
    public function testResellerRestaurantAlsoExpiresOnItsOwnPaidUntil(): void
    {
        $reseller = new Reseller('Agencia');
        $reseller->markPaid(Reseller::PLAN_MONTHLY);
        $restaurant = $this->restaurantOf($reseller);

        foreach (['today', '+30 days'] as $own) {
            $this->paidUntil($restaurant, $own);
            self::assertFalse($restaurant->isExpired(), "own paid_until $own, reseller up to date");
        }
        foreach (['-1 year', 'yesterday'] as $own) {
            $this->paidUntil($restaurant, $own);
            self::assertTrue($restaurant->isExpired(), "own paid_until $own, reseller up to date");
            self::assertTrue($restaurant->isOwnPeriodExpired());
        }

        $reseller->deactivateNow();
        foreach (['yesterday', '+30 days', '+5 years'] as $own) {
            $this->paidUntil($restaurant, $own);
            self::assertTrue($restaurant->isExpired(), "own paid_until $own, reseller expired");
        }
    }

    /** The reseller renews it by hand, like /platform does for a self-billed one. */
    public function testResellerCanRenewOrDeactivateIt(): void
    {
        $reseller = new Reseller('Agencia');
        $reseller->markPaid(Reseller::PLAN_MONTHLY);
        $restaurant = $this->restaurantOf($reseller);

        $restaurant->deactivateNow();
        self::assertTrue($restaurant->isExpired());

        $restaurant->markPaid(Restaurant::PLAN_MONTHLY);
        self::assertFalse($restaurant->isExpired());
        self::assertSame('monthly', $restaurant->getPlan());
        self::assertEquals(new \DateTimeImmutable('today'), $restaurant->getLastPaidAt());
    }

    public function testOwnerNoticeWhenItsOwnDateIsSevenDaysOrLess(): void
    {
        $reseller = new Reseller('Agencia');
        $reseller->markPaid(Reseller::PLAN_YEARLY);
        $restaurant = $this->restaurantOf($reseller);

        $this->paidUntil($restaurant, '+8 days');
        self::assertFalse($restaurant->isResellerServiceEndingSoon());

        foreach (['+7 days', '+1 day', 'today'] as $own) {
            $this->paidUntil($restaurant, $own);
            self::assertTrue($restaurant->isResellerServiceEndingSoon(), "own paid_until $own");
        }

        // Expired (its own date, or archived): the expired page instead.
        $this->paidUntil($restaurant, 'yesterday');
        self::assertFalse($restaurant->isResellerServiceEndingSoon());
        $this->paidUntil($restaurant, '+2 days');
        $restaurant->archive();
        self::assertFalse($restaurant->isResellerServiceEndingSoon());

        // Never for a self-billed restaurant: it has the Stripe notices.
        $own = new Restaurant();
        $this->paidUntil($own, '+2 days');
        self::assertFalse($own->isResellerServiceEndingSoon());
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
