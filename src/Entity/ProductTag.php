<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A dietary/editorial label a restaurant can assign to its products —
 * "Vegetarian", "Spicy", "Chef's Recommendation", or anything a restaurant
 * owner types themselves. One row per restaurant per tag (never shared
 * across restaurants), so assignment, icon, and colour are always
 * per-restaurant concerns.
 *
 * System tags ($isSystem = true) are the small, app-curated set seeded from
 * config/preset_tags.yaml (see DefaultTagSeeder) — the current EU dietary
 * labels, plus "Chef's Recommendation". Their $code is the one thing code
 * elsewhere is allowed to depend on: it is fixed at construction, has no
 * setter, and is `readonly` at the PHP language level, so nothing — not a
 * future controller change, not a bug — can alter a system tag's code
 * after creation. A restaurant owner can still fully customize a system
 * tag's display name (via translations), icon, and colour, and can still
 * choose which products carry it; only its identity and its existence are
 * protected (see TagsController::delete()).
 *
 * A future Smart Waiter (or any other code) that needs to find a specific
 * system tag reliably — regardless of what the owner has renamed it to —
 * must query by *both* code and isSystem, e.g. "code = 'recommended' AND
 * is_system = true", never by translated name. Restaurant-created custom
 * tags are never protected this way and must never be mistaken for a
 * system tag just because a code happens to match (see the collision
 * avoidance in TagsController::create(), backed by a unique constraint on
 * (restaurant_id, code)).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'unique_product_tag_restaurant_code', columns: ['restaurant_id', 'code'])]
class ProductTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Restaurant::class, inversedBy: 'productTags')]
    #[ORM\JoinColumn(nullable: false)]
    private readonly Restaurant $restaurant;

    /** Stable natural key. Immutable — see the class-level docblock. */
    #[ORM\Column(length: 100)]
    private readonly string $code;

    /** True only for the app-curated tags seeded from config/preset_tags.yaml. Immutable — see the class-level docblock. */
    #[ORM\Column]
    private readonly bool $isSystem;

    #[ORM\Column(length: 50)]
    private string $icon = '';

    #[ORM\Column(length: 20)]
    private string $color = '#666666';

    #[ORM\Column]
    private int $position = 0;

    #[ORM\ManyToMany(
        targetEntity: Product::class,
        mappedBy: 'tags'
    )]
    private Collection $products;

    #[ORM\OneToMany(
        mappedBy: 'tag',
        targetEntity: ProductTagTranslation::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $translations;

    public function __construct(Restaurant $restaurant, string $code, bool $isSystem = false)
    {
        $this->restaurant = $restaurant;
        $this->code = $code;
        $this->isSystem = $isSystem;
        $this->products = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRestaurant(): Restaurant
    {
        return $this->restaurant;
    }

    public function getCode(): string
    {
        return $this->code;
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

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(ProductTagTranslation $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setTag($this);
        }
    }

    public function removeTranslation(ProductTagTranslation $translation): void
    {
        $this->translations->removeElement($translation);
    }

    public function getTranslation(string $locale): ?ProductTagTranslation
    {
        foreach ($this->translations as $t) {
            if ($t->getLocale() === $locale) {
                return $t;
            }
        }

        return null;
    }
}
