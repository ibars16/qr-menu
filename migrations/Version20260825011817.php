<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds CategoryTranslation::$source ('human' vs 'ai') — the category-name
 * twin of Version20260825004234's ProductTranslation::$source. Hand-trimmed
 * per the same convention as Version20260720073705.
 */
final class Version20260825011817 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add category_translation.source ('human'/'ai', default 'human')";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE category_translation ADD source VARCHAR(10) NOT NULL DEFAULT 'human'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category_translation DROP source');
    }
}
