<?php

namespace App\Tests\Entity;

use App\Entity\Restaurant;
use PHPUnit\Framework\TestCase;

/**
 * Trial state is purely computed from createdAt (see Restaurant::getTrialEndsAt())
 * — no DB round-trip needed here, just reflection to backdate createdAt on a
 * plain object, since it's normally only set by Doctrine's PrePersist callback.
 */
final class RestaurantTrialTest extends TestCase
{
    private function restaurantCreatedAt(\DateTimeImmutable $createdAt): Restaurant
    {
        $restaurant = new Restaurant();

        $property = new \ReflectionProperty(Restaurant::class, 'createdAt');
        $property->setAccessible(true);
        $property->setValue($restaurant, $createdAt);

        return $restaurant;
    }

    public function testTrialIsActiveOnSignupDayWithThirtyDaysRemaining(): void
    {
        $restaurant = $this->restaurantCreatedAt(new \DateTimeImmutable());

        self::assertTrue($restaurant->isTrialActive());
        self::assertSame(30, $restaurant->getTrialDaysRemaining());
    }

    public function testTrialIsActiveWithOneDayRemainingOnTheLastDay(): void
    {
        $restaurant = $this->restaurantCreatedAt(new \DateTimeImmutable('-29 days'));

        self::assertTrue($restaurant->isTrialActive());
        self::assertSame(1, $restaurant->getTrialDaysRemaining());
    }

    public function testTrialHasEndedThirtyOneDaysAfterSignup(): void
    {
        $restaurant = $this->restaurantCreatedAt(new \DateTimeImmutable('-31 days'));

        self::assertFalse($restaurant->isTrialActive());
        self::assertSame(0, $restaurant->getTrialDaysRemaining());
    }
}
