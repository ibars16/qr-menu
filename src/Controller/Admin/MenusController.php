<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\MenuSection;
use App\Enum\CategoryType;
use App\Repository\AllergenRepository;
use App\Service\CategoryMenuPriceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fixed-price menus ("menú del día", tasting menu…) — a first-class
 * sidebar section, fully separate from the Categorías/Carta screens (see
 * CLAUDE.md-adjacent design note: mixing menus into categories confused
 * the owner, hence the split). A menu is still just a Category with
 * $menuPrice set — see Category::isFixedPriceMenu() — but every admin
 * touchpoint for one lives here, not in MenuAdminController/
 * CategoriesController. Visibility toggle and delete are the one
 * exception: those reuse MenuAdminController's toggleCategory()/
 * deleteCategory(), which are already generic over any Category.
 */
#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_STAFF')]
class MenusController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly CategoryMenuPriceResolver $menuPriceResolver,
        private readonly AllergenRepository $allergenRepository,
    ) {
    }

    private function restaurant(): \App\Entity\Restaurant
    {
        $restaurant = $this->getUser()->getRestaurant();
        if (!$restaurant) {
            throw $this->createAccessDeniedException('No restaurant linked to this user.');
        }
        return $restaurant;
    }

    private function assertOwner(\App\Entity\Restaurant $restaurant): void
    {
        if ($restaurant !== $this->restaurant()) {
            throw $this->createAccessDeniedException();
        }
    }

    /** @return Category[] every menu-category for this restaurant, in their own position order */
    private function menusFor(\App\Entity\Restaurant $restaurant): array
    {
        $menus = array_values(array_filter(
            $restaurant->getCategories()->toArray(),
            static fn(Category $c) => $c->isFixedPriceMenu()
        ));
        usort($menus, static fn($a, $b) => $a->getPosition() <=> $b->getPosition());

        return $menus;
    }

    // ── Screens ──────────────────────────────────────────────────────────────

    #[Route('/menus', name: 'menus')]
    public function index(): Response
    {
        $restaurant = $this->restaurant();

        return $this->render('admin/menus.html.twig', [
            'restaurant' => $restaurant,
            'menus'      => $this->menusFor($restaurant),
            'locale'     => $restaurant->getDefaultLanguage(),
        ]);
    }

    #[Route('/menus/{id}', name: 'menus_edit', requirements: ['id' => '\d+'])]
    public function edit(Category $menu): Response
    {
        $this->assertOwner($menu->getRestaurant());
        if (!$menu->isFixedPriceMenu()) {
            throw $this->createNotFoundException();
        }

        $restaurant = $menu->getRestaurant();
        $languages  = require $this->getParameter('kernel.project_dir') . '/config/languages.php';

        return $this->render('admin/menu_edit.html.twig', [
            'restaurant' => $restaurant,
            'menu'       => $menu,
            'languages'  => $languages,
            'locale'     => $restaurant->getDefaultLanguage(),
            'allergens'  => $this->allergenRepository->findAllOrdered(),
        ]);
    }

    // ── Menu header CRUD ─────────────────────────────────────────────────────

    #[Route('/menus/create', name: 'menus_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();
        $data       = json_decode($request->getContent(), true);
        $name       = trim($data['name'] ?? '');

        if (!$name) {
            return $this->json(['error' => $this->translator->trans('error.category_name_required', domain: 'admin_menu')], 400);
        }

        $priceResult = $this->menuPriceResolver->resolve(['menuPrice' => $data['menuPrice'] ?? null, 'menuDescription' => $data['menuDescription'] ?? null]);
        if (!$priceResult['ok']) {
            return $this->json(['error' => $this->translator->trans('field.menu_price_required', domain: 'admin_menus')], 400);
        }

        $menu = new Category();
        $menu->setRestaurant($restaurant);
        $menu->setPosition(count($this->menusFor($restaurant)));
        $menu->setActive(true);
        $menu->setType(CategoryType::tryFrom($data['type'] ?? ''));
        $menu->setMenuPrice($priceResult['menuPrice']);
        $menu->setMenuDescription($priceResult['menuDescription']);

        $translation = new CategoryTranslation();
        $translation->setCategory($menu);
        $translation->setLocale($restaurant->getDefaultLanguage());
        $translation->setName($name);

        $em->persist($menu);
        $em->persist($translation);

        // No default section here on purpose: a fabricated "Platos" section
        // just becomes junk the owner has to notice and delete once they've
        // created their real ones. The mandatory-section rule (a dish always
        // belongs to a section) still holds — "+ Añadir plato" simply has
        // nowhere to attach until the owner creates the first real section
        // (see menu_edit.html.twig's empty state) — see MenuSection's class
        // docblock. Pre-existing menus that already got a synthesized
        // "Platos" section from the (a)→(b) data migration are unaffected;
        // this only changes what happens for a brand-new menu going forward.
        $em->flush();

        return $this->json(['id' => $menu->getId()]);
    }

    #[Route('/menus/{id}/edit', name: 'menus_update', methods: ['POST'])]
    public function update(Category $menu, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->assertOwner($menu->getRestaurant());
        if (!$menu->isFixedPriceMenu()) {
            throw $this->createNotFoundException();
        }

        $data = json_decode($request->getContent(), true);
        $name = trim($data['name'] ?? '');

        if (!$name) {
            return $this->json(['error' => $this->translator->trans('error.category_name_required', domain: 'admin_menu')], 400);
        }

        $priceResult = $this->menuPriceResolver->resolve(['menuPrice' => $data['menuPrice'] ?? null, 'menuDescription' => $data['menuDescription'] ?? null]);
        if (!$priceResult['ok']) {
            return $this->json(['error' => $this->translator->trans('field.menu_price_required', domain: 'admin_menus')], 400);
        }

        $menu->setType(CategoryType::tryFrom($data['type'] ?? ''));
        $menu->setMenuPrice($priceResult['menuPrice']);
        $menu->setMenuDescription($priceResult['menuDescription']);

        $locale      = $menu->getRestaurant()->getDefaultLanguage();
        $translation = $menu->getTranslation($locale);
        if (!$translation) {
            $translation = new CategoryTranslation();
            $translation->setCategory($menu);
            $translation->setLocale($locale);
            $em->persist($translation);
        }
        $translation->setName($name);

        $em->flush();

        return $this->json(['id' => $menu->getId(), 'name' => $name]);
    }

    #[Route('/menus/reorder', name: 'menus_reorder', methods: ['POST'])]
    public function reorder(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();
        $ids        = json_decode($request->getContent(), true)['ids'] ?? [];

        // Menus reorder among themselves only — their own independent
        // 0..n-1 position band, never touching normal categories' position
        // values. See the class docblock and the "global ordering" note in
        // MenuAdminController::reorderCategories() for the mirror image of
        // this on the Carta side.
        foreach ($ids as $position => $id) {
            $menu = $em->getRepository(Category::class)->find($id);
            if ($menu && $menu->getRestaurant() === $restaurant && $menu->isFixedPriceMenu()) {
                $menu->setPosition($position);
            }
        }
        $em->flush();

        return $this->json(['ok' => true]);
    }

    /** Deletes every fixed-price menu — cascades their sections/dishes, same as MenuAdminController::deleteCategory(). Ordinary categories are untouched. */
    #[Route('/menus/delete-all', name: 'menus_delete_all', methods: ['POST'])]
    public function deleteAll(EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();

        foreach ($this->menusFor($restaurant) as $menu) {
            $em->remove($menu);
        }
        $em->flush();

        return $this->json(['ok' => true]);
    }

    // ── Sections (Primeros/Segundos/Postres…) ────────────────────────────────

    #[Route('/menus/{id}/sections/create', name: 'menus_section_create', methods: ['POST'])]
    public function createSection(Category $menu, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->assertOwner($menu->getRestaurant());
        if (!$menu->isFixedPriceMenu()) {
            throw $this->createNotFoundException();
        }

        $data  = json_decode($request->getContent(), true);
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            return $this->json(['error' => $this->translator->trans('error.section_label_required', domain: 'admin_menus')], 400);
        }

        $section = new MenuSection();
        $section->setCategory($menu);
        $section->setLabel($label);
        $section->setPosition($menu->getMenuSections()->count());

        $em->persist($section);
        $em->flush();

        return $this->json(['id' => $section->getId(), 'label' => $label]);
    }

    #[Route('/menu-sections/{id}/rename', name: 'menus_section_rename', methods: ['POST'])]
    public function renameSection(MenuSection $section, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->assertOwner($section->getCategory()->getRestaurant());

        $data  = json_decode($request->getContent(), true);
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            return $this->json(['error' => $this->translator->trans('error.section_label_required', domain: 'admin_menus')], 400);
        }

        $section->setLabel($label);
        $em->flush();

        return $this->json(['id' => $section->getId(), 'label' => $label]);
    }

    #[Route('/menu-sections/{id}/delete', name: 'menus_section_delete', methods: ['POST'])]
    public function deleteSection(MenuSection $section, EntityManagerInterface $em): JsonResponse
    {
        $this->assertOwner($section->getCategory()->getRestaurant());

        // Cascades to delete this section's dishes too (ORM cascade:
        // ['remove'] on MenuSection::$products, plus ON DELETE CASCADE at
        // the DB level) — the simpler, existing-pattern-consistent choice:
        // deleting a Category already cascades to delete its Products the
        // same way, so a section behaves like the small category-like
        // container it is. The client confirms the dish count first — see
        // the confirm_delete_section_with_dishes copy.
        $em->remove($section);
        $em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/menus/{id}/sections/reorder', name: 'menus_sections_reorder', methods: ['POST'])]
    public function reorderSections(Category $menu, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->assertOwner($menu->getRestaurant());

        $ids      = json_decode($request->getContent(), true)['ids'] ?? [];
        $sections = $menu->getMenuSections();

        foreach ($ids as $position => $id) {
            foreach ($sections as $section) {
                if ($section->getId() === (int) $id) {
                    $section->setPosition($position);
                    break;
                }
            }
        }
        $em->flush();

        return $this->json(['ok' => true]);
    }

    // ── Dishes within/between sections ───────────────────────────────────────

    #[Route('/menus/{id}/dishes/reorder', name: 'menus_dishes_reorder', methods: ['POST'])]
    public function reorderDishes(Category $menu, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->assertOwner($menu->getRestaurant());

        // Body shape: { sections: [{ id: sectionId, productIds: [...] }, ...] }
        // — one entry per section, each carrying its dishes in the new
        // order. Covers both within-section reordering (position changes)
        // and moving a dish to a different section (menuSection FK
        // changes) in a single call, since a drag can do either.
        $sectionsPayload = json_decode($request->getContent(), true)['sections'] ?? [];

        $sectionsById = [];
        foreach ($menu->getMenuSections() as $section) {
            $sectionsById[$section->getId()] = $section;
        }
        $productsById = [];
        foreach ($menu->getProducts() as $product) {
            $productsById[$product->getId()] = $product;
        }

        foreach ($sectionsPayload as $entry) {
            $section = $sectionsById[(int) ($entry['id'] ?? 0)] ?? null;
            if (!$section) {
                continue;
            }

            foreach (($entry['productIds'] ?? []) as $position => $productId) {
                $product = $productsById[(int) $productId] ?? null;
                if (!$product) {
                    continue;
                }
                $product->setMenuSection($section);
                $product->setPosition($position);
            }
        }

        $em->flush();

        return $this->json(['ok' => true]);
    }
}
