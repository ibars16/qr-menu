<?php

namespace App\Service\DishPhoto;

use App\Entity\Restaurant;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Who may generate one more AI dish photo, reserved BEFORE calling the
 * provider (each generation costs, saved or not):
 *
 * - plan null (free trial, reseller's restaurant awaiting its first
 *   payment): TRIAL_LIMIT in total, ever. Reserved with one atomic UPDATE,
 *   so two tabs or a double click can never get past it; given back only
 *   when the provider gives no photo (refund()).
 * - everyone: the ai_photo_* rate limiters (60/hour, 300/day per
 *   restaurant, 10/hour per dish) against loops and repeated requests.
 *
 * ai_photo_count also counts paying restaurants' photos (one counter, no
 * branching); only the cap depends on the plan.
 */
class DishPhotoQuota
{
    public const TRIAL_LIMIT = 2;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'limiter.ai_photo_hour')] private readonly RateLimiterFactoryInterface $hourLimiter,
        #[Autowire(service: 'limiter.ai_photo_day')] private readonly RateLimiterFactoryInterface $dayLimiter,
        #[Autowire(service: 'limiter.ai_photo_dish')] private readonly RateLimiterFactoryInterface $dishLimiter,
    ) {
    }

    public static function isLimited(Restaurant $restaurant): bool
    {
        return $restaurant->getPlan() === null;
    }

    /** Photos left on the trial; null when there's no cap (paid plan). */
    public function trialRemaining(Restaurant $restaurant): ?int
    {
        if (!self::isLimited($restaurant)) {
            return null;
        }
        $used = (int) $this->connection->fetchOne('SELECT ai_photo_count FROM restaurant WHERE id = ?', [$restaurant->getId()]);

        return max(0, self::TRIAL_LIMIT - $used);
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

        $updated = $this->connection->executeStatement(
            'UPDATE restaurant SET ai_photo_count = ai_photo_count + 1 WHERE id = ? AND (plan IS NOT NULL OR ai_photo_count < ?)',
            [$restaurant->getId(), self::TRIAL_LIMIT],
        );

        return $updated === 1 ? DishPhotoQuotaResult::Reserved : DishPhotoQuotaResult::TrialLimit;
    }

    /** The provider gave no photo: this one doesn't count. */
    public function refund(Restaurant $restaurant): void
    {
        $this->connection->executeStatement(
            'UPDATE restaurant SET ai_photo_count = ai_photo_count - 1 WHERE id = ? AND ai_photo_count > 0',
            [$restaurant->getId()],
        );
    }
}
