<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\Ingredient;
use App\Entity\IngredientTranslation;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_USER')]
class MenuAdminController extends AbstractController
{
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
            return $this->json(['error' => 'El nombre es obligatorio.'], 400);
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
            return $this->json(['error' => 'El nombre es obligatorio.'], 400);
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
    public function getProduct(Product $product): JsonResponse
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

        $locale = $product->getCategory()->getRestaurant()->getDefaultLanguage();
        $ingredientsList = [];
        foreach ($product->getIngredients() as $ing) {
            $t = $ing->getTranslation($locale);
            $ingredientsList[] = [
                'id'   => $ing->getId(),
                'name' => $t?->getName() ?? $ing->getCode(),
            ];
        }

        return $this->json([
            'id'              => $product->getId(),
            'categoryId'      => $product->getCategory()->getId(),
            'image'           => $product->getImage(),
            'basePrice'       => $product->getBasePrice(),
            'calories'        => $product->getCalories(),
            'spicyLevel'      => $product->getSpicyLevel(),
            'active'          => $product->isActive(),
            'translations'    => $translations,
            'tags'            => $tags,
            'ingredientsList' => $ingredientsList,
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
                return $this->json(['error' => 'Plato no encontrado.'], 404);
            }
        } else {
            $category = $em->getRepository(Category::class)->find($data['categoryId'] ?? 0);
            if (!$category || $category->getRestaurant() !== $restaurant) {
                return $this->json(['error' => 'Categoría no encontrada.'], 404);
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

        // Ingredients — create new ones if needed
        if (isset($data['ingredients'])) {
            foreach ($product->getIngredients() as $ing) {
                $product->removeIngredient($ing);
            }
            foreach ($data['ingredients'] as $ingData) {
                $id   = $ingData['id'] ?? null;
                $name = trim($ingData['name'] ?? '');
                if (!$name) continue;

                if ($id) {
                    $ingredient = $em->getRepository(Ingredient::class)->find($id);
                } else {
                    // Create new ingredient
                    $ingredient = new Ingredient();
                    $ingredient->setCode(strtolower(str_replace(' ', '-', $name)));
                    $ingredient->setRestaurant($restaurant);

                    $ingT = new IngredientTranslation();
                    $ingT->setIngredient($ingredient);
                    $ingT->setLocale($restaurant->getDefaultLanguage());
                    $ingT->setName($name);

                    $em->persist($ingredient);
                    $em->persist($ingT);
                }

                if ($ingredient && $ingredient->getRestaurant() === $restaurant) {
                    $product->addIngredient($ingredient);
                }
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

    // ── Ingredients list (for autocomplete) ──────────────────────────────────

    #[Route('/ingredients/list', name: 'ingredients_list', methods: ['GET'])]
    public function listIngredients(EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->restaurant();
        $locale     = $restaurant->getDefaultLanguage();

        $ingredients = $em->getRepository(Ingredient::class)->findBy(
            ['restaurant' => $restaurant]
        );

        $result = [];
        foreach ($ingredients as $ing) {
            $t = $ing->getTranslation($locale);
            $result[] = [
                'id'   => $ing->getId(),
                'name' => $t?->getName() ?? $ing->getCode(),
            ];
        }

        return $this->json($result);
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
