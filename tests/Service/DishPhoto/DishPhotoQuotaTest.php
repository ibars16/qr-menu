<?php

namespace App\Tests\Service\DishPhoto;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Service\DishPhoto\DishPhotoQuota;
use App\Service\DishPhoto\DishPhotoQuotaResult;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The caps (atomic, in the DB) — 2 before the first payment, 150 a month after — and who each applies to. */
final class DishPhotoQuotaTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DishPhotoQuota $quota;
    /** @var object[] */
    private array $toRemove = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->quota = static::getContainer()->get(DishPhotoQuota::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->toRemove) as $entity) {
            $this->em->remove($this->em->find($entity::class, $entity->getId()));
        }
        $this->em->flush();
        parent::tearDown();
    }

    private function restaurant(?Reseller $reseller = null, bool $paid = false): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Quota Test');
        $restaurant->setSlug('quota-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $restaurant->setReseller($reseller);
        if ($paid) {
            $restaurant->markPaid(Restaurant::PLAN_MONTHLY);
        }
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->toRemove[] = $restaurant;

        return $restaurant;
    }

    public function testNotPaidReservesTwoThenRefusesAndRefundsGiveOneBack(): void
    {
        self::assertSame(2, DishPhotoQuota::TRIAL_LIMIT);
        $restaurant = $this->restaurant();

        self::assertSame(DishPhotoQuotaResult::Reserved, $this->quota->reserve($restaurant, 'a'));
        self::assertSame(DishPhotoQuotaResult::Reserved, $this->quota->reserve($restaurant, 'b'));
        self::assertSame(DishPhotoQuotaResult::TrialLimit, $this->quota->reserve($restaurant, 'x'));
        self::assertSame(0, $this->quota->trialRemaining($restaurant));

        $this->quota->refund($restaurant);
        self::assertSame(1, $this->quota->trialRemaining($restaurant));
        self::assertSame(DishPhotoQuotaResult::Reserved, $this->quota->reserve($restaurant, 'd'));
    }

    /** Point 1 of the review: back from Checkout during the trial (plan and subscription set, nothing charged). */
    public function testACardSetButNotChargedIsStillLimitedToTwo(): void
    {
        $restaurant = $this->restaurant();
        $restaurant->setPlan(Restaurant::PLAN_MONTHLY);
        $restaurant->setStripeSubscriptionId('sub_test_' . uniqid());
        $this->em->flush();

        self::assertTrue(DishPhotoQuota::isLimited($restaurant));
        self::assertTrue(DishPhotoQuota::isCardPending($restaurant));
        self::assertSame(2, $this->quota->trialRemaining($restaurant));
        $this->quota->reserve($restaurant, 'a');
        $this->quota->reserve($restaurant, 'b');
        self::assertSame(DishPhotoQuotaResult::CardPending, $this->quota->reserve($restaurant, 'x'));
    }

    /** A payment registered by hand in /platform or /partners counts as paid. */
    public function testAPaymentRegisteredByHandCountsAsPaid(): void
    {
        $restaurant = $this->restaurant();
        $restaurant->markPaid(Restaurant::PLAN_YEARLY);
        $this->em->flush();

        self::assertFalse(DishPhotoQuota::isLimited($restaurant));
        self::assertNull($this->quota->trialRemaining($restaurant));
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(DishPhotoQuotaResult::Reserved, $this->quota->reserve($restaurant, 'dish-' . $i));
        }
    }

    public function testAResellersRestaurantAwaitingPaymentIsLimitedToo(): void
    {
        $reseller = new Reseller('Quota Test Agency');
        $this->em->persist($reseller);
        $this->em->flush();
        $this->toRemove[] = $reseller;
        $restaurant = $this->restaurant($reseller);

        self::assertNull($restaurant->getPlan());
        self::assertTrue(DishPhotoQuota::isLimited($restaurant));
        self::assertSame(2, $this->quota->trialRemaining($restaurant));
        $this->quota->reserve($restaurant, 'a');
        $this->quota->reserve($restaurant, 'b');
        self::assertSame(DishPhotoQuotaResult::TrialLimit, $this->quota->reserve($restaurant, 'x'));
    }

    public function testPaidGets150AMonthAndTheFirstOfTheMonthRenewsIt(): void
    {
        self::assertSame(150, DishPhotoQuota::MONTHLY_LIMIT);
        $clock = new MockClock('2026-11-30 23:00:00');
        $quota = $this->quotaAt($clock);
        $restaurant = $this->restaurant(paid: true);
        // 149 of this month already used (not through reserve(): the 60/hour brake).
        $this->setMonth($restaurant, '2026-11-01', 149);

        self::assertFalse($quota->isMonthlyLimitReached($restaurant));
        self::assertSame(DishPhotoQuotaResult::Reserved, $quota->reserve($restaurant, 'a'));
        self::assertTrue($quota->isMonthlyLimitReached($restaurant));
        self::assertSame(DishPhotoQuotaResult::MonthlyLimit, $quota->reserve($restaurant, 'b'));
        self::assertSame('2026-12-01', $quota->nextRenewal()->format('Y-m-d'));

        // A failed photo gives this month's back.
        $quota->refund($restaurant);
        self::assertFalse($quota->isMonthlyLimitReached($restaurant));
        self::assertSame(DishPhotoQuotaResult::Reserved, $quota->reserve($restaurant, 'c'));

        $clock->modify('2026-12-01 00:30:00');
        self::assertFalse($quota->isMonthlyLimitReached($restaurant));
        self::assertSame(DishPhotoQuotaResult::Reserved, $quota->reserve($restaurant, 'd'));
        self::assertSame(['ai_photo_month' => '2026-12-01', 'ai_photo_month_count' => 1], $this->month($restaurant));
    }

    public function testTheMonthlyCapIsNotForTheUnpaid(): void
    {
        $restaurant = $this->restaurant();
        $this->setMonth($restaurant, date('Y-m-01'), DishPhotoQuota::MONTHLY_LIMIT);

        self::assertFalse($this->quota->isMonthlyLimitReached($restaurant));
        self::assertSame(DishPhotoQuotaResult::Reserved, $this->quota->reserve($restaurant, 'a'), 'only the trial cap counts');
    }

    public function testPaidHasNoTrialCapButStillCounts(): void
    {
        $restaurant = $this->restaurant(paid: true);

        for ($i = 0; $i < 5; $i++) {
            self::assertSame(DishPhotoQuotaResult::Reserved, $this->quota->reserve($restaurant, 'dish-' . $i));
        }
        self::assertNull($this->quota->trialRemaining($restaurant));
        self::assertSame(5, (int) $this->em->getConnection()->fetchOne('SELECT ai_photo_count FROM restaurant WHERE id = ?', [$restaurant->getId()]));
    }

    public function testTheRestaurantIsBrakedAfterSixtyAnHour(): void
    {
        $restaurant = $this->restaurant(paid: true);

        for ($i = 0; $i < 60; $i++) {
            self::assertSame(DishPhotoQuotaResult::Reserved, $this->quota->reserve($restaurant, 'dish-' . $i));
        }

        self::assertSame(DishPhotoQuotaResult::RateLimited, $this->quota->reserve($restaurant, 'dish-60'));
    }

    public function testRefundNeverGoesBelowZero(): void
    {
        $restaurant = $this->restaurant();
        $this->quota->refund($restaurant);

        self::assertSame(2, $this->quota->trialRemaining($restaurant));
        self::assertSame(0, $this->month($restaurant)['ai_photo_month_count']);
    }

    private function quotaAt(MockClock $clock): DishPhotoQuota
    {
        $container = static::getContainer();

        return new DishPhotoQuota(
            $container->get(Connection::class),
            $container->get('limiter.ai_photo_hour'),
            $container->get('limiter.ai_photo_day'),
            $container->get('limiter.ai_photo_dish'),
            $clock,
        );
    }

    private function setMonth(Restaurant $restaurant, string $month, int $count): void
    {
        $this->em->getConnection()->executeStatement('UPDATE restaurant SET ai_photo_month = ?, ai_photo_month_count = ? WHERE id = ?', [$month, $count, $restaurant->getId()]);
    }

    /** @return array{ai_photo_month: ?string, ai_photo_month_count: int} */
    private function month(Restaurant $restaurant): array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT ai_photo_month, ai_photo_month_count FROM restaurant WHERE id = ?', [$restaurant->getId()]);

        return ['ai_photo_month' => $row['ai_photo_month'], 'ai_photo_month_count' => (int) $row['ai_photo_month_count']];
    }
}
