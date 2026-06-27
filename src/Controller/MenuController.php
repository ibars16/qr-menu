<?php

namespace App\Controller;

use App\Repository\RestaurantRepository;
use App\Service\CurrencyConverter;
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
        Request $request
    ): Response {
        $restaurant = $restaurantRepo->findOneBy(['slug' => $slug]);
        if (!$restaurant) {
            throw $this->createNotFoundException('Restaurante no encontrado.');
        }

        return $this->renderMenu($restaurant, $request, $em);
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
        EntityManagerInterface $em
    ): Response {
        $languages  = require $this->getParameter('kernel.project_dir') . '/config/languages.php';
        $currencies = require $this->getParameter('kernel.project_dir') . '/config/currencies.php';

        // Language
        $supportedLanguages = array_keys($languages);
        $browserLanguage    = substr($request->getPreferredLanguage(), 0, 2);
        $detectedLanguage   = in_array($browserLanguage, $supportedLanguages)
            ? $browserLanguage
            : $restaurant->getDefaultLanguage();
        $locale = $request->query->get('lang', $detectedLanguage);

        // Currency
        $currency = $request->query->get('currency', $restaurant->getCurrency());

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

        // Tags
        $tags = $restaurant->getProductTags()->toArray();
        usort($tags, fn($a, $b) => $a->getPosition() <=> $b->getPosition());

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
            'layout'        => $layout,
            'theme'         => $theme,
            'isPreview'     => $isPreview,
            'previewLayout' => $previewLayout,
            'previewTheme'  => $previewTheme,
        ]);
    }
}
