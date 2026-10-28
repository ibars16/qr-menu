<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Name provenance for the global ingredient library.
 *
 * global_ingredient_translation.source ('dataset' | 'ai' | 'human'): who
 * wrote each localized name, so AI-translated names can be searched in
 * autocomplete but never used for automatic linking, and an AI pass can
 * never overwrite a dataset or hand-written name.
 *
 * Backfill: every existing row (4.703 en + 2.153 es on 2026-10-28) comes
 * from the imported dataset → 'dataset'.
 *
 * The default only exists for the backfill and is dropped afterwards, so
 * every future write has to state its source explicitly.
 */
final class Version20261028100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add global_ingredient_translation.source (dataset|ai|human)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE global_ingredient_translation ADD source VARCHAR(10) DEFAULT \'dataset\' NOT NULL');
        $this->addSql('ALTER TABLE global_ingredient_translation ALTER source DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE global_ingredient_translation DROP source');
    }
}
