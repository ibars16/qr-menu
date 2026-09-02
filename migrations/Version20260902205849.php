<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes the 'compact' and 'grid' public-menu layouts — 'standard' (with
 * the maison theme) is now the only one. Data first, before the app code
 * stops recognizing those values, so no restaurant is ever left pointing
 * at a layout the app no longer knows how to render. Idempotent: safe to
 * run again, and a no-op for any restaurant already on 'standard'.
 *
 * The column's own DEFAULT ('standard', set in Version20260626120000) was
 * already correct — nothing to alter there.
 */
final class Version20260902205849 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Migrate any restaurant.layout in ('compact','grid') to 'standard' — those layouts are being removed";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE restaurant SET layout = 'standard' WHERE layout IN ('compact', 'grid')");
    }

    public function down(Schema $schema): void
    {
        // Not reversible: this UPDATE is lossy (a restaurant's original
        // 'compact' vs 'grid' choice isn't recoverable once collapsed to
        // 'standard'), and the layouts themselves are being deleted from
        // the app in this same change — there would be nothing to render
        // them with even if the value were restored.
    }
}
