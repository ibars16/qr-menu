<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Enum\AllergenPresence;
use Doctrine\ORM\Mapping as ORM;

/**
 * A deliberate, human-set exception to a product's computed allergen list —
 * empty for the large majority of products by design. Exists for two
 * distinct reasons: correcting a computed answer that ingredient data got
 * wrong, and capturing risk no ingredient could ever know about (a shared
 * fryer, a kitchen-specific cross-contamination risk).
 *
 * FREE_FROM is only ever written here, by a restaurant owner — it is never
 * computed automatically. See ProductAllergenResolver for how this is
 * layered on top of the computed set.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'unique_product_allergen_override', columns: ['product_id', 'allergen_id'])]
#[ORM\HasLifecycleCallbacks]
class ProductAllergenOverride
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'allergenOverrides')]
    #[ORM\JoinColumn(nullable: false)]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: Allergen::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Allergen $allergen;

    #[ORM\Column(length: 20, enumType: AllergenPresence::class)]
    private AllergenPresence $presence;

    /**
     * Why this override exists — required by the admin UI/API whenever
     * presence is FREE_FROM, since suppressing a computed allergen is the
     * one action here with real downside if done carelessly.
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $setBy = null;

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

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }

    public function getSetBy(): ?User
    {
        return $this->setBy;
    }

    public function setSetBy(?User $setBy): void
    {
        $this->setBy = $setBy;
    }
}
