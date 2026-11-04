<?php

namespace App\Entity;

use App\Entity\Trait\PaidPeriodTrait;
use App\Entity\Trait\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * A company that resells the product: pays one subscription (base plan with
 * some restaurants included, plus each one over that) and runs its
 * restaurants' menus. Those restaurants (Restaurant::$reseller) have no
 * billing of their own and are active exactly while it is. No free trial:
 * expired from signup until the first payment. Never deleted.
 */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'UNIQ_reseller_connect_account', columns: ['connect_account_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_reseller_signup_code', columns: ['signup_code'])]
class Reseller
{
    use TimestampableTrait;
    use PaidPeriodTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    /** Language of the reseller's own panel (config/admin_locales.php). */
    #[ORM\Column(length: 5)]
    private string $adminLocale = 'es';

    /**
     * Its own Stripe account (Stripe Connect, Accounts v2 — see
     * Service\Billing\ResellerConnect), where its restaurants pay it
     * directly. Null = not connected yet; never disconnected.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $connectAccountId = null;

    /** Its account can take card payments (merchant card_payments capability active), kept up to date from Stripe. */
    #[ORM\Column(options: ['default' => false])]
    private bool $connectChargesEnabled = false;

    /** What it charges each of its restaurants, in cents; one price for all of them. */
    #[ORM\Column(nullable: true)]
    private ?int $restaurantPriceMonthly = null;

    /** Optional yearly price, in cents; null = monthly only. */
    #[ORM\Column(nullable: true)]
    private ?int $restaurantPriceYearly = null;

    /** The product and current prices created in ITS Stripe account for the amounts above (new subscriptions only). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $connectProductId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $connectPriceMonthlyId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $connectPriceYearlyId = null;

    /** Email its restaurants see to contact it; null = its user's email. Not sent to Stripe. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $contactEmail = null;

    /** Secret part of its customers' signup link (/alta/{code}); restaurants created there are its own. */
    #[ORM\Column(length: 32)]
    private string $signupCode;

    public function __construct(string $name)
    {
        $this->name       = $name;
        $this->paidUntil  = new \DateTimeImmutable('yesterday');
        $this->signupCode = bin2hex(random_bytes(16));
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): void
    {
        $this->contactEmail = $contactEmail === '' ? null : $contactEmail;
    }

    public function getSignupCode(): string
    {
        return $this->signupCode;
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

    public function getAdminLocale(): string
    {
        return $this->adminLocale;
    }

    public function setAdminLocale(string $adminLocale): void
    {
        $this->adminLocale = $adminLocale;
    }

    public function getConnectAccountId(): ?string
    {
        return $this->connectAccountId;
    }

    public function setConnectAccountId(?string $connectAccountId): void
    {
        $this->connectAccountId = $connectAccountId;
    }

    public function hasConnectAccount(): bool
    {
        return $this->connectAccountId !== null;
    }

    public function isConnectChargesEnabled(): bool
    {
        return $this->connectChargesEnabled;
    }

    public function setConnectChargesEnabled(bool $connectChargesEnabled): void
    {
        $this->connectChargesEnabled = $connectChargesEnabled;
    }

    /** Connected and able to take card payments: what "Stripe conectado" means. */
    public function isConnectReady(): bool
    {
        return $this->connectAccountId !== null && $this->connectChargesEnabled;
    }

    public function getRestaurantPriceMonthly(): ?int
    {
        return $this->restaurantPriceMonthly;
    }

    public function getRestaurantPriceYearly(): ?int
    {
        return $this->restaurantPriceYearly;
    }

    public function getConnectProductId(): ?string
    {
        return $this->connectProductId;
    }

    public function setConnectProductId(?string $connectProductId): void
    {
        $this->connectProductId = $connectProductId;
    }

    public function getConnectPriceMonthlyId(): ?string
    {
        return $this->connectPriceMonthlyId;
    }

    public function getConnectPriceYearlyId(): ?string
    {
        return $this->connectPriceYearlyId;
    }

    /** The amount and the Stripe price created for it, together. */
    public function setRestaurantPriceMonthly(?int $cents, ?string $priceId): void
    {
        $this->restaurantPriceMonthly = $cents;
        $this->connectPriceMonthlyId  = $priceId;
    }

    public function setRestaurantPriceYearly(?int $cents, ?string $priceId): void
    {
        $this->restaurantPriceYearly = $cents;
        $this->connectPriceYearlyId  = $priceId;
    }

    /**
     * Its restaurants can pay it by card: Stripe connected and a monthly
     * price set (the yearly one is optional). Otherwise their subscription
     * screen is information only.
     */
    public function canChargeRestaurants(): bool
    {
        return $this->isConnectReady() && $this->connectPriceMonthlyId !== null;
    }

    /**
     * The plans its restaurants can choose, in cents: monthly, plus yearly if it set one.
     *
     * @return array<string, int>
     */
    public function restaurantPlans(): array
    {
        return array_filter([
            self::PLAN_MONTHLY => $this->restaurantPriceMonthly,
            self::PLAN_YEARLY  => $this->restaurantPriceYearly,
        ], static fn (?int $cents): bool => $cents !== null && $cents > 0);
    }

    /** The Stripe price (in its account) for new subscriptions to $plan; null = not offered. */
    public function restaurantPriceIdFor(string $plan): ?string
    {
        return match ($plan) {
            self::PLAN_MONTHLY => $this->connectPriceMonthlyId,
            self::PLAN_YEARLY  => $this->connectPriceYearlyId,
            default            => null,
        };
    }
}
