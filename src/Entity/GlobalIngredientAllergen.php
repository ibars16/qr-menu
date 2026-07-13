<?php

namespace App\Entity;

use App\Enum\AllergenPresence;
use Doctrine\ORM\Mapping as ORM;

/**
 * Links a Global Library ingredient to an allergen it carries — the source
 * of truth a product's allergens are computed from (see
 * ProductAllergenResolver). Kept as its own entity, separate from
 * IngredientAllergen, the same way GlobalIngredient and Ingredient are kept
 * fully separate everywhere else in this codebase, rather than one shared
 * polymorphic link table.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'unique_global_ingredient_allergen', columns: ['global_ingredient_id', 'allergen_id'])]
class GlobalIngredientAllergen
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GlobalIngredient::class, inversedBy: 'allergenLinks')]
    #[ORM\JoinColumn(nullable: false)]
    private GlobalIngredient $globalIngredient;

    #[ORM\ManyToOne(targetEntity: Allergen::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Allergen $allergen;

    #[ORM\Column(length: 20, enumType: AllergenPresence::class)]
    private AllergenPresence $presence = AllergenPresence::CONTAINS;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGlobalIngredient(): GlobalIngredient
    {
        return $this->globalIngredient;
    }

    public function setGlobalIngredient(GlobalIngredient $globalIngredient): void
    {
        $this->globalIngredient = $globalIngredient;
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
