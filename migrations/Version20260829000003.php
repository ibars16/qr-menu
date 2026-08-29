<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Restaurant::$menuContentVersion — bumped by every admin write that
 * changes something the public menu displays, folded into MenuController's
 * menu-content cache keys so a bump alone makes previously-cached keys for
 * that restaurant unused. Hand-trimmed per the same convention as
 * Version20260826074813: the auto-generated diff also picked up unrelated
 * pre-existing schema drift (DROP DEFAULT tweaks, stale trgm/unique
 * indexes) that isn't part of this change.
 */
final class Version20260829000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add restaurant.menu_content_version for the public menu content cache';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant ADD menu_content_version INT NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant DROP menu_content_version');
    }
}
