<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A group header inside a fixed-price menu category's dish list (Primeros /
 * Segundos / Postres…). Deliberately NOT a hierarchy level in the public
 * data model sense — a menu is still just a Category with $menuPrice set —
 * but every Product belonging to a menu category is REQUIRED to point at
 * one via Product::$menuSection (enforced in the admin: a freshly created
 * menu always gets a default "Platos" section, and "+ Añadir plato" is only
 * ever offered inside a section). Normal-category products always have
 * $menuSection === null.
 *
 * Deleting a section cascades to delete its products too (ON DELETE CASCADE
 * on Product::$menuSection's join column, plus the identical ORM-level
 * cascade below) — mirroring how deleting a Category already cascades to
 * delete its Products; see MenusController::deleteSection() for the
 * confirm-dialog UX this backs.
 *
 * $label is free text in the restaurant's own content language — same
 * single-language, no-auto-translate convention as
 * Product::$basePriceLabel and Category::$menuDescription.
 */
#[ORM\Entity]
class MenuSection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'menuSections')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Category $category;

    #[ORM\Column(length: 100)]
    private string $label;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\OneToMany(mappedBy: 'menuSection', targetEntity: Product::class, cascade: ['remove'])]
    private Collection $products;

    public function __construct()
    {
        $this->products = new ArrayCollection();
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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): void
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->setMenuSection($this);
        }
    }

    public function removeProduct(Product $product): void
    {
        $this->products->removeElement($product);
    }

    /** @return Product[] this section's own dishes, in position order (position is scoped per-section, not per-category) */
    public function getProductsSorted(): array
    {
        $products = $this->products->toArray();
        usort($products, static fn(Product $a, Product $b) => $a->getPosition() <=> $b->getPosition());

        return $products;
    }
}
