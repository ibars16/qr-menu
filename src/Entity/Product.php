<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Enum\MenuHiddenReason;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Product
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: false)]
    private Category $category;

    /**
     * Non-null only for products belonging to a fixed-price menu category
     * (Category::$menuPrice !== null) — every such product is required to
     * have one (see MenuSection's docblock: a freshly created menu always
     * gets a default section, and the admin never offers "+ Añadir plato"
     * without a section context). Always null for a normal category's
     * products. $position below is then scoped to siblings within this
     * section, not to the whole category — see MenuSection::getProductsSorted().
     */
    #[ORM\ManyToOne(targetEntity: MenuSection::class, inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?MenuSection $menuSection = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    /**
     * A short (<=10s), silent, looping clip — an optional layer on top of
     * $image, never a replacement for it. $image stays the universal
     * poster/fallback (admin list thumbnail, set-menu thumbnail, and the
     * <video poster> itself) read by everything that already reads it,
     * unchanged; only the live dish-card/detail-sheet render points branch
     * on this being set. See MenuAdminController::uploadProductClip().
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $videoClip = null;

    /**
     * Price stored in cents to avoid floating point precision issues.
     * Example: 1250 = 12.50 in the restaurant's base currency.
     */
    #[ORM\Column]
    private int $basePrice;

    /**
     * Extra charge on top of a fixed-price menu ("menú del día"), in cents —
     * e.g. "suplemento 1.50€". Null for a normally-priced dish. Kept
     * completely separate from $basePrice: see MenuVisionPromptBuilder for
     * how this gets extracted and why it's never folded into the base price.
     */
    #[ORM\Column(nullable: true)]
    private ?int $supplementPrice = null;

    /**
     * Label for $basePrice, shown only once this product has more than one
     * price — e.g. "Ración" when $priceVariants also has "Tapa"/"Media
     * ración" rows. Null (the default, and the case for the vast majority
     * of products) means this product has a single, unlabeled price:
     * $basePrice renders alone, exactly as it always has. See
     * ProductPriceVariant's class docblock and Product::hasPriceVariants().
     */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $basePriceLabel = null;

    #[ORM\Column(nullable: true)]
    private ?int $calories = null;

    /**
     * Nutritional facts, grams per dish — manually entered by the owner in
     * admin (Fase 2, not yet built as of this migration), rendered only on
     * the maison theme's dish detail (Fase 3, not yet built either). All
     * four are nullable and independent of each other and of $calories: a
     * dish can have any subset filled in, and the vast majority will have
     * none of them set until an owner fills them in — "no data" must never
     * render as a zero or an empty panel slot (see the maison no-photo
     * precedent: absence renders nothing, not a placeholder).
     * Unrelated to $allergenOverrides/$ingredients — those feed the
     * separately computed allergen system (ProductAllergenResolver); these
     * four are plain, uncomputed manual data entry, same category as
     * $calories above.
     *
     * decimal (NUMERIC(5,1)), not float: these are typed-in-by-hand,
     * displayed-verbatim values (nutrition-label style, always one fixed
     * decimal), not arithmetic — decimal avoids float representation
     * artifacts (6.5 rendering as 6.4999...) and Doctrine's decimal type
     * intentionally returns a string, never a float, for exactly that
     * reason. Keep it a string end to end (Fase 3 prints it as-is) rather
     * than casting to float, which would silently reintroduce the problem
     * this type was chosen to avoid. $calories above stays a plain ?int —
     * whole kcal, no decimal, unaffected by this.
     */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 1, nullable: true)]
    private ?string $fat = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 1, nullable: true)]
    private ?string $protein = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 1, nullable: true)]
    private ?string $carbohydrates = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 1, nullable: true)]
    private ?string $sugars = null;

    /**
     * Whether the nutrition facts above render on the public menu at all.
     * Defaults true (and stays true on every pre-existing row via the
     * migration's DEFAULT TRUE) so introducing this flag never silently
     * hides data an owner already filled in and was already showing —
     * it only ever changes anything once an owner explicitly unchecks
     * "Mostrar en la carta pública" in admin. Independent of whether the
     * admin form's own nutrition section is expanded/collapsed — that's
     * only a UI convenience local to the editor (see toggleNutrition() in
     * _product_js.html.twig) with no bearing on what the customer sees.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $nutritionVisible = true;

    /** Spicy level from 0 (not spicy) to 5 (extremely spicy) */
    #[ORM\Column(nullable: true)]
    private ?int $spicyLevel = null;

    // --- Dietary labels ---

    #[ORM\ManyToMany(
        targetEntity: ProductTag::class,
        inversedBy: 'products'
    )]
    #[ORM\JoinTable(name: 'product_tag_assignment')]
    private Collection $tags;

    // --- Visibility & ordering ---

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private int $position = 0;

    // --- AI menu import provenance ---

    /**
     * Which import created this row, if any — null for anything manually
     * entered, always. ON DELETE SET NULL rather than CASCADE: a product
     * the owner already confirmed (needsReview = false, active = true) must
     * never be deleted just because a "discard the rest of this batch"
     * action removes the batch it happened to come from.
     */
    #[ORM\ManyToOne(targetEntity: MenuImportBatch::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MenuImportBatch $importBatch = null;

    /** True only while this row is AI-extracted and not yet confirmed by the owner. Never true for a manually-created product. */
    #[ORM\Column]
    private bool $needsReview = false;

    /** The model's own confidence for this row while needsReview is true — meaningless once confirmed. */
    #[ORM\Column(nullable: true)]
    private ?float $aiConfidence = null;

    // --- Relations ---

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductTranslation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    /**
     * Ordered links to the restaurant's own private Ingredients — see
     * ProductIngredient's class docblock for why this is a join entity
     * carrying an explicit position rather than a bare ManyToMany (a plain
     * join table has no ordering guarantee at all, and the printed menu
     * order is meaningful). Always read through getIngredients(), which
     * returns the Ingredients themselves in position order.
     */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductIngredient::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $ingredientLinks;

    /**
     * Ordered links to entries in the shared, application-managed Global
     * Ingredient Library (see GlobalIngredient) — deliberately a separate
     * collection from $ingredientLinks, the restaurant's own private
     * ingredients: the two sources must never be conflated. Always read
     * through getGlobalIngredients(), which returns the GlobalIngredients
     * themselves in position order.
     */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductGlobalIngredient::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $globalIngredientLinks;

    /**
     * Deliberate, human-set exceptions to this product's computed allergen
     * list — see ProductAllergenOverride. Empty for the large majority of
     * products; the effective allergen set is computed from $ingredients
     * and $globalIngredients by ProductAllergenResolver, with these layered
     * on top.
     */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductAllergenOverride::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $allergenOverrides;

    /**
     * Additional labeled prices beyond $basePrice — e.g. "Tapa"/"Media
     * ración" alongside $basePrice's own "Ración". Empty for the vast
     * majority of products. See ProductPriceVariant's class docblock for
     * why this is an ordered child entity rather than numbered columns.
     */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductPriceVariant::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $priceVariants;

    /**
     * Temporary price after currency conversion.
     * NOT mapped to database — calculated at runtime in MenuController.
     */
    private int $convertedPrice = 0;

    /**
     * Converted prices for $priceVariants, keyed by ProductPriceVariant id.
     * NOT mapped to database — calculated at runtime in MenuController,
     * exactly like $convertedPrice above. See getConvertedVariantPrice().
     */
    private array $convertedVariantPrices = [];

    /**
     * Converted $supplementPrice, cents. NOT mapped to database — calculated
     * at runtime in MenuController, exactly like $convertedPrice. Only
     * meaningful when $supplementPrice is set.
     */
    private int $convertedSupplementPrice = 0;

    public function __construct()
    {
        $this->translations           = new ArrayCollection();
        $this->ingredientLinks        = new ArrayCollection();
        $this->globalIngredientLinks  = new ArrayCollection();
        $this->tags                   = new ArrayCollection();
        $this->allergenOverrides      = new ArrayCollection();
        $this->priceVariants          = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): void
    {
        $this->category = $category;
    }

    public function getMenuSection(): ?MenuSection
    {
        return $this->menuSection;
    }

    public function setMenuSection(?MenuSection $menuSection): void
    {
        $this->menuSection = $menuSection;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): void
    {
        $this->image = $image;
    }

    public function getVideoClip(): ?string
    {
        return $this->videoClip;
    }

    public function setVideoClip(?string $videoClip): void
    {
        $this->videoClip = $videoClip;
    }

    public function getBasePrice(): int
    {
        return $this->basePrice;
    }

    public function setBasePrice(int $basePrice): void
    {
        $this->basePrice = $basePrice;
    }

    /** Returns the base price as a decimal. Example: 1250 → 12.50 */
    public function getBasePriceDecimal(): float
    {
        return $this->basePrice / 100;
    }

    public function getSupplementPrice(): ?int
    {
        return $this->supplementPrice;
    }

    public function setSupplementPrice(?int $supplementPrice): void
    {
        $this->supplementPrice = $supplementPrice;
    }

    /** Returns the supplement price as a decimal, or null if this dish carries none. Example: 150 → 1.50 */
    public function getSupplementPriceDecimal(): ?float
    {
        return $this->supplementPrice !== null ? $this->supplementPrice / 100 : null;
    }

    /** Falls back to the raw supplement if conversion hasn't been applied yet — mirrors getConvertedPrice(). Null when this dish carries no supplement at all. */
    public function getConvertedSupplementPrice(): ?int
    {
        if ($this->supplementPrice === null) {
            return null;
        }

        return $this->convertedSupplementPrice > 0 ? $this->convertedSupplementPrice : $this->supplementPrice;
    }

    public function setConvertedSupplementPrice(int $price): void
    {
        $this->convertedSupplementPrice = $price;
    }

    public function getBasePriceLabel(): ?string
    {
        return $this->basePriceLabel;
    }

    public function setBasePriceLabel(?string $basePriceLabel): void
    {
        $this->basePriceLabel = $basePriceLabel;
    }

    /**
     * Returns the converted price in cents.
     * Falls back to basePrice if conversion has not been applied yet.
     */
    public function getConvertedPrice(): int
    {
        return $this->convertedPrice > 0 ? $this->convertedPrice : $this->basePrice;
    }

    public function setConvertedPrice(int $price): void
    {
        $this->convertedPrice = $price;
    }

    public function getPriceVariants(): Collection
    {
        return $this->priceVariants;
    }

    public function addPriceVariant(ProductPriceVariant $variant): void
    {
        if (!$this->priceVariants->contains($variant)) {
            $this->priceVariants->add($variant);
            $variant->setProduct($this);
        }
    }

    public function removePriceVariant(ProductPriceVariant $variant): void
    {
        $this->priceVariants->removeElement($variant);
    }

    /** True once this product has more than one price to show — see ProductPriceVariant's class docblock. */
    public function hasPriceVariants(): bool
    {
        return $this->basePriceLabel !== null || !$this->priceVariants->isEmpty();
    }

    /** Falls back to the variant's own raw price if conversion hasn't been applied yet — mirrors getConvertedPrice(). */
    public function getConvertedVariantPrice(ProductPriceVariant $variant): int
    {
        return $this->convertedVariantPrices[$variant->getId()] ?? $variant->getPrice();
    }

    /** @param array<int, int> $convertedVariantPrices keyed by ProductPriceVariant id */
    public function setConvertedVariantPrices(array $convertedVariantPrices): void
    {
        $this->convertedVariantPrices = $convertedVariantPrices;
    }

    public function getCalories(): ?int
    {
        return $this->calories;
    }

    public function setCalories(?int $calories): void
    {
        $this->calories = $calories;
    }

    public function getFat(): ?string
    {
        return $this->fat;
    }

    public function setFat(?string $fat): void
    {
        $this->fat = $fat;
    }

    public function getProtein(): ?string
    {
        return $this->protein;
    }

    public function setProtein(?string $protein): void
    {
        $this->protein = $protein;
    }

    public function getCarbohydrates(): ?string
    {
        return $this->carbohydrates;
    }

    public function setCarbohydrates(?string $carbohydrates): void
    {
        $this->carbohydrates = $carbohydrates;
    }

    public function getSugars(): ?string
    {
        return $this->sugars;
    }

    public function setSugars(?string $sugars): void
    {
        $this->sugars = $sugars;
    }

    public function isNutritionVisible(): bool
    {
        return $this->nutritionVisible;
    }

    public function setNutritionVisible(bool $nutritionVisible): void
    {
        $this->nutritionVisible = $nutritionVisible;
    }

    public function getSpicyLevel(): ?int
    {
        return $this->spicyLevel;
    }

    public function setSpicyLevel(?int $spicyLevel): void
    {
        $this->spicyLevel = $spicyLevel;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    /**
     * Safety net against a dish reaching the public menu priced at €0 —
     * almost always a data-entry slip (a blank price field defaulting to
     * 0, a half-finished AI import row) rather than an intentional free
     * item, so it's held back rather than risk a customer seeing, or
     * ordering, a €0.00 dish. Checked in addition to $active everywhere
     * the public menu (and Smart Waiter, which must never offer a dish the
     * menu itself wouldn't show) lists a category's dishes — see
     * Category::getActiveProductsSorted().
     *
     * $menuSection !== null is exempt: a fixed-price-menu dish is
     * legitimately priced at 0 (its cost is covered by the menu's own
     * price — see $menuSection's docblock), so this only ever gates a
     * normal, individually-priced dish.
     */
    public function isSafeToDisplay(): bool
    {
        return $this->menuSection !== null || $this->basePrice > 0;
    }

    /**
     * Single read-only answer to "is this dish on the public menu?" — null
     * when it is, otherwise the FIRST reason it isn't. It mirrors, and does
     * not replace, the checks the public render applies today (see
     * MenuController::renderMenu(), Category::getActiveProductsSorted() /
     * getActiveSectionsWithProducts(), show.html.twig, _set_menu.html.twig):
     * category active, dish active, isSafeToDisplay() (a fixed-price-menu
     * dish is already exempt from the €0 rule there, so this needs no
     * special case), and a translation to show.
     *
     * The translation check is locale-independent on purpose: every
     * template resolves the name as exact locale → restaurant default →
     * translations.first, falling through to whichever translation object
     * it finds first regardless of its own text. A translation ROW existing
     * is therefore not enough — checked and confirmed 2026-09-25 that a row
     * with an empty string name (only reachable today via something writing
     * directly to product_translation/category_translation outside
     * MenuAdminController::saveProduct(), which has required a non-blank
     * name since this same date) used to read as "has a translation" here,
     * so a dish in that state passed this check, showed no admin notice,
     * and rendered on the public menu with a blank name (show.html.twig /
     * _set_menu.html.twig's own `{% if pT %}` has the identical gap — see
     * their own fix, same date). The rule is therefore "has any translation
     * with actual text", for the dish and for its category, not merely
     * "has any translation".
     *
     * Reasons are ordered outermost first (category, dish, price,
     * translation), so a dish in a hidden category reports that even if it
     * is also hidden itself.
     */
    public function menuHiddenReason(): ?MenuHiddenReason
    {
        if (!$this->category->isActive()) {
            return MenuHiddenReason::CategoryHidden;
        }
        if (!$this->active) {
            return MenuHiddenReason::ProductHidden;
        }
        if (!$this->isSafeToDisplay()) {
            return MenuHiddenReason::PriceZero;
        }
        if (!$this->hasMenuName() || !self::hasNamedTranslation($this->category->getTranslations())) {
            return MenuHiddenReason::NoTranslation;
        }

        return null;
    }

    /**
     * Whether this dish has a name the public menu can show. Checked in
     * addition to $active/isSafeToDisplay() everywhere the public menu and
     * Smart Waiter list dishes, and by menuHiddenReason().
     *
     * The restaurant's default language is the one the owner edits (and
     * the one the admin Carta row shows), so when a translation row exists
     * for it, ITS name decides: a blank default-language name hides the
     * dish in every locale, even if an AI/other-language translation has
     * text — otherwise the owner sees a nameless row with no notice while
     * the dish is live in other languages (confirmed 2026-09-25 on a dish
     * with a blank `es` name and a named `en` one). With no row for the
     * default language at all (e.g. it was changed after import), any
     * translation with text is enough — same fallback as
     * getDisplayTranslation().
     */
    public function hasMenuName(): bool
    {
        $default = $this->getTranslation($this->category->getRestaurant()->getDefaultLanguage());
        if ($default !== null) {
            return trim($default->getName()) !== '';
        }

        return self::hasNamedTranslation($this->translations);
    }

    /** @param Collection<int, ProductTranslation|CategoryTranslation> $translations */
    private static function hasNamedTranslation(Collection $translations): bool
    {
        foreach ($translations as $translation) {
            if (trim($translation->getName()) !== '') {
                return true;
            }
        }

        return false;
    }

    public function isShownOnMenu(): bool
    {
        return $this->menuHiddenReason() === null;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getImportBatch(): ?MenuImportBatch
    {
        return $this->importBatch;
    }

    public function setImportBatch(?MenuImportBatch $importBatch): void
    {
        $this->importBatch = $importBatch;
    }

    public function isNeedsReview(): bool
    {
        return $this->needsReview;
    }

    public function setNeedsReview(bool $needsReview): void
    {
        $this->needsReview = $needsReview;
    }

    public function getAiConfidence(): ?float
    {
        return $this->aiConfidence;
    }

    public function setAiConfidence(?float $aiConfidence): void
    {
        $this->aiConfidence = $aiConfidence;
    }

    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(ProductTranslation $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setProduct($this);
        }
    }

    public function removeTranslation(ProductTranslation $translation): void
    {
        $this->translations->removeElement($translation);
    }

    public function getTranslation(string $locale): ?ProductTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }

        return null;
    }

    /**
     * Like getTranslation(), but for read-only display: falls back to any
     * other translation this product has (preferring a human-authored one)
     * rather than showing nothing when $preferredLocale is missing — e.g. the
     * restaurant's default language was changed after import and no
     * translation exists for it yet.
     */
    public function getDisplayTranslation(string $preferredLocale): ?ProductTranslation
    {
        $exact = $this->getTranslation($preferredLocale);
        if ($exact !== null) {
            return $exact;
        }

        $fallback = null;
        foreach ($this->translations as $translation) {
            if (!$translation->isAiGenerated()) {
                return $translation;
            }
            $fallback ??= $translation;
        }

        return $fallback;
    }

    public function getIngredientLinks(): Collection
    {
        return $this->ingredientLinks;
    }

    public function addIngredientLink(ProductIngredient $link): void
    {
        if (!$this->ingredientLinks->contains($link)) {
            $this->ingredientLinks->add($link);
            $link->setProduct($this);
        }
    }

    public function removeIngredientLink(ProductIngredient $link): void
    {
        $this->ingredientLinks->removeElement($link);
    }

    /** @return Ingredient[] the restaurant's own private ingredients, in printed/entered order */
    public function getIngredients(): array
    {
        return array_map(static fn (ProductIngredient $link) => $link->getIngredient(), $this->ingredientLinks->toArray());
    }

    public function getGlobalIngredientLinks(): Collection
    {
        return $this->globalIngredientLinks;
    }

    public function addGlobalIngredientLink(ProductGlobalIngredient $link): void
    {
        if (!$this->globalIngredientLinks->contains($link)) {
            $this->globalIngredientLinks->add($link);
            $link->setProduct($this);
        }
    }

    public function removeGlobalIngredientLink(ProductGlobalIngredient $link): void
    {
        $this->globalIngredientLinks->removeElement($link);
    }

    /** @return GlobalIngredient[] the shared-library ingredients, in printed/entered order */
    public function getGlobalIngredients(): array
    {
        return array_map(static fn (ProductGlobalIngredient $link) => $link->getGlobalIngredient(), $this->globalIngredientLinks->toArray());
    }

    /**
     * Resolved, display-ready ingredient names spanning BOTH sources
     * ($ingredientLinks and $globalIngredientLinks), merged by their shared
     * position column so the printed/entered order is respected across the
     * combined list rather than within each source separately (see
     * MenuAdminController::getProduct(), which merges the same way for the
     * admin panel). Each name falls back from $locale to $fallbackLocale to
     * English (the one language the global library always has), then to the
     * ingredient's raw code if it somehow has no translation at all.
     *
     * @return string[]
     */
    public function getIngredientNames(string $locale, string $fallbackLocale): array
    {
        $entries = [];

        foreach ($this->ingredientLinks as $link) {
            $ing = $link->getIngredient();
            $t = $ing->getTranslation($locale) ?? $ing->getTranslation($fallbackLocale) ?? $ing->getTranslation('en');
            $entries[] = ['position' => $link->getPosition(), 'name' => $t?->getName() ?? $ing->getCode()];
        }

        foreach ($this->globalIngredientLinks as $link) {
            $gIng = $link->getGlobalIngredient();
            $t = $gIng->getTranslation($locale) ?? $gIng->getTranslation($fallbackLocale) ?? $gIng->getTranslation('en');
            $entries[] = ['position' => $link->getPosition(), 'name' => $t?->getName() ?? $gIng->getCode()];
        }

        usort($entries, static fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return array_map(static fn (array $entry) => $entry['name'], $entries);
    }

    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(ProductTag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
    }

    public function removeTag(ProductTag $tag): void
    {
        $this->tags->removeElement($tag);
    }

    public function getAllergenOverrides(): Collection
    {
        return $this->allergenOverrides;
    }

    public function addAllergenOverride(ProductAllergenOverride $override): void
    {
        if (!$this->allergenOverrides->contains($override)) {
            $this->allergenOverrides->add($override);
            $override->setProduct($this);
        }
    }

    public function removeAllergenOverride(ProductAllergenOverride $override): void
    {
        $this->allergenOverrides->removeElement($override);
    }

    public function getAllergenOverride(Allergen $allergen): ?ProductAllergenOverride
    {
        foreach ($this->allergenOverrides as $override) {
            if ($override->getAllergen() === $allergen) {
                return $override;
            }
        }

        return null;
    }
}
