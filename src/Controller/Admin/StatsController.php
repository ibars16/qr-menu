<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Entity\Restaurant;
use App\Repository\ProductRepository;
use App\Repository\ProductViewRepository;
use App\Repository\SmartWaiterExchangeLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_USER')]
class StatsController extends AbstractController
{
    private function restaurant(): Restaurant
    {
        $restaurant = $this->getUser()->getRestaurant();
        if (!$restaurant) {
            throw $this->createAccessDeniedException('No restaurant linked to this user.');
        }
        return $restaurant;
    }

    #[Route('/estadisticas', name: 'stats')]
    public function index(
        ProductViewRepository $productViewRepo,
        ProductRepository $productRepo,
        SmartWaiterExchangeLogRepository $exchangeLogRepo,
    ): Response {
        $restaurant = $this->restaurant();
        $since = new \DateTimeImmutable('-30 days');

        $topProducts = array_map(
            fn (array $row) => ['name' => $this->productName($row['product'], $restaurant), 'views' => $row['views']],
            $productViewRepo->topProducts($restaurant, $since, 10)
        );

        $viewedProductIds = $productViewRepo->viewedProductIds($restaurant, $since);
        $neverViewed = array_map(
            fn (Product $product) => ['name' => $this->productName($product, $restaurant)],
            $productRepo->findActiveExcludingIds($restaurant, $viewedProductIds)
        );

        return $this->render('admin/stats/index.html.twig', [
            'restaurant' => $restaurant,
            'topProducts' => $topProducts,
            'viewsPerDay' => $productViewRepo->viewsPerDay($restaurant, $since),
            'totalViews' => $productViewRepo->totalViews($restaurant, $since),
            'conversations' => $exchangeLogRepo->countConversations($restaurant, $since),
            'neverViewed' => $neverViewed,
            'localeBreakdown' => $exchangeLogRepo->localeBreakdown($restaurant, $since),
        ]);
    }

    private function productName(Product $product, Restaurant $restaurant): string
    {
        $translation = $product->getTranslation($restaurant->getDefaultLanguage())
            ?? ($product->getTranslations()->first() ?: null);

        return $translation ? $translation->getName() : ('#' . $product->getId());
    }
}
