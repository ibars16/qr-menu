<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Category::$menuPrice and Category::$menuDescription (fixed-price
 * "menú del día" support). Hand-trimmed: doctrine:migrations:diff also
 * picked up unrelated pre-existing schema drift (stale trigram indexes,
 * DEFAULT-clause mismatches on unrelated columns) — only the two new
 * nullable category columns are kept here.
 */
final class Version20260718083828 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add menuPrice and menuDescription to category (fixed-price menu support)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD menu_price INT DEFAULT NULL');
        $this->addSql('ALTER TABLE category ADD menu_description VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category DROP menu_price');
        $this->addSql('ALTER TABLE category DROP menu_description');
    }
}
