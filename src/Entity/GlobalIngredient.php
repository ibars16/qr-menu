<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Repository\GlobalIngredientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single entry in the application-wide ingredient library, shared by every
 * restaurant. Managed only by the application (via the importer commands
 * under src/Command/GlobalIngredients) — restaurants can search and use these,
 * but never create, edit, or delete them. Completely separate from
 * Ingredient, which holds each restaurant's own private ingredients.
 */
#[ORM\Entity(repositoryClass: GlobalIngredientRepository::class)]
#[ORM\HasLifecycleCallbacks]
class GlobalIngredient
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Stable slug derived from the canonical English name (e.g. "extra-virgin-olive-oil").
     * Used as the natural key for idempotent re-imports — see GlobalIngredientImporter.
     */
    #[ORM\Column(length: 150, unique: true)]
    private string $code;

    #[ORM\OneToMany(mappedBy: 'globalIngredient', targetEntity: GlobalIngredientTranslation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    /**
     * The allergens this ingredient carries — app-managed, sourced from a
     * reliable reference mapping at import time, never invented. Source of
     * truth for ProductAllergenResolver.
     */
    #[ORM\OneToMany(mappedBy: 'globalIngredient', targetEntity: GlobalIngredientAllergen::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $allergenLinks;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
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

    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(GlobalIngredientTranslation $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setGlobalIngredient($this);
        }
    }

    public function removeTranslation(GlobalIngredientTranslation $translation): void
    {
        $this->translations->removeElement($translation);
    }

    public function getTranslation(string $locale): ?GlobalIngredientTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }

        return null;
    }

    public function getAllergenLinks(): Collection
    {
        return $this->allergenLinks;
    }

    public function addAllergenLink(GlobalIngredientAllergen $link): void
    {
        if (!$this->allergenLinks->contains($link)) {
            $this->allergenLinks->add($link);
            $link->setGlobalIngredient($this);
        }
    }

    public function removeAllergenLink(GlobalIngredientAllergen $link): void
    {
        $this->allergenLinks->removeElement($link);
    }

    public function getAllergenLink(Allergen $allergen): ?GlobalIngredientAllergen
    {
        foreach ($this->allergenLinks as $link) {
            if ($link->getAllergen() === $allergen) {
                return $link;
            }
        }

        return null;
    }
}
