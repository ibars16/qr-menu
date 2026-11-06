<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notice when an import's dishes are in another language than the menu's
 * main one (see MenuImportBatch):
 *  - detected_language: ISO 639-1 code the import's texts were found to be
 *    in, NULL when not checked / the AI couldn't tell;
 *  - language_notice: NULL (no notice), 'pending', 'translating',
 *    'dismissed' ("Dejarla así"), 'translated'.
 * Existing imports stay NULL: no notice for them.
 */
final class Version20261108110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add menu_import_batch.detected_language and language_notice';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE menu_import_batch ADD detected_language VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE menu_import_batch ADD language_notice VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE menu_import_batch DROP detected_language');
        $this->addSql('ALTER TABLE menu_import_batch DROP language_notice');
    }
}
