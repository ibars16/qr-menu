<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

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

    #[ORM\Column(nullable: true)]
    private ?int $calories = null;

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
     * Temporary price after currency conversion.
     * NOT mapped to database — calculated at runtime in MenuController.
     */
    private int $convertedPrice = 0;

    public function __construct()
    {
        $this->translations           = new ArrayCollection();
        $this->ingredientLinks        = new ArrayCollection();
        $this->globalIngredientLinks  = new ArrayCollection();
        $this->tags                   = new ArrayCollection();
        $this->allergenOverrides      = new ArrayCollection();
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

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): void
    {
        $this->image = $image;
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

    public function getCalories(): ?int
    {
        return $this->calories;
    }

    public function setCalories(?int $calories): void
    {
        $this->calories = $calories;
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
