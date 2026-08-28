<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One row per de-duplicated customer view of a dish on the public menu (see
 * MenuController::trackView) — immutable, write-once, never updated. Mirrors
 * SmartWaiterExchangeLog's plain-createdAt style rather than
 * TimestampableTrait, since nothing here is ever revised after insert.
 *
 * $restaurant is denormalized alongside $product (same reasoning as
 * SmartWaiterExchangeLog::$restaurant) so every stats query can filter by
 * restaurant directly instead of joining through product -> category ->
 * restaurant.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['restaurant_id', 'created_at'], name: 'idx_product_view_restaurant_created')]
#[ORM\Index(columns: ['restaurant_id', 'product_id', 'created_at'], name: 'idx_product_view_restaurant_product_created')]
class ProductView
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Restaurant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Restaurant $restaurant;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Restaurant $restaurant, Product $product)
    {
        $this->restaurant = $restaurant;
        $this->product = $product;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRestaurant(): Restaurant
    {
        return $this->restaurant;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
