<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Enum\MenuImportBatchStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One upload session — the owner picks N photos, this is what ties them
 * together. Never holds menu content itself; that lives on the real
 * Category/Product rows each MenuImportPage eventually produces (see
 * Product::$importBatch / Category::$importBatch), created inactive and
 * needing review — never here.
 */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class MenuImportBatch
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Restaurant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Restaurant $restaurant;

    #[ORM\Column(length: 20, enumType: MenuImportBatchStatus::class)]
    private MenuImportBatchStatus $status;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\OneToMany(mappedBy: 'batch', targetEntity: MenuImportPage::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $pages;

    public function __construct(Restaurant $restaurant)
    {
        $this->restaurant = $restaurant;
        $this->status = MenuImportBatchStatus::UPLOADED;
        $this->pages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRestaurant(): Restaurant
    {
        return $this->restaurant;
    }

    public function getStatus(): MenuImportBatchStatus
    {
        return $this->status;
    }

    public function setStatus(MenuImportBatchStatus $status): void
    {
        $this->status = $status;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function getPages(): Collection
    {
        return $this->pages;
    }

    public function addPage(MenuImportPage $page): void
    {
        if (!$this->pages->contains($page)) {
            $this->pages->add($page);
        }
    }
}
