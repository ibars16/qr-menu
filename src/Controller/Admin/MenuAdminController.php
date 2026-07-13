<?php

namespace App\Controller\Admin;

use App\Entity\Allergen;
use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\GlobalIngredient;
use App\Entity\Ingredient;
use App\Entity\IngredientAllergen;
use App\Entity\IngredientTranslation;
use App\Entity\Product;
use App\Entity\ProductAllergenOverride;
use App\Entity\ProductTranslation;
use App\Enum\AllergenPresence;
use App\Repository\AllergenRepository;
use App\Service\ProductAllergenResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_USER')]
class MenuAdminController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly AllergenRepository $allergenRepository,
        private readonly ProductAllergenResolver $allergenResolver,
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

    /**
     * Tom Select item values are prefixed so the two independent ingredient
     * sources (and brand-new, not-yet-persisted ingredients) never collide:
     * "r:<id>" a restaurant-private Ingredient, "g:<id>" a GlobalIngredient,
     * "new:<name>" typed by the admin and not yet matched to either.
     *
     * @return array{type: 'restaurant'|'global'|'new'|'unknown', id: ?int, name: ?string}
     */
    private function parseIngredientValue(string $value): array
    {
        if (str_starts_with($value, 'r:')) {
            return ['type' => 'restaurant', 'id' => (int) substr($value, 2), 'name' => null];
        }
        if (str_starts_with($value, 'g:')) {
            return ['type' => 'global', 'id' => (int) substr($value, 2), 'name' => null];
        }
        if (str_starts_with($value, 'new:')) {
            return ['type' => 'new', 'id' => null, 'name' => substr($value, 4)];
        }

        return ['type' => 'unknown', 'id' => null, 'name' => null];
    }

    /**
     * Same locale-fallback chain used everywhere else in this controller for
     * reference data: requested locale → restaurant's content language →
     * English (the one language the taxonomy always has) → raw code.
     */
    private function serializeAllergen(Allergen $allergen, string $locale, string $contentLocale): array
    {
        $t = $allergen->getTranslation($locale)
            ?? $allergen->getTranslation($contentLocale)
            ?? $allergen->getTranslation('en');

        return [
            'id'    => $allergen->getId(),
            'code'  => $allergen->getCode(),
            'icon'  => $allergen->getIcon(),
            'color' => $allergen->getColor(),
            'name'  => $t?->getName() ?? $allergen->getCode(),
        ];
    }

    // ── Main view ────────────────────────────────────────────────────────────

    #[Route('/menu', name: 'menu')]
    public function menu(): Response
    {
        $restaurant = $this->restaurant();
        $languages  = require $this->getParameter('kernel.project_dir') . '/config/languages.php';

        $categories = $restaurant->getCategories()->toArray();
        usort($categories, fn($a, $b) => $a->getPosition() <=> $b->getPosition());

        return $this->render('admin/menu.html.twig', [
            'restaurant' => $restaurant,
            'categories' => $categories,
            'languages'  => $languages,
            'locale'     => $restaurant->getDefaultLanguage(),
            'allergens'  => $this->allergenRepository->findAllOrdered(),
        ]);
    }

    // ── Categories ───────────────────────────────────────────────────────────

    #[Route('/categories/create', name: 'category_create', methods: ['POST'])]
    public function createCategory(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();
        $data       = json_decode($request->getContent(), true);
        $name       = trim($data['name'] ?? '');

        if (!$name) {
            return $this->json(['error' => $this->translator->trans('error.category_name_required', domain: 'admin_menu')], 400);
        }

        $category = new Category();
        $category->setRestaurant($restaurant);
        $category->setPosition($restaurant->getCategories()->count());
        $category->setActive(true);

        $translation = new CategoryTranslation();
        $translation->setCategory($category);
        $translation->setLocale($restaurant->getDefaultLanguage());
        $translation->setName($name);

        $em->persist($category);
        $em->persist($translation);
        $em->flush();

        return $this->json(['id' => $category->getId(), 'name' => $name]);
    }

    #[Route('/categories/{id}/edit', name: 'category_edit', methods: ['POST'])]
    public function editCategory(Category $category, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->assertOwner($category->getRestaurant());

        $data = json_decode($request->getContent(), true);
        $name = trim($data['name'] ?? '');

        if (!$name) {
            return $this->json(['error' => $this->translator->trans('error.category_name_required', domain: 'admin_menu')], 400);
        }

        $locale      = $category->getRestaurant()->getDefaultLanguage();
        $translation = $category->getTranslation($locale);

        if (!$translation) {
            $translation = new CategoryTranslation();
            $translation->setCategory($category);
            $translation->setLocale($locale);
            $em->persist($translation);
        }

        $translation->setName($name);
        $em->flush();

        return $this->json(['id' => $category->getId(), 'name' => $name]);
    }

    #[Route('/categories/{id}/toggle', name: 'category_toggle', methods: ['POST'])]
    public function toggleCategory(Category $category, EntityManagerInterface $em): JsonResponse
    {
        $this->assertOwner($category->getRestaurant());
        $category->setActive(!$category->isActive());
        $em->flush();
        return $this->json(['active' => $category->isActive()]);
    }

    #[Route('/categories/{id}/delete', name: 'category_delete', methods: ['POST'])]
    public function deleteCategory(Category $category, EntityManagerInterface $em): JsonResponse
    {
        $this->assertOwner($category->getRestaurant());
        $em->remove($category);
        $em->flush();
        return $this->json(['ok' => true]);
    }

    // ── Products ─────────────────────────────────────────────────────────────

    #[Route('/products/{id}', name: 'product_get', methods: ['GET'])]
    public function getProduct(Product $product, Request $request): JsonResponse
    {
        $this->assertOwner($product->getCategory()->getRestaurant());

        $translations = [];
        foreach ($product->getTranslations() as $t) {
            $translations[$t->getLocale()] = [
                'name'        => $t->getName(),
                'description' => $t->getDescription(),
            ];
        }

        $tags = [];
        foreach ($product->getTags() as $tag) {
            $tags[] = $tag->getId();
        }

        // Show already-attached ingredients (from both the restaurant's own
        // list and the Global Ingredient Library) in the current Admin Panel
        // language, same as the autocomplete — falling back to the
        // restaurant's own content language, then English (the one language
        // the global library always has), then the raw code.
        $adminLocale     = $request->getLocale();
        $contentLocale   = $product->getCategory()->getRestaurant()->getDefaultLanguage();
        $ingredientsList = [];
        foreach ($product->getIngredients() as $ing) {
            $t = $ing->getTranslation($adminLocale) ?? $ing->getTranslation($contentLocale);

            // Its own allergen tags too — lets the product panel offer an
            // inline editor for this private ingredient without a second
            // request (see the ingredients/allergens endpoints for when one
            // gets added to the selection afterwards instead).
            $ingAllergens = [];
            foreach ($ing->getAllergenLinks() as $link) {
                $ingAllergens[] = ['allergenId' => $link->getAllergen()->getId(), 'presence' => $link->getPresence()->value];
            }

            $ingredientsList[] = [
                'value'     => 'r:' . $ing->getId(),
                'name'      => $t?->getName() ?? $ing->getCode(),
                'source'    => 'restaurant',
                'allergens' => $ingAllergens,
            ];
        }
        foreach ($product->getGlobalIngredients() as $gIng) {
            $t = $gIng->getTranslation($adminLocale) ?? $gIng->getTranslation($contentLocale) ?? $gIng->getTranslation('en');
            $ingredientsList[] = [
                'value'  => 'g:' . $gIng->getId(),
                'name'   => $t?->getName() ?? $gIng->getCode(),
                'source' => 'global',
            ];
        }

        // Allergens — computed from the ingredients above, with any
        // product-specific exception layered on top. Kept as two separate
        // lists in the response (rather than one merged one) so the admin
        // UI can show "from your ingredients" and "exceptions you set"
        // as clearly distinct — see ProductAllergenResolver.
        $allergensComputed = [];
        $allergenOverrides = [];
        foreach ($this->allergenResolver->resolveForProduct($product) as $entry) {
            $row = $this->serializeAllergen($entry['allergen'], $adminLocale, $contentLocale);
            $row['presence'] = $entry['presence']->value;
            if ($entry['source'] === 'override') {
                $row['note'] = $entry['note'];
                $allergenOverrides[] = $row;
            } else {
                $allergensComputed[] = $row;
            }
        }

        return $this->json([
            'id'                => $product->getId(),
            'categoryId'        => $product->getCategory()->getId(),
            'image'             => $product->getImage(),
            'basePrice'         => $product->getBasePrice(),
            'calories'          => $product->getCalories(),
            'spicyLevel'        => $product->getSpicyLevel(),
            'active'            => $product->isActive(),
            'translations'      => $translations,
            'tags'              => $tags,
            'ingredientsList'   => $ingredientsList,
            'allergensComputed' => $allergensComputed,
            'allergenOverrides' => $allergenOverrides,
        ]);
    }

    #[Route('/products/save', name: 'product_save', methods: ['POST'])]
    public function saveProduct(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();
        $data       = json_decode($request->getContent(), true);
        $productId  = $data['id'] ?? null;

        // Get or create product
        if ($productId) {
            $product = $em->getRepository(Product::class)->find($productId);
            if (!$product || $product->getCategory()->getRestaurant() !== $restaurant) {
                return $this->json(['error' => $this->translator->trans('error.product_not_found', domain: 'admin_menu')], 404);
            }
            // Allow moving the product to a different category
            if (!empty($data['categoryId']) && $data['categoryId'] != $product->getCategory()->getId()) {
                $newCat = $em->getRepository(Category::class)->find($data['categoryId']);
                if ($newCat && $newCat->getRestaurant() === $restaurant) {
                    $product->setCategory($newCat);
                }
            }
        } else {
            $category = $em->getRepository(Category::class)->find($data['categoryId'] ?? 0);
            if (!$category || $category->getRestaurant() !== $restaurant) {
                return $this->json(['error' => $this->translator->trans('error.category_not_found', domain: 'admin_menu')], 404);
            }
            $product = new Product();
            $product->setCategory($category);
            $product->setPosition($category->getProducts()->count());
            $product->setActive(true);
        }

        // Scalar fields
        if (isset($data['basePrice'])) {
            $product->setBasePrice((int) round((float)$data['basePrice'] * 100));
        }
        if (array_key_exists('calories',   $data)) $product->setCalories($data['calories'] ?: null);
        if (array_key_exists('spicyLevel', $data)) $product->setSpicyLevel($data['spicyLevel'] ?: null);
        if (isset($data['active']))                 $product->setActive((bool) $data['active']);

        // Translations
        foreach ($data['translations'] ?? [] as $locale => $trans) {
            $translation = $product->getTranslation($locale);
            if (!$translation) {
                $translation = new ProductTranslation();
                $translation->setLocale($locale);
                $translation->setProduct($product);
                $product->addTranslation($translation);
                $em->persist($translation);
            }
            $translation->setName($trans['name'] ?? '');
            $translation->setDescription($trans['description'] ?? null);
        }

        // Tags
        if (isset($data['tags'])) {
            foreach ($product->getTags() as $tag) {
                $product->removeTag($tag);
            }
            foreach ($data['tags'] as $tagId) {
                $tag = $em->getRepository(\App\Entity\ProductTag::class)->find($tagId);
                if ($tag && $tag->getRestaurant() === $restaurant) {
                    $product->addTag($tag);
                }
            }
        }

        // Ingredients — from the restaurant's own list, the Global
        // Ingredient Library, or newly typed (see parseIngredientValue()).
        if (isset($data['ingredients'])) {
            foreach ($product->getIngredients() as $ing) {
                $product->removeIngredient($ing);
            }
            foreach ($product->getGlobalIngredients() as $gIng) {
                $product->removeGlobalIngredient($gIng);
            }

            $adminLocale          = $request->getLocale();
            $ingredientRepo       = $em->getRepository(Ingredient::class);
            $globalIngredientRepo = $em->getRepository(GlobalIngredient::class);

            foreach ($data['ingredients'] as $ingData) {
                $parsed = $this->parseIngredientValue(trim($ingData['value'] ?? ''));

                if ($parsed['type'] === 'restaurant') {
                    $ingredient = $ingredientRepo->find($parsed['id']);
                    if ($ingredient && $ingredient->getRestaurant() === $restaurant) {
                        $product->addIngredient($ingredient);
                    }
                    continue;
                }

                if ($parsed['type'] === 'global') {
                    $globalIngredient = $globalIngredientRepo->find($parsed['id']);
                    if ($globalIngredient) {
                        $product->addGlobalIngredient($globalIngredient);
                    }
                    continue;
                }

                if ($parsed['type'] === 'new') {
                    $name = trim($parsed['name'] ?? '');
                    if (!$name) continue;

                    // 1) The same ingredient concept may already exist for
                    //    this restaurant under a different admin locale —
                    //    reuse it rather than creating a duplicate.
                    $ingredient = $ingredientRepo->findExistingByNameAnyLocale($restaurant, $name);
                    if ($ingredient) {
                        if (!$ingredient->getTranslation($adminLocale)) {
                            $ingT = new IngredientTranslation();
                            $ingT->setIngredient($ingredient);
                            $ingT->setLocale($adminLocale);
                            $ingT->setName($name);
                            $ingredient->addTranslation($ingT);
                            $em->persist($ingT);
                        }
                        $product->addIngredient($ingredient);
                        continue;
                    }

                    // 2) Never create a restaurant-private duplicate of
                    //    something the Global Ingredient Library already
                    //    has — associate that instead. Restaurants can only
                    //    ever read the global library, never write to it.
                    $globalIngredient = $globalIngredientRepo->findExistingByNameAnyLocale($name);
                    if ($globalIngredient) {
                        $product->addGlobalIngredient($globalIngredient);
                        continue;
                    }

                    // 3) Genuinely new — create as a restaurant-private ingredient.
                    $ingredient = new Ingredient();
                    $ingredient->setCode(strtolower(str_replace(' ', '-', $name)));
                    $ingredient->setRestaurant($restaurant);
                    $em->persist($ingredient);

                    $ingT = new IngredientTranslation();
                    $ingT->setIngredient($ingredient);
                    $ingT->setLocale($adminLocale);
                    $ingT->setName($name);
                    $ingredient->addTranslation($ingT);
                    $em->persist($ingT);

                    $product->addIngredient($ingredient);
                }
            }
        }

        // Allergen overrides — the rare, deliberate exceptions to the
        // computed list (correction, or kitchen-level cross-contamination
        // that no ingredient could ever capture). FREE_FROM must always
        // carry a reason: suppressing a computed allergen is the one action
        // here with real downside if done carelessly.
        if (isset($data['allergenOverrides'])) {
            foreach ($product->getAllergenOverrides()->toArray() as $existing) {
                $product->removeAllergenOverride($existing);
                $em->remove($existing);
            }

            foreach ($data['allergenOverrides'] as $ovData) {
                $allergen = $em->getRepository(Allergen::class)->find($ovData['allergenId'] ?? 0);
                $presence = AllergenPresence::tryFrom($ovData['presence'] ?? '');
                if (!$allergen || !$presence) {
                    continue;
                }

                $note = trim($ovData['note'] ?? '');
                if ($presence === AllergenPresence::FREE_FROM && $note === '') {
                    return $this->json(['error' => $this->translator->trans('error.allergen_free_from_note_required', domain: 'admin_menu')], 400);
                }

                $override = new ProductAllergenOverride();
                $override->setAllergen($allergen);
                $override->setPresence($presence);
                $override->setNote($note !== '' ? $note : null);
                $override->setSetBy($this->getUser());
                $product->addAllergenOverride($override);
                $em->persist($override);
            }
        }

        $em->persist($product);
        $em->flush();

        $locale      = $restaurant->getDefaultLanguage();
        $translation = $product->getTranslation($locale);

        return $this->json([
            'id'    => $product->getId(),
            'name'  => $translation?->getName() ?? '',
            'price' => $product->getBasePrice() / 100,
        ]);
    }

    #[Route('/products/{id}/toggle', name: 'product_toggle', methods: ['POST'])]
    public function toggleProduct(Product $product, EntityManagerInterface $em): JsonResponse
    {
        $this->assertOwner($product->getCategory()->getRestaurant());
        $product->setActive(!$product->isActive());
        $em->flush();
        return $this->json(['active' => $product->isActive()]);
    }

    #[Route('/products/{id}/delete', name: 'product_delete', methods: ['POST'])]
    public function deleteProduct(Product $product, EntityManagerInterface $em): JsonResponse
    {
        $this->assertOwner($product->getCategory()->getRestaurant());
        $em->remove($product);
        $em->flush();
        return $this->json(['ok' => true]);
    }

    // ── Ingredients search (for autocomplete) ────────────────────────────────

    #[Route('/ingredients/search', name: 'ingredients_search', methods: ['GET'])]
    public function searchIngredients(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();
        $query      = trim($request->query->get('q', ''));
        $locale     = $request->getLocale();
        $limit      = 20;

        if (mb_strlen($query) < 2) {
            return $this->json([]);
        }

        // Only ever matches/returns names in the current Admin Panel
        // language — an ingredient translated in another locale must never
        // appear in these results, even if it exists for this restaurant (or
        // the global library).
        //
        // The restaurant's own ingredients are searched first; the Global
        // Ingredient Library only fills whatever's left of the result cap,
        // so a restaurant's own ingredients always take priority.
        $restaurantMatches = $em->getRepository(Ingredient::class)->searchByLocale($restaurant, $locale, $query, $limit);

        $results = array_map(
            static fn (array $r) => ['value' => 'r:' . $r['id'], 'name' => $r['name'], 'source' => 'restaurant'],
            $restaurantMatches
        );

        $remaining = $limit - count($results);
        if ($remaining > 0) {
            $globalMatches = $em->getRepository(GlobalIngredient::class)->searchByLocale($locale, $query, $remaining);
            foreach ($globalMatches as $r) {
                $results[] = ['value' => 'g:' . $r['id'], 'name' => $r['name'], 'source' => 'global'];
            }
        }

        return $this->json($results);
    }

    // ── Ingredient allergens (restaurant-private ingredients only — the
    //    Global Library is app-managed and never editable here) ────────────────

    #[Route('/ingredients/allergens', name: 'ingredients_allergens_batch', methods: ['GET'])]
    public function batchIngredientAllergens(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();
        $ids        = array_filter(array_map('intval', explode(',', $request->query->get('ids', ''))));

        $result = [];
        foreach ($ids as $id) {
            $ingredient = $em->getRepository(Ingredient::class)->find($id);
            if (!$ingredient || $ingredient->getRestaurant() !== $restaurant) {
                continue;
            }
            $result[$id] = array_map(
                static fn (IngredientAllergen $link) => ['allergenId' => $link->getAllergen()->getId(), 'presence' => $link->getPresence()->value],
                $ingredient->getAllergenLinks()->toArray()
            );
        }

        return $this->json($result);
    }

    #[Route('/ingredients/{id}/allergens', name: 'ingredients_allergens_save', methods: ['POST'])]
    public function saveIngredientAllergens(Ingredient $ingredient, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->assertOwner($ingredient->getRestaurant());

        $data = json_decode($request->getContent(), true);

        foreach ($ingredient->getAllergenLinks()->toArray() as $existing) {
            $ingredient->removeAllergenLink($existing);
            $em->remove($existing);
        }

        foreach ($data['allergens'] ?? [] as $row) {
            $allergen = $this->allergenRepository->find($row['allergenId'] ?? 0);
            // FREE_FROM only ever belongs on a product-level override — an
            // ingredient carries an allergen or it doesn't, there is no
            // "explicitly free from" state to record at this level.
            $presence = AllergenPresence::tryFrom($row['presence'] ?? '');
            if (!$allergen || !in_array($presence, [AllergenPresence::CONTAINS, AllergenPresence::MAY_CONTAIN], true)) {
                continue;
            }

            $link = new IngredientAllergen();
            $link->setAllergen($allergen);
            $link->setPresence($presence);
            $ingredient->addAllergenLink($link);
            $em->persist($link);
        }

        $em->flush();

        return $this->json(['ok' => true]);
    }

    // ── Reorder ───────────────────────────────────────────────────────────────

    #[Route('/reorder/categories', name: 'reorder_categories', methods: ['POST'])]
    public function reorderCategories(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();
        $ids        = json_decode($request->getContent(), true)['ids'] ?? [];

        foreach ($ids as $position => $id) {
            $cat = $em->getRepository(Category::class)->find($id);
            if ($cat && $cat->getRestaurant() === $restaurant) {
                $cat->setPosition($position);
            }
        }
        $em->flush();
        return $this->json(['ok' => true]);
    }

    #[Route('/reorder/products', name: 'reorder_products', methods: ['POST'])]
    public function reorderProducts(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();
        $ids        = json_decode($request->getContent(), true)['ids'] ?? [];

        foreach ($ids as $position => $id) {
            $product = $em->getRepository(Product::class)->find($id);
            if ($product && $product->getCategory()->getRestaurant() === $restaurant) {
                $product->setPosition($position);
            }
        }
        $em->flush();
        return $this->json(['ok' => true]);
    }
}
