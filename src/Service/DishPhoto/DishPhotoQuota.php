<?php

namespace App\Service\DishPhoto;

use App\Entity\Restaurant;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Who may generate one more AI dish photo, reserved BEFORE calling the
 * provider (each generation costs, saved or not):
 *
 * - not paid yet (lastPaidAt null: free trial, card set but Stripe hasn't
 *   charged anything yet, reseller's restaurant awaiting its first
 *   payment): TRIAL_LIMIT in total, ever. Setting a card doesn't lift it —
 *   only a real payment (a Stripe invoice over 0 €, or one registered by
 *   hand in /platform or /partners) does.
 * - paid at least once: MONTHLY_LIMIT per calendar month; the 1st starts
 *   a new count.
 * - everyone: the ai_photo_* rate limiters (60/hour, 300/day per
 *   restaurant, 10/hour per dish) against loops and repeated requests.
 *
 * Both caps are checked and counted in ONE atomic UPDATE, so two tabs or
 * a double click can never get past them; given back only when the
 * provider gives no photo (refund()).
 *
 * ai_photo_count counts every photo ever (the trial cap reads it);
 * ai_photo_month/ai_photo_month_count the current month's (the paid cap).
 */
class DishPhotoQuota
{
    /** AI photos a restaurant gets before its first payment, in total (editor and every import together). The only place it's set. */
    public const TRIAL_LIMIT = 2;

    /** AI photos a month once paid (about 5 € of image generation). The only place it's set. */
    public const MONTHLY_LIMIT = 150;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'limiter.ai_photo_hour')] private readonly RateLimiterFactoryInterface $hourLimiter,
        #[Autowire(service: 'limiter.ai_photo_day')] private readonly RateLimiterFactoryInterface $dayLimiter,
        #[Autowire(service: 'limiter.ai_photo_dish')] private readonly RateLimiterFactoryInterface $dishLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Not paid yet: the TRIAL_LIMIT applies (and the "Te quedan X de 2" counter shows). */
    public static function isLimited(Restaurant $restaurant): bool
    {
        return $restaurant->getLastPaidAt() === null;
    }

    /** Not paid yet but a card is set (its plan starts later): never "Elige un plan", but "Tendrás más fotos cuando empiece tu plan". */
    public static function isCardPending(Restaurant $restaurant): bool
    {
        return self::isLimited($restaurant) && $restaurant->hasLiveSubscription();
    }

    /** Photos left before the first payment; null when it has paid (no visible counter then). */
    public function trialRemaining(Restaurant $restaurant): ?int
    {
        if (!self::isLimited($restaurant)) {
            return null;
        }
        $used = (int) $this->connection->fetchOne('SELECT ai_photo_count FROM restaurant WHERE id = ?', [$restaurant->getId()]);

        return max(0, self::TRIAL_LIMIT - $used);
    }

    /** Paid and this month's MONTHLY_LIMIT already used. */
    public function isMonthlyLimitReached(Restaurant $restaurant): bool
    {
        if (self::isLimited($restaurant)) {
            return false;
        }
        $row = $this->connection->fetchAssociative('SELECT ai_photo_month, ai_photo_month_count FROM restaurant WHERE id = ?', [$restaurant->getId()]);

        return $row !== false
            && $row['ai_photo_month'] === $this->currentMonth()
            && (int) $row['ai_photo_month_count'] >= self::MONTHLY_LIMIT;
    }

    /** The 1st of next month: when a reached monthly limit renews. */
    public function nextRenewal(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->currentMonth() . ' +1 month');
    }

    /**
     * $dishKey: the product id, or for a dish not saved yet something stable
     * from its name — the per-dish brake.
     */
    public function reserve(Restaurant $restaurant, string $dishKey): DishPhotoQuotaResult
    {
        $id = (string) $restaurant->getId();
        // Dish first: hammering one dish shouldn't eat the restaurant's hourly budget.
        if (!$this->dishLimiter->create($id . ':' . $dishKey)->consume()->isAccepted()
            || !$this->hourLimiter->create($id)->consume()->isAccepted()
            || !$this->dayLimiter->create($id)->consume()->isAccepted()) {
            return DishPhotoQuotaResult::RateLimited;
        }

        // Not paid: ai_photo_count below the trial cap. Paid: a new month,
        // or this month's count below its cap. Both counters move together.
        $updated = $this->connection->executeStatement(
            'UPDATE restaurant SET
                ai_photo_count = ai_photo_count + 1,
                ai_photo_month_count = CASE WHEN ai_photo_month = :month THEN ai_photo_month_count + 1 ELSE 1 END,
                ai_photo_month = :month
             WHERE id = :id AND (
                (last_paid_at IS NULL AND ai_photo_count < :trial)
                OR (last_paid_at IS NOT NULL AND (ai_photo_month IS DISTINCT FROM :month OR ai_photo_month_count < :monthly))
             )',
            ['id' => $restaurant->getId(), 'month' => $this->currentMonth(), 'trial' => self::TRIAL_LIMIT, 'monthly' => self::MONTHLY_LIMIT],
        );
        if ($updated === 1) {
            return DishPhotoQuotaResult::Reserved;
        }

        return match (true) {
            self::isCardPending($restaurant) => DishPhotoQuotaResult::CardPending,
            self::isLimited($restaurant) => DishPhotoQuotaResult::TrialLimit,
            default => DishPhotoQuotaResult::MonthlyLimit,
        };
    }

    /** The provider gave no photo: this one doesn't count. */
    public function refund(Restaurant $restaurant): void
    {
        $this->connection->executeStatement(
            'UPDATE restaurant SET
                ai_photo_count = GREATEST(ai_photo_count - 1, 0),
                ai_photo_month_count = CASE WHEN ai_photo_month = :month THEN GREATEST(ai_photo_month_count - 1, 0) ELSE ai_photo_month_count END
             WHERE id = :id',
            ['id' => $restaurant->getId(), 'month' => $this->currentMonth()],
        );
    }

    /** 'Y-m-01' of today's month (the app's timezone). */
    private function currentMonth(): string
    {
        return $this->clock->now()->format('Y-m-01');
    }
}
