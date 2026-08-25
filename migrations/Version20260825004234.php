<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds ProductTranslation::$source ('human' vs 'ai') so AI-generated dish
 * translations can be told apart from ones an admin typed in, and safely
 * invalidated on their own when the source-locale text changes. Hand-trimmed
 * per the same convention as Version20260720073705.
 */
final class Version20260825004234 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add product_translation.source ('human'/'ai', default 'human')";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE product_translation ADD source VARCHAR(10) NOT NULL DEFAULT 'human'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_translation DROP source');
    }
}
