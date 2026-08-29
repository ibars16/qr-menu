<?php

namespace App\MessageHandler;

use App\Message\TranslateTagsMessage;
use App\Repository\RestaurantRepository;
use App\Service\AiTagTranslator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TranslateTagsMessageHandler
{
    public function __construct(
        private readonly RestaurantRepository $restaurantRepo,
        private readonly AiTagTranslator      $translator,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(TranslateTagsMessage $message): void
    {
        $restaurant = $this->restaurantRepo->find($message->restaurantId);

        if ($restaurant === null) {
            return;
        }

        $this->translator->translateMissing($restaurant, $message->locale);

        // This runs in a background worker, strictly after the customer
        // request that dispatched it already returned (and may already have
        // cached the pre-translation fallback name) — see MenuController's
        // menu-content cache. Without this bump, the new translation would
        // never become visible until some unrelated admin edit happened to
        // bump the version for another reason.
        $restaurant->bumpMenuContentVersion();
        $this->em->flush();
    }
}
