<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\GlobalIngredient;
use App\Entity\Ingredient;
use App\Entity\IngredientTranslation;
use App\Entity\MenuImportBatch;
use App\Entity\Product;
use App\Entity\ProductGlobalIngredient;
use App\Entity\ProductIngredient;
use App\Entity\ProductTag;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Enum\MenuImportBatchStatus;
use App\Enum\MenuImportPageStatus;
use App\Repository\CategoryRepository;
use App\Repository\GlobalIngredientRepository;
use App\Repository\IngredientRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a MenuImportBatch's already-extracted page JSON (see
 * MenuVisionPromptBuilder for the shape, MenuImportExtractionService for
 * where it comes from) into real Category/Product/Ingredient rows — the
 * ONLY thing this class does. It never sets active = true, never clears
 * needsReview, and never builds anything a customer or Smart Waiter could
 * see: that's the review step, a later phase, not this one.
 *
 * Deliberately reuses, unmodified, the exact ingredient-resolution and
 * category/product conventions MenuAdminController::saveProduct() already
 * established for manual entry — an imported dish's data ends up
 * structurally indistinguishable from a hand-typed one, just flagged
 * needsReview until an owner says otherwise.
 */
final class MenuImportAssembler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CategoryRepository $categoryRepository,
        private readonly IngredientRepository $ingredientRepository,
        private readonly GlobalIngredientRepository $globalIngredientRepository,
    ) {}

    public function assemble(MenuImportBatch $batch): MenuImportAssemblyResult
    {
        $restaurant = $batch->getRestaurant();

        $categoriesCreated = 0;
        $categoriesReused = 0;
        $productsCreated = 0;
        $productsSkipped = 0;
        $ingredientsLinked = 0;
        $ingredientsSkippedUncertain = 0;
        $tagsAssigned = 0;

        /** @var array<string, Category> normalized name => Category, scoped to this one assemble() call */
        $categoriesThisRun = [];
        $anyPageExtracted = false;

        foreach ($batch->getPages() as $page) {
            if ($page->getStatus() !== MenuImportPageStatus::EXTRACTED) {
                continue; // PENDING/ANALYZING/FAILED pages contribute nothing — not an error, just nothing to assemble yet
            }
            $anyPageExtracted = true;

            $data = $page->getExtractedData();
            $locale = $page->getDetectedLocale() ?? 'en';
            $categories = is_array($data['categories'] ?? null) ? $data['categories'] : [];

            foreach ($categories as $categoryData) {
                $name = trim((string) ($categoryData['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                [$category, $wasCreated] = $this->resolveCategory($restaurant, $name, $locale, $batch, $categoriesThisRun);
                $wasCreated ? $categoriesCreated++ : $categoriesReused++;

                foreach ((is_array($categoryData['products'] ?? null) ? $categoryData['products'] : []) as $productData) {
                    $product = $this->buildProduct($productData, $category, $batch, $locale, $restaurant, $ingredientsLinked, $ingredientsSkippedUncertain, $tagsAssigned);
                    if ($product === null) {
                        $productsSkipped++;
                        continue;
                    }
                    $productsCreated++;
                }
            }
        }

        $batch->setStatus($anyPageExtracted ? MenuImportBatchStatus::READY_FOR_REVIEW : MenuImportBatchStatus::FAILED);
        if (!$anyPageExtracted) {
            $batch->setErrorMessage('No page in this batch finished extraction successfully — nothing to assemble.');
        }

        $this->em->flush();

        return new MenuImportAssemblyResult(
            categoriesCreated: $categoriesCreated,
            categoriesReused: $categoriesReused,
            productsCreated: $productsCreated,
            productsSkipped: $productsSkipped,
            ingredientsLinked: $ingredientsLinked,
            ingredientsSkippedUncertain: $ingredientsSkippedUncertain,
            tagsAssigned: $tagsAssigned,
        );
    }

    /**
     * @param array<string, Category> $categoriesThisRun
     * @return array{0: Category, 1: bool} the category, and whether it was newly created
     */
    private function resolveCategory(Restaurant $restaurant, string $name, string $locale, MenuImportBatch $batch, array &$categoriesThisRun): array
    {
        $key = mb_strtolower($name);

        if (isset($categoriesThisRun[$key])) {
            return [$categoriesThisRun[$key], false];
        }

        $existing = $this->categoryRepository->findExistingByNameAnyLocale($restaurant, $name);
        if ($existing !== null) {
            $categoriesThisRun[$key] = $existing;
            return [$existing, false];
        }

        $category = new Category();
        $category->setRestaurant($restaurant);
        $category->setPosition($restaurant->getCategories()->count());
        $category->setActive(false);
        $category->setNeedsReview(true);
        $category->setImportBatch($batch);

        $translation = new CategoryTranslation();
        $translation->setLocale($locale);
        $translation->setName($name);
        $category->addTranslation($translation);

        $this->em->persist($category);
        $this->em->persist($translation);
        $restaurant->getCategories()->add($category);

        $categoriesThisRun[$key] = $category;

        return [$category, true];
    }

    private function buildProduct(
        array $productData,
        Category $category,
        MenuImportBatch $batch,
        string $locale,
        Restaurant $restaurant,
        int &$ingredientsLinked,
        int &$ingredientsSkippedUncertain,
        int &$tagsAssigned,
    ): ?Product {
        $name = trim((string) ($productData['name'] ?? ''));
        if ($name === '') {
            return null; // ProductTranslation.name is NOT NULL — nothing sensible to create without one
        }

        $description = $productData['description'] ?? null;
        $description = is_string($description) && trim($description) !== '' ? trim($description) : null;

        $price = $productData['price'] ?? null;
        $priceCents = is_numeric($price) ? (int) round(((float) $price) * 100) : 0; // 0 is a placeholder, not a claim — see MenuImportAssembler's class docblock: this product is needsReview=true and active=false regardless

        $product = new Product();
        $product->setCategory($category);
        $product->setBasePrice($priceCents);
        $product->setPosition($category->getProducts()->count());
        $product->setActive(false);
        $product->setNeedsReview(true);
        $product->setImportBatch($batch);
        $product->setAiConfidence($this->computeConfidence($productData));

        $translation = new ProductTranslation();
        $translation->setLocale($locale);
        $translation->setName($name);
        $translation->setDescription($description);
        $product->addTranslation($translation);

        $this->em->persist($product);
        $this->em->persist($translation);
        $category->getProducts()->add($product);

        // $position only advances for ingredients actually linked — a run of
        // uncertain/empty entries never leaves a gap in the saved sequence,
        // matching MenuVisionPromptBuilder rule 4 (exact printed order).
        $position = 0;
        foreach ((is_array($productData['ingredients'] ?? null) ? $productData['ingredients'] : []) as $ingredientData) {
            if (($ingredientData['uncertain'] ?? false) === true) {
                $ingredientsSkippedUncertain++;
                continue; // stays visible only in MenuImportPage::extractedData — never becomes a ProductIngredient link
            }
            $ingredientName = trim((string) ($ingredientData['name'] ?? ''));
            if ($ingredientName === '') {
                continue;
            }
            $this->linkIngredient($product, $ingredientName, $restaurant, $locale, $position);
            $position++;
            $ingredientsLinked++;
        }

        foreach ((is_array($productData['dietary_markers'] ?? null) ? $productData['dietary_markers'] : []) as $markerData) {
            if (($markerData['uncertain'] ?? false) === true) {
                continue;
            }
            $code = trim((string) ($markerData['code'] ?? ''));
            if ($code === '' || in_array($code, MenuContextBuilder::KNOWN_HIGHLIGHT_CODES, true)) {
                continue; // hard guard: a Smart Waiter highlight code (e.g. "recommended") is never assignable this way, regardless of what the model output
            }

            $tag = $this->em->getRepository(ProductTag::class)->findOneBy([
                'restaurant' => $restaurant,
                'code' => $code,
                'isSystem' => true,
            ]);
            if ($tag === null) {
                continue; // no matching preset tag for this restaurant — skip, never fabricate one
            }

            $product->addTag($tag);
            $tagsAssigned++;
        }

        return $product;
    }

    /** Exactly MenuAdminController::saveProduct()'s 3-tier resolution for a typed ingredient name, reused rather than reimplemented — plus the position this call's ingredient occupies in the printed/entered order. */
    private function linkIngredient(Product $product, string $name, Restaurant $restaurant, string $locale, int $position): void
    {
        $ingredient = $this->ingredientRepository->findExistingByNameAnyLocale($restaurant, $name);
        if ($ingredient) {
            if (!$ingredient->getTranslation($locale)) {
                $ingT = new IngredientTranslation();
                $ingT->setIngredient($ingredient);
                $ingT->setLocale($locale);
                $ingT->setName($name);
                $ingredient->addTranslation($ingT);
                $this->em->persist($ingT);
            }
            $this->attachIngredient($product, $ingredient, $position);
            return;
        }

        $globalIngredient = $this->globalIngredientRepository->findExistingByNameAnyLocale($name);
        if ($globalIngredient) {
            $this->attachGlobalIngredient($product, $globalIngredient, $position);
            return;
        }

        $ingredient = new Ingredient();
        $ingredient->setCode(strtolower(str_replace(' ', '-', $name)));
        $ingredient->setRestaurant($restaurant);
        $this->em->persist($ingredient);

        $ingT = new IngredientTranslation();
        $ingT->setIngredient($ingredient);
        $ingT->setLocale($locale);
        $ingT->setName($name);
        $ingredient->addTranslation($ingT);
        $this->em->persist($ingT);

        $this->attachIngredient($product, $ingredient, $position);
    }

    private function attachIngredient(Product $product, Ingredient $ingredient, int $position): void
    {
        $link = new ProductIngredient();
        $link->setIngredient($ingredient);
        $link->setPosition($position);
        $product->addIngredientLink($link);
        $this->em->persist($link);
    }

    private function attachGlobalIngredient(Product $product, GlobalIngredient $globalIngredient, int $position): void
    {
        $link = new ProductGlobalIngredient();
        $link->setGlobalIngredient($globalIngredient);
        $link->setPosition($position);
        $product->addGlobalIngredientLink($link);
        $this->em->persist($link);
    }

    /**
     * 1.0 minus the proportion of fields the model itself flagged uncertain.
     * A field that's legitimately absent (e.g. no price printed at all —
     * price_uncertain: false alongside price: null) is never counted as a
     * penalty; only an actual "*_uncertain": true costs anything.
     */
    private function computeConfidence(array $productData): float
    {
        $flags = [];
        $flags[] = !($productData['name_uncertain'] ?? false);

        if (($productData['description'] ?? null) !== null) {
            $flags[] = !($productData['description_uncertain'] ?? false);
        }
        if (($productData['price'] ?? null) !== null || ($productData['price_uncertain'] ?? false)) {
            $flags[] = !($productData['price_uncertain'] ?? false);
        }
        foreach ((is_array($productData['ingredients'] ?? null) ? $productData['ingredients'] : []) as $ingredientData) {
            $flags[] = !($ingredientData['uncertain'] ?? false);
        }
        foreach ((is_array($productData['dietary_markers'] ?? null) ? $productData['dietary_markers'] : []) as $markerData) {
            $flags[] = !($markerData['uncertain'] ?? false);
        }

        if (empty($flags)) {
            return 1.0;
        }

        return array_sum($flags) / count($flags);
    }
}
