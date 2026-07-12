<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable pg_trgm and add a trigram index on ingredient_translation.name for fast autocomplete search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE INDEX idx_ingredient_translation_name_trgm ON ingredient_translation USING gin (name gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ingredient_translation_name_trgm');
    }
}
