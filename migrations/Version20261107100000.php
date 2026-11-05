<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The language a restaurant's menu is being converted to (see
 * MenuLanguageChanger). Null = no change in progress.
 */
final class Version20261107100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add restaurant.language_change_to';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant ADD language_change_to VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant DROP language_change_to');
    }
}
