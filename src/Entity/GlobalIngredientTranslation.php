<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'unique_global_ingredient_locale', columns: ['global_ingredient_id', 'locale'])]
class GlobalIngredientTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GlobalIngredient::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false)]
    private GlobalIngredient $globalIngredient;

    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column(length: 255)]
    private string $name;

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
}
