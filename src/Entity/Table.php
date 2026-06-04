<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'restaurant_table')]
#[ORM\HasLifecycleCallbacks]
class Table
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Restaurant::class, inversedBy: 'tables')]
    #[ORM\JoinColumn(nullable: false)]
    private Restaurant $restaurant;

    #[ORM\Column(length: 50)]
    private string $number;

    #[ORM\Column(length: 255, unique: true)]
    private string $qrToken;

    #[ORM\Column]
    private bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRestaurant(): Restaurant
    {
        return $this->restaurant;
    }

    public function setRestaurant(Restaurant $restaurant): void
    {
        $this->restaurant = $restaurant;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function setNumber(string $number): void
    {
        $this->number = $number;
    }

    public function getQrToken(): string
    {
        return $this->qrToken;
    }

    public function setQrToken(string $qrToken): void
    {
        $this->qrToken = $qrToken;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }
}
