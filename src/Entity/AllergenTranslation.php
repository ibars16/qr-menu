<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'unique_allergen_locale', columns: ['allergen_id', 'locale'])]
class AllergenTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Allergen::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false)]
    private Allergen $allergen;

    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column(length: 100)]
    private string $name;

    /** Short admin-facing help text; also useful as something the Smart Waiter can quote directly. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAllergen(): Allergen
    {
        return $this->allergen;
    }

    public function setAllergen(Allergen $allergen): void
    {
        $this->allergen = $allergen;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }
}
