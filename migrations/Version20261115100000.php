<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops category.type (food/drink): the category type, its public-menu
 * filter and the admin selector were removed. down() restores the column
 * empty — the values it held are not recoverable.
 */
final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop category.type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category DROP type');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD type VARCHAR(20) DEFAULT NULL');
    }
}
