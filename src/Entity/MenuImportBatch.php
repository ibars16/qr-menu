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

    /** languageNotice: shown on Carta — "this import seems to be in another language" (MenuImportLanguageNotice). */
    public const NOTICE_PENDING     = 'pending';
    /** languageNotice: the owner chose to translate it; a step may still be left (resumable). */
    public const NOTICE_TRANSLATING = 'translating';
    /** languageNotice: "Dejarla así" — never shown again. */
    public const NOTICE_DISMISSED   = 'dismissed';
    /** languageNotice: translated, nothing left. */
    public const NOTICE_TRANSLATED  = 'translated';

    /** aiPhotosStatus: "Crear sus fotos con IA" started; dishes may be left (resumable from Carta). */
    public const AI_PHOTOS_RUNNING   = 'running';
    /** aiPhotosStatus: stopped by Gemini being unavailable (429/5xx) — "Reintentar". */
    public const AI_PHOTOS_PAUSED    = 'paused';
    /** aiPhotosStatus: "Dejarlo" — the Carta notice is gone for this import. */
    public const AI_PHOTOS_DISMISSED = 'dismissed';
    /** aiPhotosStatus: nothing left to generate. */
    public const AI_PHOTOS_DONE      = 'done';
    /**
     * aiPhotosStatus: imported on the free trial and left with dishes
     * without a photo (the trial's sample photos made, or already spent).
     * Once the restaurant pays, Carta offers the rest (AiPhotoBatch::noticesFor()).
     */
    public const AI_PHOTOS_TRIAL     = 'trial';

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

    /** ISO 639-1 code the import's dishes were found to be in, once checked; null: not checked, or the AI couldn't tell. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $detectedLanguage = null;

    /** null (no notice) or one of the NOTICE_* constants. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $languageNotice = null;

    /** null (never started) or one of the AI_PHOTOS_* constants. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $aiPhotosStatus = null;

    /** @var int[] Product ids the AI couldn't make a photo for (refused / failed): never retried by the batch. */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $aiPhotosSkipped = [];

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

    public function getDetectedLanguage(): ?string
    {
        return $this->detectedLanguage;
    }

    public function setDetectedLanguage(?string $detectedLanguage): void
    {
        $this->detectedLanguage = $detectedLanguage;
    }

    public function getLanguageNotice(): ?string
    {
        return $this->languageNotice;
    }

    public function setLanguageNotice(?string $languageNotice): void
    {
        $this->languageNotice = $languageNotice;
    }

    public function getAiPhotosStatus(): ?string
    {
        return $this->aiPhotosStatus;
    }

    public function setAiPhotosStatus(?string $aiPhotosStatus): void
    {
        $this->aiPhotosStatus = $aiPhotosStatus;
    }

    /** @return int[] */
    public function getAiPhotosSkipped(): array
    {
        return $this->aiPhotosSkipped;
    }

    public function addAiPhotosSkipped(int $productId): void
    {
        if (!in_array($productId, $this->aiPhotosSkipped, true)) {
            $this->aiPhotosSkipped[] = $productId;
        }
    }
}
