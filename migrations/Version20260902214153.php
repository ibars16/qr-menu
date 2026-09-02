<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes the 'bold', 'classic' and 'grid' public-menu themes — relics of
 * the pre-Version20260626120000 combined theme+layout naming (their own
 * templates/menu/themes/<x>/show.html.twig confirm this: full self-contained
 * pages from that old architecture, not the current vars.html.twig +
 * overrides.html.twig pair every live theme has). Confirmed unreachable
 * before deleting: no restaurant.theme row, not in ThemeController::THEMES
 * (never offered in the picker), not even in MenuController's preview
 * whitelist. Data first, before the app code stops recognizing those
 * values — same reasoning as Version20260902205849 (compact/grid layouts).
 * Idempotent, no-op for any restaurant already elsewhere.
 */
final class Version20260902214153 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Migrate any restaurant.theme in ('bold','classic','grid') to 'classic-dark' — those themes are being removed";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE restaurant SET theme = 'classic-dark' WHERE theme IN ('bold', 'classic', 'grid')");
    }

    public function down(Schema $schema): void
    {
        // Not reversible — same reasoning as Version20260902205849's down():
        // lossy (original bold/classic/grid choice unrecoverable), and the
        // themes are deleted from the app in this same change.
    }
}
