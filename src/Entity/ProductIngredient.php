<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Links a Product to one of the restaurant's own private Ingredients, in a
 * specific position. Exists (rather than a bare ManyToMany) specifically to
 * carry that position: the printed order on a menu is meaningful (see
 * MenuVisionPromptBuilder rule 3) and a bare join table has no ordering
 * guarantee at all — see ProductGlobalIngredient for the identical reasoning
 * on the Global Ingredient Library side, kept as a separate entity for the
 * same reason $ingredients and $globalIngredients are separate collections
 * on Product: the two sources must never be conflated.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'unique_product_ingredient', columns: ['product_id', 'ingredient_id'])]
class ProductIngredient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'ingredientLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: Ingredient::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ingredient $ingredient;

    /** Zero-based position in the printed/entered order — see the class docblock. */
    #[ORM\Column]
    private int $position = 0;

    /**
     * True when this ingredient wasn't in an explicit printed list, but named
     * literally in the dish's own commercial name (e.g. "Provolone tibio" ->
     * provolone) — see MenuVisionPromptBuilder. Never true for a manually
     * typed ingredient. Lets the admin ingredient panel flag it as
     * AI-suggested for the owner to confirm or reject, rather than treating
     * it the same as an ingredient the menu actually listed.
     */
    #[ORM\Column]
    private bool $aiSuggested = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): void
    {
        $this->product = $product;
    }

    public function getIngredient(): Ingredient
    {
        return $this->ingredient;
    }

    public function setIngredient(Ingredient $ingredient): void
    {
        $this->ingredient = $ingredient;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function isAiSuggested(): bool
    {
        return $this->aiSuggested;
    }

    public function setAiSuggested(bool $aiSuggested): void
    {
        $this->aiSuggested = $aiSuggested;
    }
}
