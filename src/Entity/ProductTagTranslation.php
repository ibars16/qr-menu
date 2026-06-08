<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ProductTagTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(
        targetEntity: ProductTag::class,
        inversedBy: 'translations'
    )]
    #[ORM\JoinColumn(nullable: false)]
    private ProductTag $tag;

    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column(length: 100)]
    private string $name;

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
