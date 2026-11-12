<?php

namespace App\Entity;

use App\Entity\Trait\PaidPeriodTrait;
use App\Entity\Trait\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Restaurant
{
    use TimestampableTrait;
    use PaidPeriodTrait {
        isExpired as private isPeriodExpired;
        isTrialActive as private isPeriodTrialActive;
        isEndingSoon as private isPeriodEndingSoon;
    }

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

    /**
     * Header/hero photo shown on the public menu (theme-dependent whether it
     * renders — currently only maison's .hero-photo-band). Deliberately its
     * own field, not a reuse of $logo: a logo is brand-mark art (can be
     * square, marginned, low-res), not the atmospheric wide photography a
     * hero band wants. Stored in public/uploads/heroes/, a directory
     * app:logos:clean-orphans never scans (it's hardcoded to
     * public/uploads/logos/ and only ever queries this entity's $logo
     * column) — so that command can't touch these files.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $heroImage = null;

    /** Short line shown under the restaurant name on the public menu hero (theme-dependent whether it renders). */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $tagline = null;

    /**
     * Social/review links edited in Configuración, already normalized by
     * ContactDetailsNormalizer: network key (instagram, facebook, tiktok,
     * google, tripadvisor, website) => absolute https URL. A network that
     * isn't set has no key; nothing set at all is null.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $socialLinks = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    /** As typed by the owner (trimmed); validated to hold 7–15 digits. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phone = null;

    /**
     * Weekly opening hours: day key (mon…sun) => list of [open, close] "HH:MM"
     * pairs, at most 2; [] = closed that day. A close earlier than its open
     * runs past midnight. A day that isn't configured has no key; nothing
     * configured at all is null.
     *
     * @var array<string, list<array{0: string, 1: string}>>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $openingHours = null;

    #[ORM\Column(length: 20)]
    private string $primaryColor = '#000000';

    /** Currency code (ISO 4217). Example: EUR, NZD, USD */
    #[ORM\Column(length: 3)]
    private string $currency = 'NZD';

    #[ORM\Column(length: 5)]
    private string $defaultLanguage = 'en';

    /**
     * The language the menu is being converted to (MenuLanguageChanger):
     * set while a change of defaultLanguage is half done — defaultLanguage
     * keeps the old one until every text is converted. Null = none.
     */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $languageChangeTo = null;

    /** Language of the Admin Panel UI for this restaurant's staff. Independent from defaultLanguage (public menu). */
    #[ORM\Column(length: 5)]
    private string $adminLocale = 'es';

    /** Visual layout for the public menu. Only 'standard' exists today — see ThemeController::LAYOUTS. */
    #[ORM\Column(length: 20)]
    private string $layout = 'standard';

    /** Visual theme for the public menu: classic-dark | glass | ocean | noir | forest | maison — see ThemeController::THEMES / MenuController::$validThemes. */
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
     * TagTranslationService::translateMissing()) — MenuController folds this into its
     * content-cache keys, so a bump alone makes every previously-cached key
     * for this restaurant unused, with no explicit cache deletion needed.
     * Never set directly; only ever incremented via bumpMenuContentVersion().
     */
    #[ORM\Column]
    private int $menuContentVersion = 1;

    /**
     * Dish photos generated with AI so far (every generation, saved or not:
     * each one costs). Capped at DishPhotoQuota::TRIAL_LIMIT while plan is
     * null (free trial, reseller's restaurant awaiting payment). Only ever
     * moved by DishPhotoQuota's atomic UPDATEs, never through this entity.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $aiPhotoCount = 0;

    /** Billed through a reseller: active while the reseller is and its own paidUntil (set by the reseller in /partners) hasn't passed — see isExpired(). Never billed through Stripe on its own. */
    #[ORM\ManyToOne(targetEntity: Reseller::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Reseller $reseller = null;

    /**
     * The Stripe account its customer and subscription live in: its
     * reseller's connected account (Stripe Connect) once it pays by card
     * through it; null = the platform's own account (or none yet).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeAccountId = null;

    /** Taken out of use by its reseller (never deleted): menu and admin off, not counted for the reseller's extra restaurants. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

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
    // Same position (a new one gets count() after a delete): oldest first —
    // also the order the dish editor lists them in (_product_fields.html.twig).
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $productTags;

    public function __construct()
    {
        $this->tables      = new ArrayCollection();
        $this->categories  = new ArrayCollection();
        $this->users       = new ArrayCollection();
        $this->productTags = new ArrayCollection();
        $this->paidUntil   = new \DateTimeImmutable('today +' . self::TRIAL_DAYS . ' days');
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

    public function getHeroImage(): ?string
    {
        return $this->heroImage;
    }

    public function setHeroImage(?string $heroImage): void
    {
        $this->heroImage = $heroImage;
    }

    public function getTagline(): ?string
    {
        return $this->tagline;
    }

    public function setTagline(?string $tagline): void
    {
        $this->tagline = $tagline;
    }

    /** @return array<string, string>|null */
    public function getSocialLinks(): ?array
    {
        return $this->socialLinks;
    }

    /** @param array<string, string>|null $socialLinks */
    public function setSocialLinks(?array $socialLinks): void
    {
        $this->socialLinks = $socialLinks ?: null;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): void
    {
        $this->address = $address;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    /** @return array<string, list<array{0: string, 1: string}>>|null */
    public function getOpeningHours(): ?array
    {
        return $this->openingHours;
    }

    /** @param array<string, list<array{0: string, 1: string}>>|null $openingHours */
    public function setOpeningHours(?array $openingHours): void
    {
        $this->openingHours = $openingHours ?: null;
    }

    public function getPrimaryColor(): string
    {
        return $this->primaryColor;
    }

    /**
     * Legacy: no longer editable or read by any theme (classic-dark/-warm
     * use a fixed accent), the column is only kept. Still only ever
     * "#rrggbb", stored lowercase — should it ever be printed into a
     * <style> block again, HTML escaping there doesn't stop "red;} body{…",
     * so anything else is refused here.
     */
    public function setPrimaryColor(string $primaryColor): void
    {
        if (!self::isValidPrimaryColor($primaryColor)) {
            throw new \InvalidArgumentException('Primary color must be #RRGGBB.');
        }
        $this->primaryColor = strtolower($primaryColor);
    }

    public static function isValidPrimaryColor(string $color): bool
    {
        return preg_match('/^#[0-9a-f]{6}$/iD', $color) === 1;
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

    public function getLanguageChangeTo(): ?string
    {
        return $this->languageChangeTo;
    }

    public function setLanguageChangeTo(?string $languageChangeTo): void
    {
        $this->languageChangeTo = $languageChangeTo;
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

    public function getAiPhotoCount(): int
    {
        return $this->aiPhotoCount;
    }

    private const TRIAL_DAYS = 30;

    /**
     * Its own paidUntil passed; a reseller's restaurant is also off when
     * archived or when its reseller has expired. Everything that turns a
     * restaurant off (admin gate, public menu, Smart Waiter, /platform,
     * /partners) asks only this.
     */
    public function isExpired(?\DateTimeImmutable $today = null): bool
    {
        if ($this->archivedAt !== null || $this->reseller?->isExpired($today)) {
            return true;
        }

        return $this->isPeriodExpired($today);
    }

    /**
     * Only its own paidUntil, whatever its reseller or archiving say: the
     * status the reseller manages for it in /partners.
     */
    public function isOwnPeriodExpired(?\DateTimeImmutable $today = null): bool
    {
        return $this->isPeriodExpired($today);
    }

    /**
     * A reseller's restaurant that has never been paid: created off (no
     * free trial, RestaurantCreator) and still waiting for its first
     * payment — "Pendiente de pago" on /partners, "Elige un plan para
     * activar tu carta" for its owner, rather than "vencido".
     */
    public function isAwaitingFirstPayment(?\DateTimeImmutable $today = null): bool
    {
        return $this->reseller !== null && $this->plan === null && $this->lastPaidAt === null && $this->isPeriodExpired($today);
    }

    /**
     * The owner's "vence pronto" notice for a reseller's restaurant
     * ("contacta con tu revendedor", no payment button): its own paidUntil
     * 7 days or fewer away, still active. Trial or paid alike — the
     * reseller renews it by hand from /partners. Not once the owner pays
     * by card: then it gets the trial / "vence pronto" notices instead.
     */
    public function isResellerServiceEndingSoon(?\DateTimeImmutable $today = null): bool
    {
        return $this->reseller !== null && !$this->ownerPaysByCard() && !$this->isExpired($today) && $this->getDaysLeft($today) <= 7;
    }

    /**
     * The owner pays by card through Stripe: always for a restaurant billed
     * on its own; for a reseller's, once the reseller can charge it (its own
     * Stripe connected and a price set — Reseller::canChargeRestaurants()),
     * or while it already has a subscription there. Otherwise the reseller
     * renews it by hand and the owner's subscription screen is information
     * only.
     */
    public function ownerPaysByCard(): bool
    {
        return $this->reseller === null || $this->hasLiveSubscription() || $this->reseller->canChargeRestaurants();
    }

    /** Only when the owner pays by card (ownerPaysByCard()). */
    public function isTrialActive(?\DateTimeImmutable $today = null): bool
    {
        return $this->ownerPaysByCard() && $this->isPeriodTrialActive($today);
    }

    /** Only when the owner pays by card (ownerPaysByCard()). */
    public function isEndingSoon(?\DateTimeImmutable $today = null): bool
    {
        return $this->ownerPaysByCard() && $this->isPeriodEndingSoon($today);
    }

    public function getReseller(): ?Reseller
    {
        return $this->reseller;
    }

    public function setReseller(?Reseller $reseller): void
    {
        $this->reseller = $reseller;
    }

    /** No billing screens, Stripe or payment actions of its own: the reseller pays and renews it from /partners. */
    public function isBilledByReseller(): bool
    {
        return $this->reseller !== null;
    }

    public function getStripeAccountId(): ?string
    {
        return $this->stripeAccountId;
    }

    public function setStripeAccountId(?string $stripeAccountId): void
    {
        $this->stripeAccountId = $stripeAccountId;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    public function archive(): void
    {
        $this->archivedAt ??= new \DateTimeImmutable();
    }

    public function unarchive(): void
    {
        $this->archivedAt = null;
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

    public function hasFixedPriceMenus(): bool
    {
        foreach ($this->categories as $category) {
            if ($category->isFixedPriceMenu()) {
                return true;
            }
        }

        return false;
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

    /**
     * Signed up by itself and its owner hasn't confirmed the email yet: the
     * public menu (and Smart Waiter) stay hidden from customers — the owner
     * can still preview it while logged in (MenuController).
     */
    public function isAwaitingEmailConfirmation(): bool
    {
        foreach ($this->users as $user) {
            if ($user->isEmailConfirmationPending()) {
                return true;
            }
        }

        return false;
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
