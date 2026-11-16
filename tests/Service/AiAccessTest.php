<?php

namespace App\Tests\Service;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Service\AiAccess;
use App\Service\DishPhoto\DishPhotoQuota;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AiAccess, the one rule for the AI: everything while the restaurant is
 * on (card-renewal grace included); while off but never paid, the free AI
 * photos left (DishPhotoQuota, faked) and — "Pendiente de pago" — the menu
 * import; nothing at all otherwise.
 */
final class AiAccessTest extends TestCase
{
    private const FEATURES = [AiAccess::IMPORT, AiAccess::PHOTOS, AiAccess::SMART_WAITER, AiAccess::TRANSLATIONS, AiAccess::LANGUAGE_CHANGE];

    /** @return iterable<string, array{string}> */
    public static function features(): iterable
    {
        foreach (self::FEATURES as $feature) {
            yield $feature => [$feature];
        }
    }

    /** AiAccess with $left free AI photos left (DishPhotoQuota::trialRemaining(), no database). */
    private function access(?int $left = DishPhotoQuota::TRIAL_LIMIT): AiAccess
    {
        $quota = $this->createStub(DishPhotoQuota::class);
        $quota->method('trialRemaining')->willReturn($left);

        return new AiAccess($quota);
    }

    private static function paidUntil(Restaurant $restaurant, string $date): void
    {
        (new \ReflectionProperty(Restaurant::class, 'paidUntil'))->setValue($restaurant, new \DateTimeImmutable($date));
    }

    private static function paidReseller(): Reseller
    {
        $reseller = new Reseller('Ai Access Test Agency');
        $reseller->markPaid(Reseller::PLAN_MONTHLY);

        return $reseller;
    }

    /** "Pendiente de pago": a reseller's restaurant never paid, created off. */
    private static function awaitingFirstPayment(): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setReseller(self::paidReseller());
        $restaurant->deactivateNow();
        self::assertTrue($restaurant->isAwaitingFirstPayment());

        return $restaurant;
    }

    #[DataProvider('features')]
    public function testActiveRestaurantMayUseEverything(string $feature): void
    {
        self::assertTrue($this->access()->allows(new Restaurant(), $feature));
    }

    #[DataProvider('features')]
    public function testCardRenewalGraceKeepsEverything(string $feature): void
    {
        $restaurant = new Restaurant();
        $restaurant->markPaid(Restaurant::PLAN_MONTHLY);
        self::paidUntil($restaurant, 'today -2 days');
        $restaurant->setStripeSubscriptionId('sub_ai_access');
        self::assertTrue($restaurant->isInCardRenewalGrace());

        self::assertTrue($this->access()->allows($restaurant, $feature));
    }

    #[DataProvider('features')]
    public function testAwaitingFirstPaymentImportsAndKeepsItsFreePhotos(string $feature): void
    {
        self::assertSame(in_array($feature, [AiAccess::IMPORT, AiAccess::PHOTOS], true), $this->access()->allows(self::awaitingFirstPayment(), $feature));
    }

    public function testNeverPaidLosesThePhotosOnceTheFreeOnesAreUsed(): void
    {
        self::assertTrue($this->access(1)->allows(self::awaitingFirstPayment(), AiAccess::PHOTOS));
        self::assertFalse($this->access(0)->allows(self::awaitingFirstPayment(), AiAccess::PHOTOS));

        $trialOver = new Restaurant();
        $trialOver->deactivateNow();
        self::assertTrue($this->access(1)->allows($trialOver, AiAccess::PHOTOS));
        self::assertFalse($this->access(0)->allows($trialOver, AiAccess::PHOTOS));
    }

    /** Trial over (self-billed, never paid): only the free photos left, never the import. */
    #[DataProvider('features')]
    public function testTrialOverKeepsOnlyItsFreePhotos(string $feature): void
    {
        $restaurant = new Restaurant();
        $restaurant->deactivateNow();

        self::assertSame($feature === AiAccess::PHOTOS, $this->access()->allows($restaurant, $feature));
    }

    #[DataProvider('features')]
    public function testAwaitingFirstPaymentButArchivedOrResellerExpiredGetsNothing(string $feature): void
    {
        $archived = self::awaitingFirstPayment();
        $archived->archive();
        self::assertFalse($this->access()->allows($archived, $feature));

        $resellerExpired = self::awaitingFirstPayment();
        $resellerExpired->getReseller()->deactivateNow();
        self::assertFalse($this->access()->allows($resellerExpired, $feature));
    }

    #[DataProvider('features')]
    public function testExpiredAfterPayingGetsNothing(string $feature): void
    {
        $restaurant = new Restaurant();
        $restaurant->setReseller(self::paidReseller());
        $restaurant->markPaid(Restaurant::PLAN_MONTHLY);
        self::paidUntil($restaurant, 'today -10 days');
        self::assertTrue($restaurant->isExpired());

        self::assertFalse($this->access(null)->allows($restaurant, $feature));
    }

    /** Desactivar after paying: nothing, the photos included. */
    #[DataProvider('features')]
    public function testDeactivatedAfterPayingGetsNothing(string $feature): void
    {
        $restaurant = new Restaurant();
        $restaurant->markPaid(Restaurant::PLAN_MONTHLY);
        $restaurant->deactivateNow();

        self::assertFalse($this->access(null)->allows($restaurant, $feature));
    }
}
