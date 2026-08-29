<?php

namespace App\Controller;

use App\Entity\ProductView;
use App\Enum\AllergenPresence;
use App\Repository\ProductRepository;
use App\Repository\ProductViewRepository;
use App\Repository\RestaurantRepository;
use App\Service\CategoryTranslationService;
use App\Service\CategoryTypeFilterResolver;
use App\Service\CurrencyConverter;
use App\Service\MenuPreferencesResolver;
use App\Service\ProductAllergenResolver;
use App\Service\ProductTranslationService;
use App\Service\TagTranslationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class MenuController extends AbstractController
{
    private const VIEW_RATE_LIMIT_PER_MINUTE = 60;

    // "Popular" badge (see renderMenu()): only the very top dishes, and only
    // once there's enough real traffic to mean something — a 2-view badge on
    // a brand-new restaurant would just look fake.
    private const POPULAR_BADGE_LIMIT = 3;
    private const POPULAR_BADGE_MIN_VIEWS = 10;

    /**
     * True whenever this request must never read or write the menu-content
     * cache — the owner's own live layout/theme preview, the one path where
     * "always exactly current" matters more than speed. Bypasses on the
     * mere presence of either query param, not just when preview actually
     * activates (the auth check for that happens later): preview traffic is
     * rare and always admin-only, so erring toward "don't cache" here costs
     * nothing.
     */
    private function shouldBypassMenuCache(Request $request): bool
    {
        return $request->query->has('preview_layout') || $request->query->has('preview_theme');
    }

    /**
     * Shared prefix for every menu-content cache entry this request might
     * read or write (see ProductRepository::warmMenuCollections(),
     * CategoryRepository/ProductTagRepository::warmTranslations(),
     * ProductAllergenResolver::resolveForRestaurant()) — each of those
     * appends its own suffix. Folding Restaurant::$menuContentVersion in
     * here is the entire invalidation mechanism: bumping it changes every
     * key built from it, so old entries are simply never read again rather
     * than needing explicit deletion.
     */
    private function menuCacheKeyPrefix(\App\Entity\Restaurant $restaurant, string $locale): string
    {
        return sprintf('menu_v%d_r%d_%s', $restaurant->getMenuContentVersion(), $restaurant->getId(), $locale);
    }

    #[Route('/r/{slug}', name: 'menu_show')]
    public function show(
        string $slug,
        RestaurantRepository $restaurantRepo,
        EntityManagerInterface $em,
        Request $request,
        TagTranslationService $tagTranslationService,
        ProductTranslationService $productTranslationService,
        CategoryTranslationService $categoryTranslationService,
        ProductRepository $productRepo,
        ProductViewRepository $productViewRepo,
        MenuPreferencesResolver $menuPreferencesResolver,
        ProductAllergenResolver $allergenResolver,
        CategoryTypeFilterResolver $categoryTypeFilterResolver,
        TranslatorInterface $translator,
    ): Response {
        $restaurant = $restaurantRepo->findOneBy(['slug' => $slug]);
        if (!$restaurant) {
            throw $this->createNotFoundException('Restaurante no encontrado.');
        }

        return $this->renderMenu($restaurant, $request, $em, $tagTranslationService, $productTranslationService, $categoryTranslationService, $productRepo, $productViewRepo, $menuPreferencesResolver, $allergenResolver, $categoryTypeFilterResolver, $translator);
    }

    // Backwards-compat redirect for QR codes already printed with the old table URL.
    #[Route('/r/{slug}/table/{qrToken}', name: 'menu_show_table')]
    public function showTable(string $slug): Response
    {
        return $this->redirectToRoute('menu_show', ['slug' => $slug], 301);
    }

    /**
     * Called by the loading screen's JS (see menu/loading.html.twig) to do
     * the actual translation work off the main navigation, so a slow/first
     * AI call never blocks the page load — it animates a spinner instead.
     * Synchronous itself (mirrors ProductTranslationService's reasoning:
     * each dish+locale pair is translated once, ever), it just runs from a
     * background fetch instead of from the page request.
     */
    #[Route('/r/{slug}/warm-translations', name: 'menu_warm_translations', methods: ['POST'])]
    public function warmTranslations(
        string $slug,
        Request $request,
        RestaurantRepository $restaurantRepo,
        ProductRepository $productRepo,
        ProductTranslationService $productTranslationService,
        CategoryTranslationService $categoryTranslationService,
        MenuPreferencesResolver $menuPreferencesResolver,
    ): JsonResponse {
        $restaurant = $restaurantRepo->findOneBy(['slug' => $slug]);
        if (!$restaurant) {
            return $this->json(['ok' => false], 404);
        }

        $locale = $request->query->get('lang', '');
        if (!$menuPreferencesResolver->isLanguageSupported($locale)) {
            return $this->json(['ok' => false], 400);
        }

        $productTranslationService->resolveForMenu(
            $restaurant,
            $productRepo->findActiveForRestaurant($restaurant),
            $locale
        );

        $categoryTranslationService->resolveForMenu(
            $restaurant,
            $restaurant->getCategories()->filter(fn($c) => $c->isActive())->toArray(),
            $locale
        );

        return $this->json(['ok' => true]);
    }

    /**
     * Fires from the menu's own JS whenever a customer opens a dish's detail
     * (modal on standard, flip on compact/grid) — see menu/_search_js.html.twig's
     * trackDishView(). Fire-and-forget from the client's side, so this never
     * needs to report anything but ok/error.
     */
    #[Route('/r/{slug}/view', name: 'menu_track_view', methods: ['POST'])]
    public function trackView(
        string $slug,
        Request $request,
        RestaurantRepository $restaurantRepo,
        ProductRepository $productRepo,
        EntityManagerInterface $em,
        CacheItemPoolInterface $cache,
    ): JsonResponse {
        $restaurant = $restaurantRepo->findOneBy(['slug' => $slug]);
        if (!$restaurant) {
            return $this->json(['ok' => false], 404);
        }

        if (!$this->allowViewTracking($cache, $restaurant->getId(), $request->getClientIp() ?? '')) {
            return $this->json(['error' => 'rate_limited'], 429);
        }

        $data = json_decode($request->getContent(), true);
        $productId = $data['productId'] ?? null;
        if (!is_int($productId) && !ctype_digit((string) $productId)) {
            return $this->json(['ok' => false], 400);
        }

        $product = $productRepo->find((int) $productId);
        // Client-supplied id: must belong to this restaurant, never trust it
        // blindly, or one restaurant's traffic could write view rows against
        // another restaurant's product.
        if (!$product || $product->getCategory()->getRestaurant() !== $restaurant) {
            return $this->json(['ok' => false], 404);
        }

        $em->persist(new ProductView($restaurant, $product));
        $em->flush();

        return $this->json(['ok' => true]);
    }

    // Same cache-counter idiom as SmartWaiterController::allowRequest — no
    // rate-limiter component used anywhere in this app, deliberately kept as
    // a small per-controller helper rather than a shared abstraction. Views
    // fire far more often per visitor than chat messages, hence the higher
    // ceiling than Smart Waiter's 20/min.
    private function allowViewTracking(CacheItemPoolInterface $cache, int $restaurantId, string $ip): bool
    {
        $safeIp = preg_replace('/[^a-zA-Z0-9]/', '', $ip);
        $item = $cache->getItem(sprintf('product_view_rl_%d_%s', $restaurantId, $safeIp));

        $count = $item->isHit() ? (int) $item->get() : 0;
        if ($count >= self::VIEW_RATE_LIMIT_PER_MINUTE) {
            return false;
        }

        $item->set($count + 1);
        $item->expiresAfter(60);
        $cache->save($item);

        return true;
    }

    private function renderMenu(
        \App\Entity\Restaurant $restaurant,
        Request $request,
        EntityManagerInterface $em,
        TagTranslationService $tagTranslationService,
        ProductTranslationService $productTranslationService,
        CategoryTranslationService $categoryTranslationService,
        ProductRepository $productRepo,
        ProductViewRepository $productViewRepo,
        MenuPreferencesResolver $menuPreferencesResolver,
        ProductAllergenResolver $allergenResolver,
        CategoryTypeFilterResolver $categoryTypeFilterResolver,
        TranslatorInterface $translator,
    ): Response {
        $languages  = $menuPreferencesResolver->getLanguages();
        $currencies = $menuPreferencesResolver->getCurrencies();

        // Each customer's own preference, remembered client-side (cookie) from
        // a previous visit — never the restaurant's own settings.
        $savedPrefs = $menuPreferencesResolver->readCookie($request);

        // Language: explicit ?lang= override > saved preference > device
        // language (if supported) > restaurant's own fallback language.
        $queryLang = $request->query->get('lang');
        $locale    = $menuPreferencesResolver->isLanguageSupported($queryLang)
            ? $queryLang
            : ($savedPrefs['lang'] ?? $menuPreferencesResolver->detectLanguage($request, $restaurant->getDefaultLanguage()));

        // null on the owner's own live preview request — every cache read/
        // write below is skipped entirely in that case (see
        // shouldBypassMenuCache()'s own docblock).
        $cacheKeyPrefix = $this->shouldBypassMenuCache($request)
            ? null
            : $this->menuCacheKeyPrefix($restaurant, $locale);

        // Computed early (rather than at its original spot further down)
        // so the loading-screen check below and the main render can both
        // use it without querying twice.
        $activeCategoriesForCheck = $restaurant->getCategories()
            ->filter(fn($c) => $c->isActive())
            ->toArray();

        // If this restaurant has never served $locale before, translating it
        // (see ProductTranslationService) takes a few real seconds — instead
        // of blocking this navigation on that first-ever visit, detour once
        // through a loading screen that fires the same translation off in
        // the background and redirects back here when it's done (?mtw=1
        // marks that this page already took that detour, so a slow/failed
        // attempt can only ever cause one extra hop, never a redirect loop —
        // the retried load below just renders with whatever's cached).
        if ($request->query->get('mtw') !== '1'
            && $locale !== $restaurant->getDefaultLanguage()
            && ($productRepo->hasAnyMissingTranslation($restaurant, $locale)
                || $categoryTranslationService->hasAnyMissing($restaurant, $activeCategoriesForCheck, $locale, $cacheKeyPrefix))
        ) {
            $nextParams        = $request->query->all();
            $nextParams['mtw'] = '1';

            // Only ~20 of the 60 public menu languages have a "loading"
            // string translated (translations/menu_public.*.yaml) — same
            // coverage level as the rest of the public menu chrome. Falls
            // back to English rather than an untranslated message id.
            $loadingText = $translator->getCatalogue($locale)->has('loading.title', 'menu_public')
                ? $translator->trans('loading.title', [], 'menu_public', $locale)
                : $translator->trans('loading.title', [], 'menu_public', 'en');

            return $this->render('menu/loading.html.twig', [
                'restaurant'   => $restaurant,
                'locale'       => $locale,
                'loading_text' => $loadingText,
                'nextUrl'      => $request->getPathInfo() . '?' . http_build_query($nextParams),
                'warmUrl'      => $this->generateUrl('menu_warm_translations', ['slug' => $restaurant->getSlug()]) . '?' . http_build_query(['lang' => $locale]),
            ]);
        }

        // Currency: explicit ?currency= override > saved preference > guessed
        // from the device's locale (if supported) > restaurant's own currency.
        $queryCurrency = $request->query->get('currency');
        $currency      = $menuPreferencesResolver->isCurrencySupported($queryCurrency)
            ? $queryCurrency
            : ($savedPrefs['currency'] ?? $menuPreferencesResolver->guessCurrency($request, $restaurant->getCurrency()));
        $currencyDisplay = $menuPreferencesResolver->getCurrencyDisplay($currency);

        // Only prompt when the customer has never chosen before and isn't
        // arriving via a link that already specifies a preference.
        $showPrefsDialog = $savedPrefs === null && $queryLang === null && $queryCurrency === null;

        // Active categories, ordered as: every set (fixed-price) menu first
        // (in their own position band), then every normal category (in its
        // own, independent position band) — the two never share one scale,
        // see Category's class docblock / the Set menus restructure. This
        // rendering query is purely data-driven: it filters on Category/
        // Product's own $active flag only, never on
        // Restaurant::$setMenusEnabled — that flag gates the ADMIN screen
        // (see SetMenusFeatureGate), not what a published menu-category
        // renders publicly. A menu created while the flag was on keeps
        // rendering here even if the flag is later switched off.
        $allActiveCategories = $activeCategoriesForCheck;

        $setMenus = array_values(array_filter($allActiveCategories, fn($c) => $c->isFixedPriceMenu()));
        $normalCategories = array_values(array_filter($allActiveCategories, fn($c) => !$c->isFixedPriceMenu()));
        usort($setMenus, fn($a, $b) => $a->getPosition() <=> $b->getPosition());
        usort($normalCategories, fn($a, $b) => $a->getPosition() <=> $b->getPosition());
        $categories = array_merge($setMenus, $normalCategories);

        // Products with converted price
        $currencyConverter = new CurrencyConverter(
            $em->getRepository(\App\Entity\ExchangeRate::class)
        );

        // Bulk-initializes every to-many collection the loop below and the
        // template touch per product (tags, price variants, translations,
        // ingredient links) — one query per collection for the whole menu,
        // instead of Doctrine lazily re-querying per product per collection
        // the first time each is touched (measured: ~130 queries for a
        // 21-dish menu before this, mostly this exact N+1 shape). Must run
        // before the loop below, which is the first thing to touch
        // getPriceVariants() per product. See ProductRepository::warmMenuCollections().
        $productRepo->warmMenuCollections(
            $productRepo->findActiveForRestaurant($restaurant, $cacheKeyPrefix),
            $cacheKeyPrefix
        );

        // Collected alongside the loop below so ProductTranslationService
        // can check every dish actually shown on this menu for a missing
        // translation, without a second pass over categories/sections.
        $allProducts = [];

        foreach ($categories as $category) {
            if ($category->isFixedPriceMenu()) {
                $category->setConvertedMenuPrice(
                    $currencyConverter->convert($category->getMenuPrice(), $restaurant->getCurrency(), $currency)
                );
                foreach ($category->getActiveSectionsWithProducts() as $entry) {
                    foreach ($entry['products'] as $product) {
                        if ($product->getSupplementPrice() !== null) {
                            $product->setConvertedSupplementPrice(
                                $currencyConverter->convert($product->getSupplementPrice(), $restaurant->getCurrency(), $currency)
                            );
                        }
                        $allProducts[] = $product;
                    }
                }
                continue;
            }

            $products = $category->getProducts()
                ->filter(fn($p) => $p->isActive())
                ->toArray();
            usort($products, fn($a, $b) => $a->getPosition() <=> $b->getPosition());
            foreach ($products as $product) {
                $product->setConvertedPrice(
                    $currencyConverter->convert(
                        $product->getBasePrice(),
                        $restaurant->getCurrency(),
                        $currency
                    )
                );

                if ($product->getSupplementPrice() !== null) {
                    $product->setConvertedSupplementPrice(
                        $currencyConverter->convert($product->getSupplementPrice(), $restaurant->getCurrency(), $currency)
                    );
                }

                $convertedVariantPrices = [];
                foreach ($product->getPriceVariants() as $variant) {
                    $convertedVariantPrices[$variant->getId()] = $currencyConverter->convert(
                        $variant->getPrice(),
                        $restaurant->getCurrency(),
                        $currency
                    );
                }
                $product->setConvertedVariantPrices($convertedVariantPrices);
                $allProducts[] = $product;
            }
        }

        // Dish/category translations — safety net only: the loading screen
        // above already warms these before this point is normally reached,
        // so this is a fast no-op unless that detour was skipped or failed.
        $productTranslationService->resolveForMenu($restaurant, $allProducts, $locale);
        $categoryTranslationService->resolveForMenu($restaurant, $allActiveCategories, $locale, $cacheKeyPrefix);

        // Tags — sorted + resolved names (with lazy-dispatch fallback for missing locales)
        $tags     = $restaurant->getProductTags()->toArray();
        usort($tags, fn($a, $b) => $a->getPosition() <=> $b->getPosition());
        $tagNames = $tagTranslationService->resolveForMenu($restaurant, $locale, $cacheKeyPrefix);

        // Allergens — computed once for the whole menu (see
        // ProductAllergenResolver), then serialized per product in the
        // customer's current locale for the template to render as chips.
        // Also collects the distinct set of allergens actually present
        // anywhere on the menu (excluding FREE_FROM, which isn't something
        // a customer would ever want to "avoid") to drive the customer
        // allergen filter — a restaurant with no allergen data configured
        // simply gets no filter button at all, rather than an empty one.
        $resolveAllergenName = static function ($allergen) use ($locale, $restaurant) {
            $t = $allergen->getTranslation($locale)
                ?? $allergen->getTranslation($restaurant->getDefaultLanguage())
                ?? $allergen->getTranslation('en');

            return $t?->getName() ?? $allergen->getCode();
        };

        $productAllergens   = [];
        $menuAllergensByCode = [];
        foreach ($allergenResolver->resolveForRestaurant($restaurant, $cacheKeyPrefix) as $productId => $entries) {
            $serialized = [];
            foreach ($entries as $entry) {
                $allergen = $entry['allergen'];
                $serialized[] = [
                    'code'     => $allergen->getCode(),
                    'icon'     => $allergen->getIcon(),
                    'name'     => $resolveAllergenName($allergen),
                    'presence' => $entry['presence']->value,
                    'note'     => $entry['note'],
                ];
                if ($entry['presence'] !== AllergenPresence::FREE_FROM) {
                    $menuAllergensByCode[$allergen->getCode()] = $allergen;
                }
            }
            $productAllergens[$productId] = $serialized;
        }

        uasort($menuAllergensByCode, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());
        $menuAllergens = array_map(
            static fn ($allergen) => [
                'code' => $allergen->getCode(),
                'icon' => $allergen->getIcon(),
                'name' => $resolveAllergenName($allergen),
            ],
            array_values($menuAllergensByCode)
        );

        // Layout + theme (with preview support)
        $layout        = $restaurant->getLayout();
        $theme         = $restaurant->getTheme();
        $isPreview     = false;
        $validLayouts  = ['standard', 'compact', 'grid'];
        $validThemes   = ['classic-dark', 'classic-warm', 'glass', 'ocean', 'noir', 'forest', 'terra', 'warm-cream'];

        $previewLayout = $request->query->get('preview_layout');
        $previewTheme  = $request->query->get('preview_theme');

        if ($previewLayout || $previewTheme) {
            $user = $this->getUser();
            if ($user && method_exists($user, 'getRestaurant') && $user->getRestaurant() === $restaurant) {
                if ($previewLayout && in_array($previewLayout, $validLayouts)) {
                    $layout    = $previewLayout;
                    $isPreview = true;
                }
                if ($previewTheme && in_array($previewTheme, $validThemes)) {
                    $theme     = $previewTheme;
                    $isPreview = true;
                }
            }
        }

        $showTypeFilter = $categoryTypeFilterResolver->shouldShowFilter($categories);

        // "Popular" badge — the top few most-viewed dishes over the last 30
        // days, but only once each has real traffic behind it (see the two
        // POPULAR_BADGE_* constants); an under-trafficked restaurant simply
        // shows no badges at all rather than a misleading one.
        $popularProductIds = array_values(array_map(
            static fn (array $row) => $row['product']->getId(),
            array_filter(
                $productViewRepo->topProducts($restaurant, new \DateTimeImmutable('-30 days'), self::POPULAR_BADGE_LIMIT),
                static fn (array $row) => $row['views'] >= self::POPULAR_BADGE_MIN_VIEWS
            )
        ));

        return $this->render('menu/show.html.twig', [
            'restaurant'    => $restaurant,
            'categories'    => $categories,
            'showTypeFilter' => $showTypeFilter,
            'locale'        => $locale,
            'currency'      => $currency,
            'currencyDisplay' => $currencyDisplay,
            'languages'     => $languages,
            'currencies'    => $currencies,
            'tags'          => $tags,
            'tagNames'      => $tagNames,
            'productAllergens' => $productAllergens,
            'menuAllergens' => $menuAllergens,
            'popularProductIds' => $popularProductIds,
            'layout'        => $layout,
            'theme'         => $theme,
            'isPreview'     => $isPreview,
            'previewLayout' => $previewLayout,
            'previewTheme'  => $previewTheme,
            'showPrefsDialog' => $showPrefsDialog,
        ]);
    }
}
