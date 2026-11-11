<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\DishPhoto\DishPhotoAvailability;
use App\Service\DishPhoto\DishPhotoQuota;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * ai_photo_available(): false without GEMINI_IMAGE_API_KEY — the editor hides "Generar con IA".
 * ai_photo_trial_remaining(): AI dish photos left on the current restaurant's trial; null when it has no cap (paid plan) or no restaurant.
 */
class DishPhotoExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly DishPhotoQuota $quota,
        private readonly DishPhotoAvailability $availability,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ai_photo_available', $this->availability->isAvailable(...)),
            new TwigFunction('ai_photo_trial_remaining', $this->trialRemaining(...)),
        ];
    }

    public function trialRemaining(): ?int
    {
        $user = $this->security->getUser();
        $restaurant = $user instanceof User ? $user->getRestaurant() : null;

        return $restaurant !== null ? $this->quota->trialRemaining($restaurant) : null;
    }
}
