<?php

namespace App\Entity;

use App\Enum\AllergenPresence;
use Doctrine\ORM\Mapping as ORM;

/**
 * Links a restaurant's own private ingredient to an allergen it carries.
 * There is no taxonomy to derive this from — unlike GlobalIngredient, a
 * private ingredient's allergens are always set by hand by the restaurant
 * owner. See GlobalIngredientAllergen for why this is a separate entity
 * rather than a shared/polymorphic link.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'unique_ingredient_allergen', columns: ['ingredient_id', 'allergen_id'])]
class IngredientAllergen
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ingredient::class, inversedBy: 'allergenLinks')]
    #[ORM\JoinColumn(nullable: false)]
    private Ingredient $ingredient;

    #[ORM\ManyToOne(targetEntity: Allergen::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Allergen $allergen;

    #[ORM\Column(length: 20, enumType: AllergenPresence::class)]
    private AllergenPresence $presence = AllergenPresence::CONTAINS;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIngredient(): Ingredient
    {
        return $this->ingredient;
    }

    public function setIngredient(Ingredient $ingredient): void
    {
        $this->ingredient = $ingredient;
    }

    public function getAllergen(): Allergen
    {
        return $this->allergen;
    }

    public function setAllergen(Allergen $allergen): void
    {
        $this->allergen = $allergen;
    }

    public function getPresence(): AllergenPresence
    {
        return $this->presence;
    }

    public function setPresence(AllergenPresence $presence): void
    {
        $this->presence = $presence;
    }
}
