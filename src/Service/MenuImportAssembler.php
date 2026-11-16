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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a MenuImportBatch's already-extracted page JSON (see
 * MenuVisionPromptBuilder for the shape, MenuImportExtractionService for
 * where it comes from) into real Category/Product/Ingredient rows — the
 * ONLY thing this class does. It never clears needsReview.
 *
 * Visibility: the restaurant's FIRST carta (no dish of its own before this
 * import) is published right away — categories and dishes active, so the
 * owner sees a working menu and is asked once, on the import's complete
 * page, to check it (MenuImportController::complete()). A dish without a
 * readable price stays hidden even then (a 0 € placeholder is never shown).
 * Any later import adds to a live carta, so everything it creates stays
 * hidden (active = false) until the owner reviews it.
 *
 * Repeated dishes: one whose name (duplicateKey()) is already in its
 * category — in the carta, or earlier in this same upload — is still
 * created, but always hidden and marked importDuplicate, so Carta shows
 * "Repetido" on it until the owner reviews it (MenuImportReview::confirm()).
 *
 * A category the AI read without a name: its dishes go to "Otros" in the
 * carta's main language (menu_public's category.fallback_name), reusing
 * one the carta already has — same visibility and repeated-dish rules,
 * counted like any other dish. Never dropped silently.
 *
 * A category or dish name read ENTIRELY in capitals ("PULPO A LA GALLEGA")
 * is stored with only its first letter capitalised (fromAllCaps()). Import
 * only: what the owner types in the panel is never touched.
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
        private readonly TranslatorInterface $translator,
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
        /** @var array<int, array<string, true>> spl_object_id(category) => duplicateKey(name) => true: dish names already in it */
        $namesByCategory = [];
        $anyPageExtracted = false;
        $publish = $this->isFirstMenu($restaurant, $batch);

        foreach ($batch->getPages() as $page) {
            if ($page->getStatus() !== MenuImportPageStatus::EXTRACTED) {
                continue; // PENDING/ANALYZING/FAILED pages contribute nothing — not an error, just nothing to assemble yet
            }
            $anyPageExtracted = true;

            $data = $page->getExtractedData();
            // Every extracted name is tagged under the restaurant's own
            // configured content language, not the page's raw detected
            // script — MenuVisionPromptBuilder already instructs the model
            // to prefer that same language whenever a menu prints something
            // bilingually (see its rule 1c), and the admin panel only ever
            // looks a category/product's name up by this locale (see
            // MenuAdminController::menu()). Tagging translations any other
            // way would make them invisible there even though real data
            // exists — see Restaurant::$defaultLanguage.
            $locale = $restaurant->getDefaultLanguage();
            $categories = is_array($data['categories'] ?? null) ? $data['categories'] : [];

            foreach ($categories as $categoryData) {
                $name = self::fromAllCaps(trim((string) ($categoryData['name'] ?? '')));
                if ($name === '') {
                    if (empty($categoryData['products'])) {
                        continue; // nothing to rescue: no empty "Otros"
                    }
                    // No name read: its dishes go to "Otros" (see the class docblock).
                    $name = $this->translator->trans('category.fallback_name', [], 'menu_public', $locale);
                }

                [$category, $wasCreated] = $this->resolveCategory($restaurant, $name, $locale, $batch, $categoriesThisRun, $publish);
                $wasCreated ? $categoriesCreated++ : $categoriesReused++;

                $categoryNames = &$namesByCategory[spl_object_id($category)];
                if ($categoryNames === null) {
                    // First time this run: the dishes already in the carta
                    // (hidden and pending ones too), in any of their languages.
                    $categoryNames = [];
                    foreach ($category->getProducts() as $existing) {
                        foreach ($existing->getTranslations() as $existingTranslation) {
                            $key = self::duplicateKey((string) $existingTranslation->getName());
                            if ($key !== '') {
                                $categoryNames[$key] = true;
                            }
                        }
                    }
                }

                foreach ((is_array($categoryData['products'] ?? null) ? $categoryData['products'] : []) as $productData) {
                    $product = $this->buildProduct($productData, $category, $batch, $locale, $restaurant, $ingredientsLinked, $ingredientsSkippedUncertain, $tagsAssigned, $publish, $categoryNames);
                    if ($product === null) {
                        $productsSkipped++;
                        continue;
                    }
                    $productsCreated++;
                }
                unset($categoryNames);
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
     * The name as compared for "repeated dish": lowercase, Latin accents
     * removed, runs of spaces collapsed. Every other character is kept —
     * stripping non-Latin letters would make two different Arabic or
     * Chinese names both "" and so "equal". (No intl in the image, hence
     * the table.)
     */
    public static function duplicateKey(string $name): string
    {
        $name = mb_strtolower($name);
        $name = strtr($name, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y',
        ]);

        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * "PULPO A LA GALLEGA" → "Pulpo a la gallega", "AÑEJO" → "Añejo": only a
     * name with no lowercase letter at all and at least 4 capitals. Mixed
     * case ("Pizza BBQ"), short acronyms on their own ("BBQ", "XL") and
     * scripts without case (Chinese, Japanese, Arabic, Thai…) are returned
     * unchanged. The first LETTER is the one capitalised, so a leading
     * digit or sign ("1/2 RACIÓN", "¡OFERTA!") is kept as is.
     */
    public static function fromAllCaps(string $name): string
    {
        if (preg_match('/\p{Ll}/u', $name) === 1 || preg_match_all('/\p{Lu}/u', $name) < 4) {
            return $name;
        }
        $lower = mb_strtolower($name);
        if (preg_match('/\p{L}/u', $lower, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return $name;
        }
        [$letter, $offset] = $m[0];

        return substr($lower, 0, $offset) . mb_convert_case($letter, MB_CASE_TITLE) . substr($lower, $offset + strlen($letter));
    }

    /** No dish in the restaurant other than this import's own (so re-assembling the same batch keeps its answer). */
    private function isFirstMenu(Restaurant $restaurant, MenuImportBatch $batch): bool
    {
        $others = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Product::class, 'p')
            ->join('p.category', 'c')
            ->where('c.restaurant = :restaurant')
            ->andWhere('p.importBatch IS NULL OR p.importBatch != :batch')
            ->setParameter('restaurant', $restaurant)
            ->setParameter('batch', $batch)
            ->getQuery()
            ->getSingleScalarResult();

        return $others === 0;
    }

    /**
     * @param array<string, Category> $categoriesThisRun
     * @return array{0: Category, 1: bool} the category, and whether it was newly created
     */
    private function resolveCategory(Restaurant $restaurant, string $name, string $locale, MenuImportBatch $batch, array &$categoriesThisRun, bool $publish): array
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
        $category->setActive($publish);
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
        bool $publish,
        array &$categoryNames,
    ): ?Product {
        $name = self::fromAllCaps(trim((string) ($productData['name'] ?? '')));
        if ($name === '') {
            return null; // ProductTranslation.name is NOT NULL — nothing sensible to create without one
        }

        // Same name already in this category (in the carta, or earlier in
        // this same upload — e.g. a page sent twice): still created, but
        // hidden and marked so Carta shows "Repetido" until reviewed. The
        // price doesn't matter; a name with nothing comparable never matches.
        $key = self::duplicateKey($name);
        $isDuplicate = $key !== '' && isset($categoryNames[$key]);
        if ($key !== '') {
            $categoryNames[$key] = true;
        }

        $description = $productData['description'] ?? null;
        $description = is_string($description) && trim($description) !== '' ? trim($description) : null;

        $price = $productData['price'] ?? null;
        $priceCents = is_numeric($price) ? (int) round(((float) $price) * 100) : 0; // 0 is a placeholder, not a claim — such a product always stays active=false (see the class docblock)

        $supplementPrice = $productData['supplement_price'] ?? null;
        $supplementPriceCents = is_numeric($supplementPrice) ? (int) round(((float) $supplementPrice) * 100) : null;

        $product = new Product();
        $product->setCategory($category);
        $product->setBasePrice($priceCents);
        $product->setSupplementPrice($supplementPriceCents);
        $product->setPosition($category->getProducts()->count());
        $product->setActive($publish && is_numeric($price) && !$isDuplicate);
        $product->setImportDuplicate($isDuplicate);
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
            $aiSuggested = ($ingredientData['from_name'] ?? false) === true;
            $this->linkIngredient($product, $ingredientName, $restaurant, $locale, $position, $aiSuggested);
            $position++;
            $ingredientsLinked++;
        }

        // Built only from *this* $productData slice — never from another
        // dish's markers — so tags can never leak across products in the
        // same batch/page.
        foreach ((is_array($productData['dietary_markers'] ?? null) ? $productData['dietary_markers'] : []) as $markerData) {
            if (($markerData['uncertain'] ?? false) === true) {
                continue;
            }
            // Defensive normalization only — the model is restricted to a
            // small fixed vocabulary (see MenuVisionPromptBuilder), so this
            // just guards against stray casing/whitespace, never fuzzy-matches.
            $code = mb_strtolower(trim((string) ($markerData['code'] ?? '')));
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

        // "recommended" is deliberately never reachable through the generic
        // dietary_markers loop above (see the hard guard there) — this is
        // the one narrow, explicit-only path for it, driven only by this
        // dish's own "recommended" flag (default false — see
        // MenuVisionPromptBuilder rule 10), never inferred.
        if (($productData['recommended'] ?? false) === true) {
            $recommendedTag = $this->em->getRepository(ProductTag::class)->findOneBy([
                'restaurant' => $restaurant,
                'code' => 'recommended',
                'isSystem' => true,
            ]);
            if ($recommendedTag !== null) {
                $product->addTag($recommendedTag);
                $tagsAssigned++;
            }
        }

        return $product;
    }

    /** Exactly MenuAdminController::saveProduct()'s 3-tier resolution for a typed ingredient name, reused rather than reimplemented — plus the position this call's ingredient occupies in the printed/entered order. */
    private function linkIngredient(Product $product, string $name, Restaurant $restaurant, string $locale, int $position, bool $aiSuggested = false): void
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
            $this->attachIngredient($product, $ingredient, $position, $aiSuggested);
            return;
        }

        $globalIngredient = $this->globalIngredientRepository->findUnambiguousByName($name, $locale);
        if ($globalIngredient) {
            $this->attachGlobalIngredient($product, $globalIngredient, $position, $aiSuggested);
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

        $this->attachIngredient($product, $ingredient, $position, $aiSuggested);
    }

    private function attachIngredient(Product $product, Ingredient $ingredient, int $position, bool $aiSuggested = false): void
    {
        $link = new ProductIngredient();
        $link->setAiSuggested($aiSuggested);
        $link->setIngredient($ingredient);
        $link->setPosition($position);
        $product->addIngredientLink($link);
        $this->em->persist($link);
    }

    private function attachGlobalIngredient(Product $product, GlobalIngredient $globalIngredient, int $position, bool $aiSuggested = false): void
    {
        $link = new ProductGlobalIngredient();
        $link->setAiSuggested($aiSuggested);
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
