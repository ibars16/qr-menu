<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: false)]
    private Category $category;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[ORM\Column]
    private int $basePrice;

    #[ORM\Column(nullable: true)]
    private ?int $calories = null;

    #[ORM\Column(nullable: true)]
    private ?int $spicyLevel = null;

    #[ORM\Column]
    private bool $vegetarian = false;

    #[ORM\Column]
    private bool $vegan = false;

    #[ORM\Column]
    private bool $glutenFree = false;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductTranslation::class, cascade: ['persist', 'remove'])]
    private Collection $translations;

    #[ORM\ManyToMany(targetEntity: Ingredient::class, inversedBy: 'products')]
    #[ORM\JoinTable(name: 'product_ingredient')]
    private Collection $ingredients;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->ingredients = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
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

    public function setTranslations(Collection $translations): void
    {
        $this->translations = $translations;
    }

    public function getIngredients(): Collection
    {
        return $this->ingredients;
    }

    public function setIngredients(Collection $ingredients): void
    {
        $this->ingredients = $ingredients;
    }


}
