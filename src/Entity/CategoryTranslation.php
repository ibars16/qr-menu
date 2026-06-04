<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'unique_category_locale', columns: ['category_id', 'locale'])]
class CategoryTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false)]
    private Category $category;

    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column(length: 255)]
    private string $name;

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
