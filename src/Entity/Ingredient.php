<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Repository\IngredientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IngredientRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Ingredient
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Unique code scoped to the restaurant.
     * Example: "cheese", "bacon", "flour"
     * Combined with restaurant_id forms a unique identifier.
     */
    #[ORM\Column(length: 100)]
    private string $code;

    /**
     * Each ingredient belongs to a restaurant.
     * This ensures tenant isolation in a multi-restaurant SaaS.
     */
    #[ORM\ManyToOne(targetEntity: Restaurant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Restaurant $restaurant;

    #[ORM\OneToMany(mappedBy: 'ingredient', targetEntity: IngredientTranslation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    #[ORM\ManyToMany(targetEntity: Product::class, mappedBy: 'ingredients')]
    private Collection $products;

    /**
     * The allergens this private ingredient carries — set by hand by the
     * restaurant owner (there is no taxonomy to derive it from, unlike
     * GlobalIngredient). Source of truth for ProductAllergenResolver.
     */
    #[ORM\OneToMany(mappedBy: 'ingredient', targetEntity: IngredientAllergen::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $allergenLinks;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->products = new ArrayCollection();
        $this->allergenLinks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getRestaurant(): Restaurant
    {
        return $this->restaurant;
    }

    public function setRestaurant(Restaurant $restaurant): void
    {
        $this->restaurant = $restaurant;
    }

    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(IngredientTranslation $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setIngredient($this);
        }
    }

    public function removeTranslation(IngredientTranslation $translation): void
    {
        $this->translations->removeElement($translation);
    }

    public function getTranslation(string $locale): ?IngredientTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }

        return null;
    }

    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function getAllergenLinks(): Collection
    {
        return $this->allergenLinks;
    }

    public function addAllergenLink(IngredientAllergen $link): void
    {
        if (!$this->allergenLinks->contains($link)) {
            $this->allergenLinks->add($link);
            $link->setIngredient($this);
        }
    }

    public function removeAllergenLink(IngredientAllergen $link): void
    {
        $this->allergenLinks->removeElement($link);
    }

    public function getAllergenLink(Allergen $allergen): ?IngredientAllergen
    {
        foreach ($this->allergenLinks as $link) {
            if ($link->getAllergen() === $allergen) {
                return $link;
            }
        }

        return null;
    }
}
