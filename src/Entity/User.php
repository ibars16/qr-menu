<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: \App\Repository\UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;

    /** Full access to a restaurant's admin panel, including managing its team (see UsersController). */
    public const ROLE_OWNER = 'ROLE_OWNER';

    /** Content-only access — see security.yaml's role_hierarchy (ROLE_OWNER implies this) and the per-controller #[IsGranted] split. */
    public const ROLE_STAFF = 'ROLE_STAFF';

    /** Platform owner: no restaurant, only /platform (see SubscriptionGate, which redirects /admin there). */
    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    /** A reseller's login: no restaurant, its own panel (see $reseller). */
    public const ROLE_RESELLER = 'ROLE_RESELLER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private string $password;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastName = null;

    /**
     * The restaurant this user manages.
     * For super-admins (ROLE_SUPER_ADMIN) this can be null.
     */
    #[ORM\ManyToOne(targetEntity: Restaurant::class, inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Restaurant $restaurant = null;

    /**
     * A self sign-up (/register, /alta/{code}) that hasn't clicked the link
     * in its email yet (EmailConfirmation). While true the panel works but
     * the restaurant's public menu stays hidden (Restaurant::isAwaitingEmailConfirmation()).
     * False for everyone else: staff, resellers, restaurants a reseller or
     * the platform creates, and every account from before this existed.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $emailConfirmationPending = false;

    /** The reseller this user logs in for (ROLE_RESELLER); null for everyone else. */
    #[ORM\ManyToOne(targetEntity: Reseller::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Reseller $reseller = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /** Always lower-cased and trimmed — see UserRepository. */
    public function setEmail(string $email): void
    {
        $this->email = \App\Repository\UserRepository::normalizeEmail($email);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
        // Clear temporary plain-text password if stored
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): void
    {
        $this->lastName = $lastName;
    }

    public function getFullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }

    public function isEmailConfirmationPending(): bool
    {
        return $this->emailConfirmationPending;
    }

    public function setEmailConfirmationPending(bool $pending): void
    {
        $this->emailConfirmationPending = $pending;
    }

    public function getRestaurant(): ?Restaurant
    {
        return $this->restaurant;
    }

    public function setRestaurant(?Restaurant $restaurant): void
    {
        $this->restaurant = $restaurant;
    }

    public function getReseller(): ?Reseller
    {
        return $this->reseller;
    }

    public function setReseller(?Reseller $reseller): void
    {
        $this->reseller = $reseller;
    }
}
