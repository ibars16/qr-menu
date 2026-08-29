<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Restaurant
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, unique: true)]
    private string $slug;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(length: 20)]
    private string $primaryColor = '#000000';

    /** Currency code (ISO 4217). Example: EUR, NZD, USD */
    #[ORM\Column(length: 3)]
    private string $currency = 'NZD';

    #[ORM\Column(length: 5)]
    private string $defaultLanguage = 'en';

    /** Language of the Admin Panel UI for this restaurant's staff. Independent from defaultLanguage (public menu). */
    #[ORM\Column(length: 5)]
    private string $adminLocale = 'es';

    /** Visual layout for the public menu: standard | compact | grid */
    #[ORM\Column(length: 20)]
    private string $layout = 'standard';

    /** Visual theme for the public menu: classic-dark | classic-warm | glass | ocean | noir */
    #[ORM\Column(length: 20)]
    private string $theme = 'classic-dark';

    /**
     * Feature flag for the "Set menus" admin section (Admin\MenusController) —
     * hidden by default. See App\EventSubscriber\SetMenusFeatureGate, which
     * gates every action on that controller behind this flag, and
     * app:restaurant:set-menus (bin/console) to flip it per restaurant.
     */
    #[ORM\Column]
    private bool $setMenusEnabled = false;

    /**
     * Bumped by every admin write that changes something the public menu
     * displays (see MenuAdminController, CategoriesController,
     * TagsController, MenusController, SettingsController, and
     * TranslateTagsMessageHandler) — MenuController folds this into its
     * content-cache keys, so a bump alone makes every previously-cached key
     * for this restaurant unused, with no explicit cache deletion needed.
     * Never set directly; only ever incremented via bumpMenuContentVersion().
     */
    #[ORM\Column]
    private int $menuContentVersion = 1;

    #[ORM\OneToMany(mappedBy: 'restaurant', targetEntity: Table::class, cascade: ['persist', 'remove'])]
    private Collection $tables;

    #[ORM\OneToMany(mappedBy: 'restaurant', targetEntity: Category::class, cascade: ['persist', 'remove'])]
    private Collection $categories;

    #[ORM\OneToMany(mappedBy: 'restaurant', targetEntity: User::class)]
    private Collection $users;

    #[ORM\OneToMany(
        mappedBy: 'restaurant',
        targetEntity: ProductTag::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $productTags;

    public function __construct()
    {
        $this->tables      = new ArrayCollection();
        $this->categories  = new ArrayCollection();
        $this->users       = new ArrayCollection();
        $this->productTags = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): void
    {
        $this->logo = $logo;
    }

    public function getPrimaryColor(): string
    {
        return $this->primaryColor;
    }

    public function setPrimaryColor(string $primaryColor): void
    {
        $this->primaryColor = $primaryColor;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = $currency;
    }

    public function getDefaultLanguage(): string
    {
        return $this->defaultLanguage;
    }

    public function setDefaultLanguage(string $defaultLanguage): void
    {
        $this->defaultLanguage = $defaultLanguage;
    }

    public function getAdminLocale(): string
    {
        return $this->adminLocale;
    }

    public function setAdminLocale(string $adminLocale): void
    {
        $this->adminLocale = $adminLocale;
    }

    public function getLayout(): string
    {
        return $this->layout;
    }

    public function setLayout(string $layout): void
    {
        $this->layout = $layout;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): void
    {
        $this->theme = $theme;
    }

    public function isSetMenusEnabled(): bool
    {
        return $this->setMenusEnabled;
    }

    public function setSetMenusEnabled(bool $setMenusEnabled): void
    {
        $this->setMenusEnabled = $setMenusEnabled;
    }

    public function getMenuContentVersion(): int
    {
        return $this->menuContentVersion;
    }

    public function bumpMenuContentVersion(): void
    {
        $this->menuContentVersion++;
    }

    private const TRIAL_DAYS = 30;

    /** Purely computed from createdAt — no stored column, nothing to migrate. */
    public function getTrialEndsAt(): \DateTimeImmutable
    {
        return $this->getCreatedAt()->modify('+' . self::TRIAL_DAYS . ' days');
    }

    public function isTrialActive(): bool
    {
        return new \DateTimeImmutable() < $this->getTrialEndsAt();
    }

    public function getTrialDaysRemaining(): int
    {
        if (!$this->isTrialActive()) {
            return 0;
        }

        // +1 rounds up so signup day shows "30 días", not "29".
        return (new \DateTimeImmutable())->diff($this->getTrialEndsAt())->days + 1;
    }

    public function getTables(): Collection
    {
        return $this->tables;
    }

    public function addTable(Table $table): void
    {
        if (!$this->tables->contains($table)) {
            $this->tables->add($table);
            $table->setRestaurant($this);
        }
    }

    public function removeTable(Table $table): void
    {
        $this->tables->removeElement($table);
    }

    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Category $category): void
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
            $category->setRestaurant($this);
        }
    }

    public function removeCategory(Category $category): void
    {
        $this->categories->removeElement($category);
    }

    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function getProductTags(): Collection
    {
        return $this->productTags;
    }

    /** $tag's restaurant is fixed at construction (see ProductTag) and must already be $this. */
    public function addProductTag(ProductTag $tag): void
    {
        if (!$this->productTags->contains($tag)) {
            $this->productTags->add($tag);
        }
    }

    public function removeProductTag(ProductTag $tag): void
    {
        $this->productTags->removeElement($tag);
    }
}
