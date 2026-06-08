<?php

namespace App\Controller;

use App\Repository\RestaurantRepository;
use App\Repository\TableRepository;
use App\Service\CurrencyConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MenuController extends AbstractController
{
    #[Route('/r/{slug}/table/{qrToken}', name: 'menu_show')]
    public function show(
        string $slug,
        string $qrToken,
        RestaurantRepository $restaurantRepo,
        TableRepository $tableRepo,
        CurrencyConverter $currencyConverter,
        Request $request
    ): Response {
        $languages  = require $this->getParameter('kernel.project_dir') . '/config/languages.php';
        $currencies = require $this->getParameter('kernel.project_dir') . '/config/currencies.php';

        // 1. Buscar restaurante por slug
        $restaurant = $restaurantRepo->findOneBy(['slug' => $slug]);

        if (!$restaurant) {
            throw $this->createNotFoundException('Restaurante no encontrado.');
        }

        // 2. Verificar que la mesa pertenece a este restaurante
        $table = $tableRepo->findOneBy([
            'qrToken'    => $qrToken,
            'restaurant' => $restaurant,
        ]);

        if (!$table) {
            throw $this->createNotFoundException('Mesa no encontrada.');
        }

        // 3. Idioma: parámetro URL > idioma del navegador > idioma por defecto del restaurante
        $supportedLanguages = array_keys($languages);

        $browserLanguage = substr($request->getPreferredLanguage(), 0, 2);

        $detectedLanguage = in_array($browserLanguage, $supportedLanguages)
            ? $browserLanguage
            : $restaurant->getDefaultLanguage();

        $locale = $request->query->get('lang', $detectedLanguage);

        // 4. Divisa: parámetro URL > divisa base del restaurante
        $currency = $request->query->get('currency', $restaurant->getCurrency());

        // 5. Obtener categorías activas ordenadas por posición
        $categories = $restaurant->getCategories()
            ->filter(fn($category) => $category->isActive())
            ->toArray();

        usort($categories, fn($a, $b) => $a->getPosition() <=> $b->getPosition());

        // 6. Filtrar/ordenar productos y calcular precio convertido
        foreach ($categories as $category) {
            $products = $category->getProducts()
                ->filter(fn($product) => $product->isActive())
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

        return $this->render('menu/show.html.twig', [
            'restaurant' => $restaurant,
            'table'      => $table,
            'categories' => $categories,
            'locale'     => $locale,
            'currency'   => $currency,
            'languages'  => $languages,
            'currencies' => $currencies,
        ]);
    }
}
