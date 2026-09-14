<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-dish opt-out for showing the nutrition-facts panel on the public
 * menu, independent of the admin form's own expand/collapse of the
 * nutrition fields (that's just editor UI, see Product::$nutritionVisible's
 * docblock). Defaults TRUE so every existing row (and every dish that
 * already had calories/fat/protein/carbohydrates/sugars filled in) keeps
 * rendering exactly as it did before this migration.
 */
final class Version20260914083455 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product.nutrition_visible (default true) — per-dish public-menu opt-out for nutrition facts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD nutrition_visible BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP nutrition_visible');
    }
}
