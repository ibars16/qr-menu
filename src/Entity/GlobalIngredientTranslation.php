<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'unique_global_ingredient_locale', columns: ['global_ingredient_id', 'locale'])]
class GlobalIngredientTranslation
{
    /** Imported from config/global_ingredients.csv. */
    public const SOURCE_DATASET = 'dataset';
    /** Machine-translated (app:global-ingredients:translate, import backfill): searchable, never auto-linked. */
    public const SOURCE_AI = 'ai';
    public const SOURCE_HUMAN = 'human';

    public const SOURCES = [self::SOURCE_DATASET, self::SOURCE_AI, self::SOURCE_HUMAN];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GlobalIngredient::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false)]
    private GlobalIngredient $globalIngredient;

    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column(length: 255)]
    private string $name;

    // No default on purpose: every write must state where the name came
    // from, so a forgotten setSource() fails the INSERT (NOT NULL) instead
    // of silently passing an AI name off as dataset.
    #[ORM\Column(length: 10)]
    private string $source;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGlobalIngredient(): GlobalIngredient
    {
        return $this->globalIngredient;
    }

    public function setGlobalIngredient(GlobalIngredient $globalIngredient): void
    {
        $this->globalIngredient = $globalIngredient;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown global ingredient name source "%s".', $source));
        }
        $this->source = $source;
    }

    public function isAiGenerated(): bool
    {
        return $this->source === self::SOURCE_AI;
    }
}
