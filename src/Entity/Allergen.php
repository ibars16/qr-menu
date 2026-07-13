<?php

namespace App\Entity;

use App\Repository\AllergenRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An entry in the application-managed allergen taxonomy (the 14 EU/UK FIC
 * allergens to start, extensible to other regulatory schemes — see
 * RegulatoryScheme). Entirely app-curated, like GlobalIngredient: restaurants
 * can tag their ingredients and products with these, but never create, edit,
 * or delete an Allergen itself — a safety taxonomy needs to stay consistent
 * across every restaurant, unlike the open-ended ingredient list.
 */
#[ORM\Entity(repositoryClass: AllergenRepository::class)]
class Allergen
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Stable natural key, e.g. "milk", "crustaceans" — used for idempotent seeding. */
    #[ORM\Column(length: 50, unique: true)]
    private string $code;

    #[ORM\Column(length: 10)]
    private string $icon = '';

    #[ORM\Column(length: 20)]
    private string $color = '#666666';

    #[ORM\Column]
    private int $position = 0;

    #[ORM\OneToMany(mappedBy: 'allergen', targetEntity: AllergenTranslation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    #[ORM\ManyToMany(targetEntity: RegulatoryScheme::class, inversedBy: 'allergens')]
    #[ORM\JoinTable(name: 'allergen_regulatory_scheme')]
    private Collection $regulatorySchemes;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->regulatorySchemes = new ArrayCollection();
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

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): void
    {
        $this->icon = $icon;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): void
    {
        $this->color = $color;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(AllergenTranslation $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setAllergen($this);
        }
    }

    public function removeTranslation(AllergenTranslation $translation): void
    {
        $this->translations->removeElement($translation);
    }

    public function getTranslation(string $locale): ?AllergenTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }

        return null;
    }

    public function getRegulatorySchemes(): Collection
    {
        return $this->regulatorySchemes;
    }

    public function addRegulatoryScheme(RegulatoryScheme $scheme): void
    {
        if (!$this->regulatorySchemes->contains($scheme)) {
            $this->regulatorySchemes->add($scheme);
        }
    }

    public function removeRegulatoryScheme(RegulatoryScheme $scheme): void
    {
        $this->regulatorySchemes->removeElement($scheme);
    }
}
