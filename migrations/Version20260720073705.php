<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Restaurant::$setMenusEnabled — the "Set menus" feature flag, hidden
 * by default. Hand-trimmed: doctrine:migrations:diff also picked up
 * unrelated pre-existing schema drift (stale trigram indexes, DEFAULT-clause
 * mismatches on unrelated columns) — only the new column is kept here.
 * DEFAULT FALSE is required (not just the entity's PHP default) so this
 * ALTER TABLE succeeds against the restaurant table's existing rows.
 */
final class Version20260720073705 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add restaurant.set_menus_enabled (Set menus feature flag, default false)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant ADD set_menus_enabled BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant DROP set_menus_enabled');
    }
}
