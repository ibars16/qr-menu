<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The email a reseller's restaurants see to contact it, edited on
 * /partners. Null = its user's email.
 */
final class Version20261106100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reseller.contact_email';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reseller ADD contact_email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reseller DROP contact_email');
    }
}
