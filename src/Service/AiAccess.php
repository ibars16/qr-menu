<?php

namespace App\Service;

use App\Entity\Restaurant;
use App\Service\DishPhoto\DishPhotoQuota;

/**
 * The one rule for whether a restaurant may use the AI (dish photos,
 * "Mejorar con IA", menu import, Smart Waiter, translations, changing the
 * menu's main language). Asked by EventSubscriber\AiAccessGate for every
 * /admin action marked #[Attribute\RequiresAi], and directly by the few
 * places that aren't a whole action of their own: the public chat and
 * warm-translations, the background menu import and the main-language
 * change started from Configuración.
 *
 * - Up to date (Restaurant::isExpired() false, card-renewal grace
 *   included): everything.
 * - A reseller's restaurant awaiting its first payment ("Pendiente de
 *   pago"), not archived and its reseller up to date: only the menu import
 *   (its reseller or the super-admin, "como" the owner, is preparing the
 *   menu — the owner alone can't reach /admin then, SubscriptionGate): the
 *   upload, the background processing and translating that import into
 *   the main language ("Traducir al…", also IMPORT).
 * - Off but never paid (lastPaidAt null: trial over, "Pendiente de
 *   pago"), not archived and its reseller (if any) up to date: the free
 *   AI photos (generate and "Mejorar con IA", one shared allowance) while
 *   any of DishPhotoQuota::TRIAL_LIMIT are left. DishPhotoQuota::reserve()
 *   still counts each one.
 * - Anything else that's off (expired after paying, deactivated,
 *   archived, reseller expired): nothing, for anyone.
 */
final class AiAccess
{
    public const IMPORT          = 'import';
    public const PHOTOS          = 'photos';
    public const SMART_WAITER    = 'smart_waiter';
    public const TRANSLATIONS    = 'translations';
    public const LANGUAGE_CHANGE = 'language_change';

    public function __construct(
        private readonly DishPhotoQuota $photoQuota,
    ) {
    }

    public function allows(Restaurant $restaurant, string $feature): bool
    {
        if (!$restaurant->isExpired()) {
            return true;
        }
        if ($restaurant->isArchived() || $restaurant->getReseller()?->isExpired()) {
            return false;
        }

        return match ($feature) {
            self::IMPORT => $restaurant->isAwaitingFirstPayment(),
            self::PHOTOS => DishPhotoQuota::isLimited($restaurant) && $this->photoQuota->trialRemaining($restaurant) > 0,
            default      => false,
        };
    }
}
