<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Enum\ClassificationStatus;
use Doctrine\ORM\Mapping as ORM;

/**
 * A generic, reusable audit trail + review queue for AI-assisted data
 * enrichment — see App\Service\Classification. One row per proposed
 * (subject, classificationType, label) combination, e.g. (GlobalIngredient
 * #123, "global_ingredient_allergens", "milk").
 *
 * Deliberately generic rather than allergen-specific: $subjectType /
 * $subjectId identify the row being classified (a plain type+id pair, not a
 * Doctrine relation, since future classification types will target
 * different entities — Product, Ingredient, whatever — without needing a
 * new FK column each time), and $label / $attributes are free-form so the
 * exact same table and workflow serve dietary tags, ingredient categories,
 * synonyms, or nutrition facts later without a redesign.
 *
 * Existence of a row for (subjectType, subjectId, classificationType) is
 * what marks a subject as "already processed" — including the
 * NO_LABELS_FOUND sentinel (label = null), which exists specifically so a
 * confident "nothing applies here" doesn't get re-submitted to the AI every
 * run. A subject the AI was genuinely unsure about gets no row at all, and
 * stays eligible for a future run — see ClassificationRunner.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'unique_classification_subject_label', columns: ['subject_type', 'subject_id', 'classification_type', 'label'])]
#[ORM\Index(name: 'idx_classification_lookup', columns: ['subject_type', 'subject_id', 'classification_type'])]
#[ORM\HasLifecycleCallbacks]
class ClassificationLog
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** e.g. "global_ingredient" */
    #[ORM\Column(length: 50)]
    private string $subjectType;

    #[ORM\Column]
    private int $subjectId;

    /** e.g. "global_ingredient_allergens" — namespaces label/status across different kinds of classification on the same subject type. */
    #[ORM\Column(length: 50)]
    private string $classificationType;

    /** Null only for the NO_LABELS_FOUND sentinel row. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $label = null;

    /** Task-specific extra data for this proposal, e.g. {"presence": "contains"}. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $attributes = null;

    #[ORM\Column(nullable: true)]
    private ?float $confidence = null;

    #[ORM\Column(length: 20, enumType: ClassificationStatus::class)]
    private ClassificationStatus $status;

    /** 'ai' today; kept open for a future 'manual' entry point into the same review queue. */
    #[ORM\Column(length: 10)]
    private string $source = 'ai';

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $reviewNote = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function setSubjectType(string $subjectType): void
    {
        $this->subjectType = $subjectType;
    }

    public function getSubjectId(): int
    {
        return $this->subjectId;
    }

    public function setSubjectId(int $subjectId): void
    {
        $this->subjectId = $subjectId;
    }

    public function getClassificationType(): string
    {
        return $this->classificationType;
    }

    public function setClassificationType(string $classificationType): void
    {
        $this->classificationType = $classificationType;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
    }

    public function getAttributes(): array
    {
        return $this->attributes ?? [];
    }

    public function setAttributes(?array $attributes): void
    {
        $this->attributes = $attributes;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    public function setConfidence(?float $confidence): void
    {
        $this->confidence = $confidence;
    }

    public function getStatus(): ClassificationStatus
    {
        return $this->status;
    }

    public function setStatus(ClassificationStatus $status): void
    {
        $this->status = $status;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function getReviewNote(): ?string
    {
        return $this->reviewNote;
    }

    public function setReviewNote(?string $reviewNote): void
    {
        $this->reviewNote = $reviewNote;
    }
}
