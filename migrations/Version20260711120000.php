<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin_locale column to restaurant (per-restaurant Admin Panel UI language)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE restaurant ADD admin_locale VARCHAR(5) DEFAULT 'es' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant DROP admin_locale');
    }
}
