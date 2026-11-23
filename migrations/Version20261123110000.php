<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Importar carta con IA: a PDF page's own text layer (pdftotext), kept so
 * MenuImportAssembler can check what the AI read against it — a section
 * continued on the next page, and "Copa … · Botella …" prices. NULL for a
 * photo, a scanned PDF page, and every page imported before this.
 */
final class Version20261123110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add menu_import_page.source_text';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE menu_import_page ADD source_text TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE menu_import_page DROP source_text');
    }
}
