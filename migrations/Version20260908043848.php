<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nutritional facts (Fase 1 of 3 — data layer only, see project memory):
 * four nullable per-dish fields, grams each, manually entered by the owner
 * in a future admin screen (Fase 2) and rendered only on the maison theme's
 * dish detail (Fase 3). Independent of the pre-existing product.calories
 * (kcal, added separately, untouched here) and of the allergen system
 * (computed from ingredients, unrelated). All four default to NULL and stay
 * NULL for every existing row — no backfill, no admin/template change here.
 */
final class Version20260908043848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product.fat/protein/carbohydrates/sugars (nullable, grams) for the maison nutrition panel';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD fat NUMERIC(5, 1) DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD protein NUMERIC(5, 1) DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD carbohydrates NUMERIC(5, 1) DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD sugars NUMERIC(5, 1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP fat');
        $this->addSql('ALTER TABLE product DROP protein');
        $this->addSql('ALTER TABLE product DROP carbohydrates');
        $this->addSql('ALTER TABLE product DROP sugars');
    }
}
