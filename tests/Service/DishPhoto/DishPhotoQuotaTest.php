<?php

namespace App\Tests\Service\DishPhoto;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Service\DishPhoto\DishPhotoQuota;
use App\Service\DishPhoto\DishPhotoQuotaResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The trial cap (atomic, in the DB) and who it applies to. */
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

    public function testTrialReservesTwoThenRefusesAndRefundsGiveOneBack(): void
    {
        $restaurant = $this->restaurant();

        self::assertSame(DishPhotoQuotaResult::Reserved, $this->quota->reserve($restaurant, 'a'));
        self::assertSame(DishPhotoQuotaResult::Reserved, $this->quota->reserve($restaurant, 'b'));
        self::assertSame(DishPhotoQuotaResult::TrialLimit, $this->quota->reserve($restaurant, 'c'));
        self::assertSame(0, $this->quota->trialRemaining($restaurant));

        $this->quota->refund($restaurant);
        self::assertSame(1, $this->quota->trialRemaining($restaurant));
        self::assertSame(DishPhotoQuotaResult::Reserved, $this->quota->reserve($restaurant, 'd'));
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
        self::assertSame(DishPhotoQuotaResult::TrialLimit, $this->quota->reserve($restaurant, 'c'));
    }

    public function testAPaidPlanHasNoCapButStillCounts(): void
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
    }
}
