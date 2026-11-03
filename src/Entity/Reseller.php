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

    public function __construct(string $name)
    {
        $this->name      = $name;
        $this->paidUntil = new \DateTimeImmutable('yesterday');
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
}
