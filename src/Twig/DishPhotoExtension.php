<?php

namespace App\Twig;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\DishPhoto\DishPhotoAvailability;
use App\Service\DishPhoto\DishPhotoException;
use App\Service\DishPhoto\DishPhotoMessages;
use App\Service\DishPhoto\DishPhotoQuota;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * ai_photo_available(): false without GEMINI_IMAGE_API_KEY — the editor hides "Generar con IA".
 * ai_photo_trial_remaining(): AI dish photos left before the current restaurant's first payment; null once it has paid (no counter shown) or no restaurant.
 * ai_photo_trial_limit(): those photos in total (DishPhotoQuota::TRIAL_LIMIT, the one place it's set).
 * ai_photo_card_pending(): not paid yet but a card is set — "Tendrás más fotos cuando empiece tu plan", never "Elige un plan".
 * ai_photo_monthly_limit_message(): paid and this month's photos used up: "…Se renueva el 1 de <mes>."; null otherwise.
 */
class DishPhotoExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly DishPhotoQuota $quota,
        private readonly DishPhotoAvailability $availability,
        private readonly DishPhotoMessages $messages,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ai_photo_available', $this->availability->isAvailable(...)),
            new TwigFunction('ai_photo_trial_remaining', $this->trialRemaining(...)),
            new TwigFunction('ai_photo_trial_limit', static fn (): int => DishPhotoQuota::TRIAL_LIMIT),
            new TwigFunction('ai_photo_card_pending', $this->cardPending(...)),
            new TwigFunction('ai_photo_monthly_limit_message', $this->monthlyLimitMessage(...)),
        ];
    }

    public function trialRemaining(): ?int
    {
        $restaurant = $this->restaurant();

        return $restaurant !== null ? $this->quota->trialRemaining($restaurant) : null;
    }

    public function cardPending(): bool
    {
        $restaurant = $this->restaurant();

        return $restaurant !== null && DishPhotoQuota::isCardPending($restaurant);
    }

    public function monthlyLimitMessage(): ?string
    {
        $restaurant = $this->restaurant();

        return $restaurant !== null && $this->quota->isMonthlyLimitReached($restaurant)
            ? $this->messages->forReason(DishPhotoException::REASON_MONTHLY_LIMIT)
            : null;
    }

    private function restaurant(): ?Restaurant
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getRestaurant() : null;
    }
}
