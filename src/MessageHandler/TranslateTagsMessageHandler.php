<?php

namespace App\MessageHandler;

use App\Message\TranslateTagsMessage;
use App\Repository\RestaurantRepository;
use App\Service\AiTagTranslator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TranslateTagsMessageHandler
{
    public function __construct(
        private readonly RestaurantRepository $restaurantRepo,
        private readonly AiTagTranslator      $translator,
    ) {}

    public function __invoke(TranslateTagsMessage $message): void
    {
        $restaurant = $this->restaurantRepo->find($message->restaurantId);

        if ($restaurant === null) {
            return;
        }

        $this->translator->translateMissing($restaurant, $message->locale);
    }
}
