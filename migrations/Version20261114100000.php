<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * product.import_duplicate: a dish the AI import created hidden because the
 * same name already existed in its category (in the carta or earlier in the
 * same upload) — see MenuImportAssembler. Existing rows: false.
 */
final class Version20261114100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product.import_duplicate';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD import_duplicate BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP import_duplicate');
    }
}
