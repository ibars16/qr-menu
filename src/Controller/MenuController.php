<?php

namespace App\Controller;

use App\Entity\Table;
use App\Repository\RestaurantRepository;
use App\Repository\TableRepository;
use App\Service\CurrencyConverter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MenuController extends AbstractController
{
    // ── Nueva ruta simple (sin mesa) ─────────────────────────────────────────
    #[Route('/r/{slug}', name: 'menu_show')]
    public function show(
        string $slug,
        RestaurantRepository $restaurantRepo,
        CurrencyConverter $currencyConverter,
        EntityManagerInterface $em,
        Request $request
    ): Response {
        $restaurant = $restaurantRepo->findOneBy(['slug' => $slug]);
        if (!$restaurant) {
            throw $this->createNotFoundException('Restaurante no encontrado.');
        }

        // Crear mesa automáticamente si no existe
        if ($restaurant->getTables()->isEmpty()) {
            $table = new Table();
            $table->setRestaurant($restaurant);
            $table->setNumber('1');
            $table->setQrToken(bin2hex(random_bytes(16)));
            $table->setActive(true);
            $em->persist($table);
            $em->flush();
        } else {
            $table = $restaurant->getTables()->first();
        }

        return $this->renderMenu($restaurant, $table, $request, $em);
    }

    // ── Ruta antigua (compatibilidad con QRs ya impresos) ────────────────────
    #[Route('/r/{slug}/table/{qrToken}', name: 'menu_show_table')]
    public function showTable(
        string $slug,
        string $qrToken,
        RestaurantRepository $restaurantRepo,
        TableRepository $tableRepo,
        CurrencyConverter $currencyConverter,
        EntityManagerInterface $em,
        Request $request
    ): Response {
        $restaurant = $restaurantRepo->findOneBy(['slug' => $slug]);
        if (!$restaurant) {
            throw $this->createNotFoundException('Restaurante no encontrado.');
        }

        $table = $tableRepo->findOneBy(['qrToken' => $qrToken, 'restaurant' => $restaurant]);
        if (!$table) {
            // Fallback: use first table
            $table = $restaurant->getTables()->first();
        }
        if (!$table) {
            throw $this->createNotFoundException('Mesa no encontrada.');
        }

        return $this->renderMenu($restaurant, $table, $request, $em);
    }

    // ── Lógica compartida ─────────────────────────────────────────────────────
    private function renderMenu(
        \App\Entity\Restaurant $restaurant,
        \App\Entity\Table $table,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $languages  = require $this->getParameter('kernel.project_dir') . '/config/languages.php';
        $currencies = require $this->getParameter('kernel.project_dir') . '/config/currencies.php';

        // Idioma
        $supportedLanguages = array_keys($languages);
        $browserLanguage    = substr($request->getPreferredLanguage(), 0, 2);
        $detectedLanguage   = in_array($browserLanguage, $supportedLanguages)
            ? $browserLanguage
            : $restaurant->getDefaultLanguage();
        $locale = $request->query->get('lang', $detectedLanguage);

        // Divisa
        $currency = $request->query->get('currency', $restaurant->getCurrency());

        // Categorías activas ordenadas
        $categories = $restaurant->getCategories()
            ->filter(fn($c) => $c->isActive())
            ->toArray();
        usort($categories, fn($a, $b) => $a->getPosition() <=> $b->getPosition());

        // Productos con precio convertido
        $currencyConverter = new \App\Service\CurrencyConverter(
            $em->getRepository(\App\Entity\ExchangeRate::class)
        );

        foreach ($categories as $category) {
            $products = $category->getProducts()
                ->filter(fn($p) => $p->isActive())
                ->toArray();
            usort($products, fn($a, $b) => $a->getPosition() <=> $b->getPosition());
            foreach ($products as $product) {
                $converted = $currencyConverter->convert(
                    $product->getBasePrice(),
                    $restaurant->getCurrency(),
                    $currency
                );
                $product->setConvertedPrice($converted);
            }
            $category->activeProductsSorted = $products;
        }

        // Tags
        $tags = $restaurant->getProductTags()->toArray();
        usort($tags, fn($a, $b) => $a->getPosition() <=> $b->getPosition());

        // Tema y preview
        $theme        = $restaurant->getTheme();
        $isPreview    = false;
        $previewTheme = $request->query->get('preview_theme');

        if ($previewTheme) {
            $user = $this->getUser();
            if ($user && method_exists($user, 'getRestaurant') && $user->getRestaurant() === $restaurant) {
                $validThemes = ['classic', 'glass', 'bold', 'grid'];
                if (in_array($previewTheme, $validThemes)) {
                    $theme     = $previewTheme;
                    $isPreview = true;
                }
            }
        }

        return $this->render('menu/show.html.twig', [
            'restaurant'   => $restaurant,
            'table'        => $table,
            'categories'   => $categories,
            'locale'       => $locale,
            'currency'     => $currency,
            'languages'    => $languages,
            'currencies'   => $currencies,
            'tags'         => $tags,
            'theme'        => $theme,
            'isPreview'    => $isPreview,
            'previewTheme' => $previewTheme,
        ]);
    }
}
