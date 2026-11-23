<?php

namespace App\Controller;

use App\Repository\RestaurantRepository;
use App\Service\AiAccess;
use App\Service\MenuPreferencesResolver;
use App\Service\SmartWaiterService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The only entry point into Smart Waiter. The restaurant is resolved here,
 * from the URL slug, exactly like MenuController::show() — never from
 * anything in the request body. That's what makes cross-restaurant data
 * leakage structurally impossible rather than something a prompt has to
 * prevent (see MenuContextBuilder's class docblock).
 *
 * Limits: 20 messages a minute per IP and restaurant (allowRequest()), and
 * the daily caps of rate_limiter.yaml — 40 a day per visitor (IP + browser)
 * and 500 a day per restaurant. Past a daily cap: 429 "daily_limit", which
 * the chat shows as its own sentence (chat.daily_limit, 20 languages).
 */
class SmartWaiterController extends AbstractController
{
    /** Also applied by app:smart-waiter:ask --modelos, so a comparison never answers what the chat would refuse. */
    public const MAX_MESSAGE_LENGTH = 800;
    private const RATE_LIMIT_PER_MINUTE = 20;

    #[Route('/r/{slug}/chat', name: 'menu_chat', methods: ['POST'])]
    public function chat(
        string $slug,
        Request $request,
        RestaurantRepository $restaurantRepo,
        SmartWaiterService $smartWaiterService,
        MenuPreferencesResolver $menuPreferencesResolver,
        CacheItemPoolInterface $cache,
        AiAccess $aiAccess,
        #[Autowire(service: 'limiter.smart_waiter_visitor_day')] RateLimiterFactoryInterface $visitorDayLimiter,
        #[Autowire(service: 'limiter.smart_waiter_restaurant_day')] RateLimiterFactoryInterface $restaurantDayLimiter,
    ): JsonResponse {
        $restaurant = $restaurantRepo->findOneBy(['slug' => $slug]);
        if (!$restaurant) {
            throw $this->createNotFoundException('Restaurante no encontrado.');
        }
        if (!$aiAccess->allows($restaurant, AiAccess::SMART_WAITER) || ($restaurant->isAwaitingEmailConfirmation() && !$this->isOwnTeam($restaurant))) {
            return $this->json(['error' => 'unavailable'], 503);
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

        // Daily caps (rate_limiter.yaml): this visitor's first, so one
        // visitor past its own limit doesn't use up the restaurant's.
        $visitor = sprintf('%d-%s', $restaurant->getId(), hash('sha256', ($request->getClientIp() ?? 'unknown') . '|' . $request->headers->get('User-Agent', '')));
        if (!$visitorDayLimiter->create($visitor)->consume()->isAccepted()
            || !$restaurantDayLimiter->create((string) $restaurant->getId())->consume()->isAccepted()) {
            return $this->json(['error' => 'daily_limit'], 429);
        }

        // Same locale resolution as the public menu itself (menu_prefs
        // cookie > device language > restaurant's own fallback), so the
        // assistant defaults to whatever language the page is already in.
        $savedPrefs = $menuPreferencesResolver->readCookie($request);
        $locale = $menuPreferencesResolver->isLanguageSupported($requestedLocale)
            ? $requestedLocale
            : ($savedPrefs['lang'] ?? $menuPreferencesResolver->detectLanguage($request, $restaurant->getDefaultLanguage()));

        // The currency the menu page itself is showing: the page sends the
        // one it was rendered with (MenuController's ?currency= override >
        // menu_prefs cookie > device locale > restaurant's own), so the
        // reply's prices and the dish cards under it (taken from that page)
        // always match. Not one we support (an old page, the console
        // command): the page's own resolution without the query override.
        $requestedCurrency = is_array($data) ? ($data['currency'] ?? null) : null;
        $currency = is_string($requestedCurrency) && $menuPreferencesResolver->isCurrencySupported($requestedCurrency)
            ? $requestedCurrency
            : ($savedPrefs['currency'] ?? $menuPreferencesResolver->guessCurrency($request, $restaurant->getCurrency()));

        $result = $smartWaiterService->reply($restaurant, $locale, $currency, $conversationId, $message);

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

    /** Logged in for this restaurant: may use Smart Waiter on its still-hidden menu (MenuController). */
    private function isOwnTeam(\App\Entity\Restaurant $restaurant): bool
    {
        $user = $this->getUser();

        return $user instanceof \App\Entity\User && $user->getRestaurant()?->getId() === $restaurant->getId();
    }
}
