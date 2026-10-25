<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Allergen review provenance for ingredients.
 *
 * - global_ingredient / ingredient .allergens_reviewed_at / _by
 *   ('yaml' | 'ai' | 'human'): lets "reviewed, carries no allergens" be told
 *   apart from "never reviewed" — both look the same (no allergen links)
 *   without it. NULL = never reviewed.
 * - global_ingredient_allergen / ingredient_allergen .source
 *   ('yaml' | 'ai' | 'human'): who created each link, so an AI pass can
 *   never overwrite a hand-verified one.
 *
 * Backfill: the 51 existing global links are exactly the rows of
 * config/global_ingredient_allergens.yaml (verified by diff on 2026-10-24)
 * → 'yaml', and their 51 ingredients are marked reviewed_by 'yaml'. The
 * single existing private link was set by hand in the admin → 'human';
 * its ingredient is NOT marked reviewed, since one hand-set allergen
 * doesn't mean the other 13 were checked.
 *
 * The defaults only exist for the backfill and are dropped afterwards, so
 * every future write has to state its source explicitly.
 */
final class Version20261024100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add allergen review provenance: ingredient allergens_reviewed_at/_by and allergen link source (yaml|ai|human)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE global_ingredient ADD allergens_reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE global_ingredient ADD allergens_reviewed_by VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE ingredient ADD allergens_reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE ingredient ADD allergens_reviewed_by VARCHAR(10) DEFAULT NULL');

        $this->addSql('ALTER TABLE global_ingredient_allergen ADD source VARCHAR(10) DEFAULT \'yaml\' NOT NULL');
        $this->addSql('ALTER TABLE global_ingredient_allergen ALTER source DROP DEFAULT');
        $this->addSql('ALTER TABLE ingredient_allergen ADD source VARCHAR(10) DEFAULT \'human\' NOT NULL');
        $this->addSql('ALTER TABLE ingredient_allergen ALTER source DROP DEFAULT');

        $this->addSql('UPDATE global_ingredient gi
            SET allergens_reviewed_by = \'yaml\', allergens_reviewed_at = NOW()
            WHERE EXISTS (SELECT 1 FROM global_ingredient_allergen gia WHERE gia.global_ingredient_id = gi.id AND gia.source = \'yaml\')');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingredient_allergen DROP source');
        $this->addSql('ALTER TABLE global_ingredient_allergen DROP source');
        $this->addSql('ALTER TABLE ingredient DROP allergens_reviewed_by');
        $this->addSql('ALTER TABLE ingredient DROP allergens_reviewed_at');
        $this->addSql('ALTER TABLE global_ingredient DROP allergens_reviewed_by');
        $this->addSql('ALTER TABLE global_ingredient DROP allergens_reviewed_at');
    }
}
