<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the two columns behind this session's AI menu-import improvements:
 *
 * - product.supplement_price: nullable, cents — a fixed-price-menu ("menú
 *   del día") surcharge, kept completely separate from product.base_price.
 * - product_ingredient.ai_suggested / product_global_ingredient.ai_suggested:
 *   true when the ingredient was named in the dish's own printed name rather
 *   than an explicit list — see ProductIngredient::$aiSuggested. Backfilled
 *   to false for every existing row (nothing before this migration could be
 *   name-derived), then made NOT NULL with no default.
 *
 * The auto-generated diff also included unrelated pre-existing schema drift
 * (trigram index drops, DEFAULT-clause mismatches on category/product/
 * product_tag/restaurant columns, a stale unique_tag_locale index) —
 * stripped out here, same as every prior migration this session; only the
 * three columns above are this migration's concern.
 */
final class Version20260716055404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product.supplement_price and product_(global_)ingredient.ai_suggested';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD supplement_price INT DEFAULT NULL');

        $this->addSql('ALTER TABLE product_ingredient ADD ai_suggested BOOLEAN DEFAULT false');
        $this->addSql('UPDATE product_ingredient SET ai_suggested = false WHERE ai_suggested IS NULL');
        $this->addSql('ALTER TABLE product_ingredient ALTER COLUMN ai_suggested SET NOT NULL');
        $this->addSql('ALTER TABLE product_ingredient ALTER COLUMN ai_suggested DROP DEFAULT');

        $this->addSql('ALTER TABLE product_global_ingredient ADD ai_suggested BOOLEAN DEFAULT false');
        $this->addSql('UPDATE product_global_ingredient SET ai_suggested = false WHERE ai_suggested IS NULL');
        $this->addSql('ALTER TABLE product_global_ingredient ALTER COLUMN ai_suggested SET NOT NULL');
        $this->addSql('ALTER TABLE product_global_ingredient ALTER COLUMN ai_suggested DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP supplement_price');
        $this->addSql('ALTER TABLE product_ingredient DROP ai_suggested');
        $this->addSql('ALTER TABLE product_global_ingredient DROP ai_suggested');
    }
}
