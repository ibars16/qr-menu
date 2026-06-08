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

    #[ORM\Column(nullable: true)]
    private ?int $calories = null;

    /** Spicy level from 0 (not spicy) to 5 (extremely spicy) */
    #[ORM\Column(nullable: true)]
    private ?int $spicyLevel = null;

    // --- Dietary labels ---

    #[ORM\Column]
    private bool $vegetarian = false;

    #[ORM\Column]
    private bool $vegan = false;

    #[ORM\Column]
    private bool $glutenFree = false;

    #[ORM\Column]
    private bool $lactoseFree = false;

    #[ORM\Column]
    private bool $containsNuts = false;

    #[ORM\Column]
    private bool $halal = false;

    #[ORM\Column]
    private bool $kosher = false;

    // --- Visibility & ordering ---

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private int $position = 0;

    // --- Relations ---

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductTranslation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    #[ORM\ManyToMany(targetEntity: Ingredient::class, inversedBy: 'products')]
    #[ORM\JoinTable(name: 'product_ingredient')]
    private Collection $ingredients;

    /**
     * Temporary price after currency conversion.
     * NOT mapped to database — calculated at runtime in MenuController.
     */
    private int $convertedPrice = 0;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->ingredients  = new ArrayCollection();
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

    public function isVegetarian(): bool
    {
        return $this->vegetarian;
    }

    public function setVegetarian(bool $vegetarian): void
    {
        $this->vegetarian = $vegetarian;
    }

    public function isVegan(): bool
    {
        return $this->vegan;
    }

    public function setVegan(bool $vegan): void
    {
        $this->vegan = $vegan;
    }

    public function isGlutenFree(): bool
    {
        return $this->glutenFree;
    }

    public function setGlutenFree(bool $glutenFree): void
    {
        $this->glutenFree = $glutenFree;
    }

    public function isLactoseFree(): bool
    {
        return $this->lactoseFree;
    }

    public function setLactoseFree(bool $lactoseFree): void
    {
        $this->lactoseFree = $lactoseFree;
    }

    public function isContainsNuts(): bool
    {
        return $this->containsNuts;
    }

    public function setContainsNuts(bool $containsNuts): void
    {
        $this->containsNuts = $containsNuts;
    }

    public function isHalal(): bool
    {
        return $this->halal;
    }

    public function setHalal(bool $halal): void
    {
        $this->halal = $halal;
    }

    public function isKosher(): bool
    {
        return $this->kosher;
    }

    public function setKosher(bool $kosher): void
    {
        $this->kosher = $kosher;
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

    public function getIngredients(): Collection
    {
        return $this->ingredients;
    }

    public function addIngredient(Ingredient $ingredient): void
    {
        if (!$this->ingredients->contains($ingredient)) {
            $this->ingredients->add($ingredient);
        }
    }

    public function removeIngredient(Ingredient $ingredient): void
    {
        $this->ingredients->removeElement($ingredient);
    }
}
