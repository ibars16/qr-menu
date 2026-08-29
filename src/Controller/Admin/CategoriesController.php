<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_STAFF')]
class CategoriesController extends AbstractController
{
    private function restaurant(): \App\Entity\Restaurant
    {
        $r = $this->getUser()->getRestaurant();
        if (!$r) throw $this->createAccessDeniedException();
        return $r;
    }

    #[Route('/categories', name: 'categories')]
    public function index(): Response
    {
        $restaurant = $this->restaurant();
        $languages  = require $this->getParameter('kernel.project_dir') . '/config/languages.php';

        // Fixed-price menus live entirely on the separate Menús screen now —
        // see Admin\MenusController. This legacy category-list screen never
        // had any menu-specific UI, but without this filter a menu category
        // would still show up here by name, and its position could get
        // tangled with normal categories' via this screen's own reorder.
        $categories = array_values(array_filter(
            $restaurant->getCategories()->toArray(),
            static fn(Category $c) => !$c->isFixedPriceMenu()
        ));
        usort($categories, fn($a, $b) => $a->getPosition() <=> $b->getPosition());

        return $this->render('admin/categories.html.twig', [
            'restaurant' => $restaurant,
            'categories' => $categories,
            'languages'  => $languages,
            'locale'     => $restaurant->getDefaultLanguage(),
        ]);
    }

    #[Route('/categories/{id}/translations/save', name: 'category_translations_save', methods: ['POST'])]
    public function saveTranslations(
        Category $category,
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        if ($category->getRestaurant() !== $this->restaurant()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $data = json_decode($request->getContent(), true);

        foreach ($data['translations'] ?? [] as $locale => $name) {
            $name = trim($name);
            if (!$name) continue;

            $translation = $category->getTranslation($locale);
            if (!$translation) {
                $translation = new CategoryTranslation();
                $translation->setCategory($category);
                $translation->setLocale($locale);
                $em->persist($translation);
            }
            $translation->setName($name);
        }

        $category->getRestaurant()->bumpMenuContentVersion();
        $em->flush();
        return $this->json(['ok' => true]);
    }

    /** Deletes every ordinary (non-fixed-price) category — cascades to their Products, translations, etc. Fixed-price menus are untouched; see MenusController for their own delete-all. */
    #[Route('/categories/delete-all', name: 'categories_delete_all', methods: ['POST'])]
    public function deleteAll(EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();

        foreach ($restaurant->getCategories() as $category) {
            if (!$category->isFixedPriceMenu()) {
                $em->remove($category);
            }
        }
        $restaurant->bumpMenuContentVersion();
        $em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/categories/reorder', name: 'categories_reorder', methods: ['POST'])]
    public function reorder(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();
        $ids        = json_decode($request->getContent(), true)['ids'] ?? [];

        foreach ($ids as $position => $id) {
            $cat = $em->getRepository(Category::class)->find($id);
            if ($cat && $cat->getRestaurant() === $restaurant && !$cat->isFixedPriceMenu()) {
                $cat->setPosition($position);
            }
        }
        $restaurant->bumpMenuContentVersion();
        $em->flush();
        return $this->json(['ok' => true]);
    }
}
