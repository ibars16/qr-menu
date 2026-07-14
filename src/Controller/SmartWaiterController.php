<?php

namespace App\Controller;

use App\Repository\RestaurantRepository;
use App\Service\MenuPreferencesResolver;
use App\Service\SmartWaiterService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The only entry point into Smart Waiter. The restaurant is resolved here,
 * from the URL slug, exactly like MenuController::show() — never from
 * anything in the request body. That's what makes cross-restaurant data
 * leakage structurally impossible rather than something a prompt has to
 * prevent (see MenuContextBuilder's class docblock).
 */
class SmartWaiterController extends AbstractController
{
    private const MAX_MESSAGE_LENGTH = 800;
    private const RATE_LIMIT_PER_MINUTE = 20;

    #[Route('/r/{slug}/chat', name: 'menu_chat', methods: ['POST'])]
    public function chat(
        string $slug,
        Request $request,
        RestaurantRepository $restaurantRepo,
        SmartWaiterService $smartWaiterService,
        MenuPreferencesResolver $menuPreferencesResolver,
        CacheItemPoolInterface $cache,
    ): JsonResponse {
        $restaurant = $restaurantRepo->findOneBy(['slug' => $slug]);
        if (!$restaurant) {
            throw $this->createNotFoundException('Restaurante no encontrado.');
        }

        if (!$this->allowRequest($cache, $restaurant->getId(), $request->getClientIp() ?? 'unknown')) {
            return $this->json(['error' => 'rate_limited'], 429);
        }

        $data = json_decode($request->getContent(), true);
        $message = is_array($data) ? trim((string) ($data['message'] ?? '')) : '';
        $conversationId = is_array($data) && is_string($data['conversationId'] ?? null) ? $data['conversationId'] : null;
        $requestedLocale = is_array($data) ? ($data['locale'] ?? null) : null;

        if ($message === '') {
            return $this->json(['error' => 'empty_message'], 400);
        }
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return $this->json(['error' => 'message_too_long'], 400);
        }

        // Same locale resolution as the public menu itself (menu_prefs
        // cookie > device language > restaurant's own fallback), so the
        // assistant defaults to whatever language the page is already in.
        $savedPrefs = $menuPreferencesResolver->readCookie($request);
        $locale = $menuPreferencesResolver->isLanguageSupported($requestedLocale)
            ? $requestedLocale
            : ($savedPrefs['lang'] ?? $menuPreferencesResolver->detectLanguage($request, $restaurant->getDefaultLanguage()));

        $result = $smartWaiterService->reply($restaurant, $locale, $conversationId, $message);

        return $this->json($result);
    }

    private function allowRequest(CacheItemPoolInterface $cache, int $restaurantId, string $ip): bool
    {
        $safeIp = preg_replace('/[^a-zA-Z0-9]/', '', $ip);
        $item = $cache->getItem(sprintf('smart_waiter_rl_%d_%s', $restaurantId, $safeIp));

        $count = $item->isHit() ? (int) $item->get() : 0;
        if ($count >= self::RATE_LIMIT_PER_MINUTE) {
            return false;
        }

        $item->set($count + 1);
        $item->expiresAfter(60);
        $cache->save($item);

        return true;
    }
}
