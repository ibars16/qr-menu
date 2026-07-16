<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Links a Product to an entry in the shared, application-managed Global
 * Ingredient Library, in a specific position — see ProductIngredient's
 * class docblock for why this carries a position at all, and why it's a
 * separate entity from ProductIngredient rather than a shared/polymorphic
 * link (the same reasoning Product::$ingredients / $globalIngredients being
 * separate collections already follows).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'unique_product_global_ingredient', columns: ['product_id', 'global_ingredient_id'])]
class ProductGlobalIngredient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'globalIngredientLinks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: GlobalIngredient::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GlobalIngredient $globalIngredient;

    /** Zero-based position in the printed/entered order — see ProductIngredient's class docblock. */
    #[ORM\Column]
    private int $position = 0;

    /** True when named literally in the dish's own name rather than an explicit printed list — see ProductIngredient::$aiSuggested. */
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

    public function getGlobalIngredient(): GlobalIngredient
    {
        return $this->globalIngredient;
    }

    public function setGlobalIngredient(GlobalIngredient $globalIngredient): void
    {
        $this->globalIngredient = $globalIngredient;
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
