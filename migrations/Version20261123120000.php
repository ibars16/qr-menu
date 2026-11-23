<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Importar carta con IA: the 1-based number of each page inside the PDF it
 * came from, so MenuImportAssembler only continues a section between pages
 * of the SAME PDF. NULL for a photo and for every page imported before this.
 */
final class Version20261123120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add menu_import_page.pdf_page_number';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE menu_import_page ADD pdf_page_number INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE menu_import_page DROP pdf_page_number');
    }
}
