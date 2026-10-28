<?php

namespace App\Tests\Entity;

use App\Entity\Restaurant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The trial badge shows while the restaurant has never paid (plan null) and
 * isn't expired — see Restaurant::isTrialActive(). No DB round-trip needed
 * here, just reflection to set paidUntil on a plain object.
 */
final class RestaurantTrialTest extends TestCase
{
    private function restaurantPaidUntil(string $date): Restaurant
    {
        $restaurant = new Restaurant();
        (new \ReflectionProperty(Restaurant::class, 'paidUntil'))
            ->setValue($restaurant, new \DateTimeImmutable($date));

        return $restaurant;
    }

    public function testNewRestaurantIsOnTrialWithThirtyDaysRemaining(): void
    {
        $restaurant = new Restaurant();

        self::assertNull($restaurant->getPlan());
        self::assertTrue($restaurant->isTrialActive());
        self::assertSame(30, $restaurant->getTrialDaysRemaining());
    }

    public function testTrialIsActiveWithOneDayRemainingOnTheLastDay(): void
    {
        // paidUntil = today: still an active day.
        $restaurant = $this->restaurantPaidUntil('today');

        self::assertTrue($restaurant->isTrialActive());
        self::assertSame(1, $restaurant->getTrialDaysRemaining());
    }

    public function testTrialHasEndedOnceExpired(): void
    {
        $restaurant = $this->restaurantPaidUntil('yesterday');

        self::assertTrue($restaurant->isExpired());
        self::assertFalse($restaurant->isTrialActive());
        self::assertSame(0, $restaurant->getTrialDaysRemaining());
    }

    public function testExistingRestaurantNeverPaidIsOnTrial(): void
    {
        // Backfilled restaurants: plan null, paidUntil = migration day + 30,
        // regardless of how long ago they signed up.
        $restaurant = $this->restaurantPaidUntil('today +12 days');

        self::assertTrue($restaurant->isTrialActive());
        self::assertSame(12, $restaurant->getTrialDaysRemaining());
    }

    /** @return iterable<string, array{string}> */
    public static function plans(): iterable
    {
        yield 'monthly' => [Restaurant::PLAN_MONTHLY];
        yield 'yearly'  => [Restaurant::PLAN_YEARLY];
    }

    #[DataProvider('plans')]
    public function testNoTrialBadgeOncePaid(string $plan): void
    {
        $restaurant = new Restaurant();
        $restaurant->markPaid($plan);

        self::assertFalse($restaurant->isExpired());
        self::assertFalse($restaurant->isTrialActive());
        self::assertSame(0, $restaurant->getTrialDaysRemaining());
    }

    #[DataProvider('plans')]
    public function testDeactivatingAPaidRestaurantDoesNotPutItBackOnTrial(string $plan): void
    {
        $restaurant = new Restaurant();
        $restaurant->markPaid($plan);
        $restaurant->deactivateNow();

        self::assertSame($plan, $restaurant->getPlan());
        self::assertTrue($restaurant->isExpired());
        self::assertFalse($restaurant->isTrialActive());
    }
}
