<?php

namespace App\Controller;

use App\Enum\AllergenPresence;
use App\Repository\RestaurantRepository;
use App\Service\CurrencyConverter;
use App\Service\MenuPreferencesResolver;
use App\Service\ProductAllergenResolver;
use App\Service\TagTranslationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MenuController extends AbstractController
{
    #[Route('/r/{slug}', name: 'menu_show')]
    public function show(
        string $slug,
        RestaurantRepository $restaurantRepo,
        EntityManagerInterface $em,
        Request $request,
        TagTranslationService $tagTranslationService,
        MenuPreferencesResolver $menuPreferencesResolver,
        ProductAllergenResolver $allergenResolver,
    ): Response {
        $restaurant = $restaurantRepo->findOneBy(['slug' => $slug]);
        if (!$restaurant) {
            throw $this->createNotFoundException('Restaurante no encontrado.');
        }

        return $this->renderMenu($restaurant, $request, $em, $tagTranslationService, $menuPreferencesResolver, $allergenResolver);
    }

    // Backwards-compat redirect for QR codes already printed with the old table URL.
    #[Route('/r/{slug}/table/{qrToken}', name: 'menu_show_table')]
    public function showTable(string $slug): Response
    {
        return $this->redirectToRoute('menu_show', ['slug' => $slug], 301);
    }

    private function renderMenu(
        \App\Entity\Restaurant $restaurant,
        Request $request,
        EntityManagerInterface $em,
        TagTranslationService $tagTranslationService,
        MenuPreferencesResolver $menuPreferencesResolver,
        ProductAllergenResolver $allergenResolver,
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

        // Currency: explicit ?currency= override > saved preference > guessed
        // from the device's locale (if supported) > restaurant's own currency.
        $queryCurrency = $request->query->get('currency');
        $currency      = $menuPreferencesResolver->isCurrencySupported($queryCurrency)
            ? $queryCurrency
            : ($savedPrefs['currency'] ?? $menuPreferencesResolver->guessCurrency($request, $restaurant->getCurrency()));

        // Only prompt when the customer has never chosen before and isn't
        // arriving via a link that already specifies a preference.
        $showPrefsDialog = $savedPrefs === null && $queryLang === null && $queryCurrency === null;

        // Active categories, sorted
        $categories = $restaurant->getCategories()
            ->filter(fn($c) => $c->isActive())
            ->toArray();
        usort($categories, fn($a, $b) => $a->getPosition() <=> $b->getPosition());

        // Products with converted price
        $currencyConverter = new CurrencyConverter(
            $em->getRepository(\App\Entity\ExchangeRate::class)
        );

        foreach ($categories as $category) {
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
            }
            $category->activeProductsSorted = $products;
        }

        // Tags — sorted + resolved names (with lazy-dispatch fallback for missing locales)
        $tags     = $restaurant->getProductTags()->toArray();
        usort($tags, fn($a, $b) => $a->getPosition() <=> $b->getPosition());
        $tagNames = $tagTranslationService->resolveForMenu($restaurant, $locale);

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
        foreach ($allergenResolver->resolveForRestaurant($restaurant) as $productId => $entries) {
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
        $validThemes   = ['classic-dark', 'classic-warm', 'glass', 'ocean', 'noir', 'forest', 'terra'];

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

        return $this->render('menu/show.html.twig', [
            'restaurant'    => $restaurant,
            'categories'    => $categories,
            'locale'        => $locale,
            'currency'      => $currency,
            'languages'     => $languages,
            'currencies'    => $currencies,
            'tags'          => $tags,
            'tagNames'      => $tagNames,
            'productAllergens' => $productAllergens,
            'menuAllergens' => $menuAllergens,
            'layout'        => $layout,
            'theme'         => $theme,
            'isPreview'     => $isPreview,
            'previewLayout' => $previewLayout,
            'previewTheme'  => $previewTheme,
            'showPrefsDialog' => $showPrefsDialog,
        ]);
    }
}
