<?php

namespace App\Service\DishPhoto;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The owner-facing text of a DishPhotoException reason (ai_photo.error.<reason>,
 * admin_menu domain), in the current request's panel language. The monthly
 * limit's says when it renews ("Se renueva el 1 de diciembre"): the month
 * name comes from ai_photo.months.<1-12> (no intl extension here).
 */
class DishPhotoMessages
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly DishPhotoQuota $quota,
    ) {
    }

    public function forReason(string $reason): string
    {
        $params = ['%limit%' => DishPhotoQuota::TRIAL_LIMIT];
        if ($reason === DishPhotoException::REASON_MONTHLY_LIMIT) {
            $month = (int) $this->quota->nextRenewal()->format('n');
            $params['%month%'] = $this->translator->trans('ai_photo.months.' . $month, [], 'admin_menu');
        }

        return $this->translator->trans('ai_photo.error.' . $reason, $params, 'admin_menu');
    }
}
