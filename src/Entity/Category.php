<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Enum\CategoryType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Category
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Restaurant::class, inversedBy: 'categories')]
    #[ORM\JoinColumn(nullable: false)]
    private Restaurant $restaurant;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $active = true;

    /** See Product::$importBatch for the identical rationale, including why this is SET NULL rather than CASCADE. */
    #[ORM\ManyToOne(targetEntity: MenuImportBatch::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MenuImportBatch $importBatch = null;

    #[ORM\Column]
    private bool $needsReview = false;

    #[ORM\Column(nullable: true)]
    private ?float $aiConfidence = null;

    /** Food vs. drink classification — null means unclassified. See CategoryType's docblock. */
    #[ORM\Column(length: 20, enumType: CategoryType::class, nullable: true)]
    private ?CategoryType $type = null;

    /**
     * Cents. Non-null turns this category into a fixed-price menu (menú del
     * día, tasting menu…): its products are ordinary dishes sharing this one
     * price, rather than each carrying its own. Per-dish surcharges within
     * the menu still use Product::$supplementPrice — unchanged.
     */
    #[ORM\Column(nullable: true)]
    private ?int $menuPrice = null;

    /**
     * Free text like "Incluye pan y bebida · De lunes a viernes". Single
     * language, stored as-is in the restaurant's own content language —
     * same convention as Product::$basePriceLabel: no translation entity,
     * no auto-translate.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $menuDescription = null;

    #[ORM\OneToMany(mappedBy: 'category', targetEntity: CategoryTranslation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    #[ORM\OneToMany(mappedBy: 'category', targetEntity: Product::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $products;

    #[ORM\OneToMany(mappedBy: 'category', targetEntity: MenuSection::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $menuSections;

    /**
     * Temporary price after currency conversion, cents. NOT mapped to the
     * database — calculated at runtime in MenuController, exactly like
     * Product::$convertedPrice. Only meaningful when $menuPrice is set.
     */
    private int $convertedMenuPrice = 0;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->products = new ArrayCollection();
        $this->menuSections = new ArrayCollection();
    }

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getImportBatch(): ?MenuImportBatch
    {
        return $this->importBatch;
    }

    public function setImportBatch(?MenuImportBatch $importBatch): void
    {
        $this->importBatch = $importBatch;
    }

    public function isNeedsReview(): bool
    {
        return $this->needsReview;
    }

    public function setNeedsReview(bool $needsReview): void
    {
        $this->needsReview = $needsReview;
    }

    public function getAiConfidence(): ?float
    {
        return $this->aiConfidence;
    }

    public function setAiConfidence(?float $aiConfidence): void
    {
        $this->aiConfidence = $aiConfidence;
    }

    public function getType(): ?CategoryType
    {
        return $this->type;
    }

    public function setType(?CategoryType $type): void
    {
        $this->type = $type;
    }

    public function getMenuPrice(): ?int
    {
        return $this->menuPrice;
    }

    public function setMenuPrice(?int $menuPrice): void
    {
        $this->menuPrice = $menuPrice;
    }

    public function getMenuDescription(): ?string
    {
        return $this->menuDescription;
    }

    public function setMenuDescription(?string $menuDescription): void
    {
        $this->menuDescription = $menuDescription;
    }

    public function isFixedPriceMenu(): bool
    {
        return $this->menuPrice !== null;
    }

    /** Falls back to the raw price if conversion hasn't been applied yet — mirrors Product::getConvertedPrice(). */
    public function getConvertedMenuPrice(): int
    {
        return $this->convertedMenuPrice > 0 ? $this->convertedMenuPrice : (int) $this->menuPrice;
    }

    public function setConvertedMenuPrice(int $price): void
    {
        $this->convertedMenuPrice = $price;
    }

    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(CategoryTranslation $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setCategory($this);
        }
    }

    public function removeTranslation(CategoryTranslation $translation): void
    {
        $this->translations->removeElement($translation);
    }

    public function getTranslation(string $locale): ?CategoryTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }

        return null;
    }

    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): void
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->setCategory($this);
        }
    }

    public function removeProduct(Product $product): void
    {
        $this->products->removeElement($product);
    }

    /** The public menu's view of this category's dishes — active AND, see Product::isSafeToDisplay(), not a €0 dish. */
    public function getActiveProductsSorted(): array
    {
        $products = $this->products
            ->filter(fn($p) => $p->isActive() && $p->isSafeToDisplay())
            ->toArray();

        usort($products, fn($a, $b) => $a->getPosition() <=> $b->getPosition());

        return $products;
    }

    public function getMenuSections(): Collection
    {
        return $this->menuSections;
    }

    public function addMenuSection(MenuSection $section): void
    {
        if (!$this->menuSections->contains($section)) {
            $this->menuSections->add($section);
            $section->setCategory($this);
        }
    }

    public function removeMenuSection(MenuSection $section): void
    {
        $this->menuSections->removeElement($section);
    }

    /**
     * The Menús edit screen's structure: every section (in position order —
     * this is now the sections' OWN clean 0..n-1 scale, no longer shared
     * with Product::$position) paired with its own dishes (each section's
     * $products, in ITS OWN position order — see MenuSection::
     * getProductsSorted()). Every product in a menu category is required to
     * belong to a section, so there is no "unsectioned" bucket here.
     *
     * @return list<array{section: MenuSection, products: Product[]}>
     */
    public function getSectionsWithProducts(): array
    {
        $sections = $this->menuSections->toArray();
        usort($sections, static fn(MenuSection $a, MenuSection $b) => $a->getPosition() <=> $b->getPosition());

        return array_map(
            static fn(MenuSection $section) => ['section' => $section, 'products' => $section->getProductsSorted()],
            $sections
        );
    }

    /**
     * Same shape as getSectionsWithProducts(), limited to each section's
     * active dishes only — the public menu's view of a fixed-price menu's
     * structure. The admin's own screen keeps using the unfiltered version
     * above so an inactive dish stays visible there with its toggle.
     *
     * @return list<array{section: MenuSection, products: Product[]}>
     */
    public function getActiveSectionsWithProducts(): array
    {
        return array_map(
            static fn(array $entry) => [
                'section'  => $entry['section'],
                'products' => array_values(array_filter($entry['products'], static fn(Product $p) => $p->isActive())),
            ],
            $this->getSectionsWithProducts()
        );
    }

    /** True once at least one section has at least one active dish — gates whether this menu renders at all on the public menu. */
    public function hasActiveMenuDishes(): bool
    {
        foreach ($this->getActiveSectionsWithProducts() as $entry) {
            if (count($entry['products']) > 0) {
                return true;
            }
        }

        return false;
    }
}
