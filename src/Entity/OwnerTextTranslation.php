<?php

namespace App\Entity;

use App\Repository\OwnerTextTranslationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * AI translation of one of the owner's free texts that has no translation
 * table of its own — tagline, multiprice labels (base + variants), set-menu
 * description and section labels (see OwnerTextTranslationService).
 *
 * Keyed by the SOURCE TEXT, not by the entity that holds it: $sourceHash is
 * self::hash(source locale, text). A label repeated across dishes ("Copa")
 * is one row per locale, and editing a text just produces a new hash, so a
 * stale translation is never looked up — nothing to invalidate per screen.
 * Write-once (plain createdAt, like ProductView).
 */
#[ORM\Entity(repositoryClass: OwnerTextTranslationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_owner_text_tr', columns: ['restaurant_id', 'locale', 'source_hash'])]
class OwnerTextTranslation
{
    public const ORIGIN_AI    = 'ai';
    public const ORIGIN_HUMAN = 'human';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Restaurant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Restaurant $restaurant;

    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column(length: 40, options: ['fixed' => true])]
    private string $sourceHash;

    #[ORM\Column(type: Types::TEXT)]
    private string $sourceText;

    #[ORM\Column(type: Types::TEXT)]
    private string $translatedText;

    #[ORM\Column(length: 10, options: ['default' => self::ORIGIN_AI])]
    private string $origin = self::ORIGIN_AI;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Restaurant $restaurant, string $locale, string $sourceLocale, string $sourceText, string $translatedText, string $origin = self::ORIGIN_AI)
    {
        $this->restaurant     = $restaurant;
        $this->locale         = $locale;
        $this->sourceHash     = self::hash($sourceLocale, $sourceText);
        $this->sourceText     = $sourceText;
        $this->translatedText = $translatedText;
        $this->origin         = $origin;
        $this->createdAt      = new \DateTimeImmutable();
    }

    /** sha1(source locale + "\0" + text): the same text in another source language is a different row. */
    public static function hash(string $sourceLocale, string $sourceText): string
    {
        return sha1($sourceLocale . "\0" . $sourceText);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRestaurant(): Restaurant
    {
        return $this->restaurant;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getSourceHash(): string
    {
        return $this->sourceHash;
    }

    public function getSourceText(): string
    {
        return $this->sourceText;
    }

    public function getTranslatedText(): string
    {
        return $this->translatedText;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
