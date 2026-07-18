<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One labeled price variant for a Product beyond its own $basePrice — e.g.
 * "Tapa 3,00€", "Media ración 5,00€", "Ración 8,00€". Exists (rather than a
 * handful of numbered columns on Product) for the same reason
 * ProductIngredient does: the printed order across variants is meaningful
 * and owner-controlled, and a fixed set of columns can't carry that — see
 * ProductIngredient's own class docblock for the identical reasoning.
 *
 * $basePrice/$basePriceLabel on Product is always the first price shown;
 * these rows are always the ones after it, in $position order. A Product
 * with no rows here and a null $basePriceLabel renders as a single plain
 * price, exactly as before this feature existed — see
 * Product::hasPriceVariants().
 */
#[ORM\Entity]
class ProductPriceVariant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'priceVariants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    /** Free text, the restaurant's own language, e.g. "Tapa", "Media ración", "Ración". Never blank. */
    #[ORM\Column(length: 40)]
    private string $label;

    /** Cents — always the full absolute price for this variant, never a delta from $basePrice. */
    #[ORM\Column]
    private int $price;

    /** Zero-based print order, after $basePrice — see the class docblock. */
    #[ORM\Column]
    private int $position = 0;

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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function setPrice(int $price): void
    {
        $this->price = $price;
    }

    /** Returns the price as a decimal. Example: 300 → 3.00 */
    public function getPriceDecimal(): float
    {
        return $this->price / 100;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
