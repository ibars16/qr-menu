<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named allergen-labeling regime (e.g. "eu" for the 14 EU/UK FIC
 * allergens). Most allergens are shared across regimes — milk is milk
 * everywhere — so this is a many-to-many tag on Allergen rather than a
 * separate allergen list per region: a future region-specific addition is
 * just a new Allergen row tagged to its own scheme, with no redesign.
 *
 * Not translated — this is an internal/admin categorization, not shown to
 * customers directly.
 */
#[ORM\Entity]
class RegulatoryScheme
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Stable natural key, e.g. "eu". */
    #[ORM\Column(length: 30, unique: true)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\ManyToMany(targetEntity: Allergen::class, mappedBy: 'regulatorySchemes')]
    private Collection $allergens;

    public function __construct()
    {
        $this->allergens = new ArrayCollection();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getAllergens(): Collection
    {
        return $this->allergens;
    }
}
