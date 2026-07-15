<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Enum\MenuImportPageStatus;
use Doctrine\ORM\Mapping as ORM;

/**
 * One uploaded photo within a MenuImportBatch. Phase 1 only ever writes
 * imagePath/position/imageHash and leaves status at PENDING — extraction
 * (Phase 2) is what advances status and, deliberately, is what adds the
 * columns for the extracted content itself (see MenuImportBatchStatus /
 * MenuImportPageStatus docblocks). Nothing here is customer-facing at any
 * point; only the real Category/Product rows Phase 3 creates from this are.
 */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class MenuImportPage
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MenuImportBatch::class, inversedBy: 'pages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MenuImportBatch $batch;

    /** Path relative to public/, e.g. "uploads/menu-imports/12/34/0-6656f2.jpg". */
    #[ORM\Column(length: 255)]
    private string $imagePath;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(length: 20, enumType: MenuImportPageStatus::class)]
    private MenuImportPageStatus $status;

    /** sha256 of the uploaded file content — lets a later phase detect the same photo being re-uploaded. Not yet acted on in Phase 1. */
    #[ORM\Column(length: 64)]
    private string $imageHash;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $errorMessage = null;

    /**
     * The raw structured JSON a vision provider extracted from this page —
     * see MenuVisionPromptBuilder for the schema. Written once, by
     * MenuImportExtractionService, and never by anything else. This is
     * extracted data, not confirmed data: nothing here is ever read by the
     * public menu or Smart Waiter, and no Product/Category/Ingredient row
     * is created from it until a later phase (and, even then, only once an
     * owner reviews it).
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $extractedData = null;

    /** ISO 639-1 code the vision provider reported the menu's own printed language as — set alongside extractedData, never guessed independently. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $detectedLocale = null;

    public function __construct(MenuImportBatch $batch, string $imagePath, int $position, string $imageHash)
    {
        $this->batch = $batch;
        $this->imagePath = $imagePath;
        $this->position = $position;
        $this->imageHash = $imageHash;
        $this->status = MenuImportPageStatus::PENDING;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBatch(): MenuImportBatch
    {
        return $this->batch;
    }

    public function getImagePath(): string
    {
        return $this->imagePath;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getStatus(): MenuImportPageStatus
    {
        return $this->status;
    }

    public function setStatus(MenuImportPageStatus $status): void
    {
        $this->status = $status;
    }

    public function getImageHash(): string
    {
        return $this->imageHash;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function getExtractedData(): ?array
    {
        return $this->extractedData;
    }

    public function setExtractedData(?array $extractedData): void
    {
        $this->extractedData = $extractedData;
    }

    public function getDetectedLocale(): ?string
    {
        return $this->detectedLocale;
    }

    public function setDetectedLocale(?string $detectedLocale): void
    {
        $this->detectedLocale = $detectedLocale;
    }
}
