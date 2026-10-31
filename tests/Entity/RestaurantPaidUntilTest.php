<?php

namespace App\Tests\Entity;

use App\Entity\Restaurant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Restaurant::$paidUntil is the only thing that decides whether a restaurant
 * is active — expired = paidUntil before today, paidUntil itself included.
 */
final class RestaurantPaidUntilTest extends TestCase
{
    private function restaurantPaidUntil(string $date): Restaurant
    {
        $restaurant = new Restaurant();
        (new \ReflectionProperty(Restaurant::class, 'paidUntil'))
            ->setValue($restaurant, new \DateTimeImmutable($date));

        return $restaurant;
    }

    public function testNewRestaurantIsPaidUntilTodayPlusThirtyDays(): void
    {
        $restaurant = new Restaurant();

        self::assertSame((new \DateTimeImmutable('today +30 days'))->format('Y-m-d'), $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertFalse($restaurant->isExpired());
    }

    public function testExpiryIsInclusiveOfPaidUntil(): void
    {
        $today = new \DateTimeImmutable('2026-10-28');

        self::assertFalse($this->restaurantPaidUntil('2026-10-28')->isExpired($today));
        self::assertTrue($this->restaurantPaidUntil('2026-10-27')->isExpired($today));
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function markPaidCases(): iterable
    {
        yield '+1 mes, still active: extends from paidUntil' => ['monthly', '2026-11-15', '2026-10-28', '2026-12-15'];
        yield '+1 mes, expired: extends from today'          => ['monthly', '2026-09-01', '2026-10-28', '2026-11-28'];
        yield '+1 mes, paidUntil today'                      => ['monthly', '2026-10-28', '2026-10-28', '2026-11-28'];
        yield '+1 mes, 31/01 clamps to 28/02'                => ['monthly', '2027-01-31', '2027-01-10', '2027-02-28'];
        yield '+1 mes, 31/01 clamps to 29/02 in a leap year' => ['monthly', '2028-01-31', '2028-01-10', '2028-02-29'];
        yield '+1 mes, 31/03 clamps to 30/04'                => ['monthly', '2027-03-31', '2027-03-01', '2027-04-30'];
        yield '+1 mes, december rolls over the year'         => ['monthly', '2026-12-31', '2026-12-01', '2027-01-31'];
        yield '+1 año, still active: extends from paidUntil' => ['yearly', '2026-11-15', '2026-10-28', '2027-11-15'];
        yield '+1 año, expired: extends from today'          => ['yearly', '2026-09-01', '2026-10-28', '2027-10-28'];
        yield '+1 año, 29/02 clamps to 28/02'                => ['yearly', '2028-02-29', '2028-02-01', '2029-02-28'];
        yield '+1 año, 28/02 into a leap year stays 28/02'   => ['yearly', '2027-02-28', '2027-02-01', '2028-02-28'];
    }

    #[DataProvider('markPaidCases')]
    public function testMarkPaidAddsClampedPeriod(string $plan, string $paidUntil, string $today, string $expected): void
    {
        $restaurant = $this->restaurantPaidUntil($paidUntil);
        self::assertSame($expected, $restaurant->paidUntilAfter($plan, new \DateTimeImmutable($today))->format('Y-m-d'), 'preview matches');

        $restaurant->markPaid($plan, new \DateTimeImmutable($today));

        self::assertSame($expected, $restaurant->getPaidUntil()->format('Y-m-d'));
    }

    public function testMarkPaidRecordsPlanAndPaymentDay(): void
    {
        $today = new \DateTimeImmutable('2026-10-28');
        $restaurant = $this->restaurantPaidUntil('2026-11-15');

        $restaurant->markPaid(Restaurant::PLAN_YEARLY, $today);

        self::assertSame(Restaurant::PLAN_YEARLY, $restaurant->getPlan());
        self::assertSame('2026-10-28', $restaurant->getLastPaidAt()->format('Y-m-d'));
    }

    public function testMarkPaidRejectsUnknownPlan(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Restaurant())->markPaid('weekly');
    }

    public function testDeactivateNowKeepsPlan(): void
    {
        $today = new \DateTimeImmutable('2026-10-28');
        $restaurant = $this->restaurantPaidUntil('2026-10-01');
        $restaurant->markPaid(Restaurant::PLAN_MONTHLY, $today);

        $restaurant->deactivateNow($today);

        self::assertSame(Restaurant::PLAN_MONTHLY, $restaurant->getPlan());
        self::assertSame('2026-10-28', $restaurant->getLastPaidAt()->format('Y-m-d'));
    }

    public function testDaysLeft(): void
    {
        $today = new \DateTimeImmutable('2026-10-28');

        self::assertSame(3, $this->restaurantPaidUntil('2026-10-31')->getDaysLeft($today));
        self::assertSame(0, $this->restaurantPaidUntil('2026-10-28')->getDaysLeft($today));
        self::assertSame(-2, $this->restaurantPaidUntil('2026-10-26')->getDaysLeft($today));
    }

    public function testStripePeriodOnlyMovesDatesForward(): void
    {
        $restaurant = $this->restaurantPaidUntil('2027-03-10');

        $restaurant->applyStripePeriod(new \DateTimeImmutable('2027-02-10'), Restaurant::PLAN_MONTHLY, new \DateTimeImmutable('2027-02-01'));
        self::assertSame('2027-03-10', $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertSame('2027-02-01', $restaurant->getLastPaidAt()->format('Y-m-d'));

        $restaurant->applyStripePeriod(new \DateTimeImmutable('2027-04-10'), Restaurant::PLAN_MONTHLY, new \DateTimeImmutable('2027-01-01'));
        self::assertSame('2027-04-10', $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertSame('2027-02-01', $restaurant->getLastPaidAt()->format('Y-m-d'), 'older payment date ignored');
    }

    public function testStripePeriodWithoutPaymentKeepsLastPaidAtEmpty(): void
    {
        $restaurant = $this->restaurantPaidUntil('2027-03-10');

        $restaurant->applyStripePeriod(new \DateTimeImmutable('2027-03-11'), Restaurant::PLAN_YEARLY, null);

        self::assertSame(Restaurant::PLAN_YEARLY, $restaurant->getPlan());
        self::assertNull($restaurant->getLastPaidAt());
    }

    public function testDeactivateNowSetsYesterdayAndExpires(): void
    {
        $today = new \DateTimeImmutable('2026-10-28');
        $restaurant = $this->restaurantPaidUntil('2027-05-01');

        $restaurant->deactivateNow($today);

        self::assertSame('2026-10-27', $restaurant->getPaidUntil()->format('Y-m-d'));
        self::assertTrue($restaurant->isExpired($today));
    }
}
