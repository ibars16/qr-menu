<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds product.second_price_label: nullable, short varchar — an override
 * for the label shown next to the bottle price when product.glass_price is
 * set (default label is "Botella"). See Product::$secondPriceLabel.
 *
 * The auto-generated diff also included unrelated pre-existing schema drift
 * (trigram index drops, DEFAULT-clause mismatches on category/product/
 * product_tag/restaurant columns, a stale unique_tag_locale index) —
 * stripped out here, same as every prior migration this session; only the
 * second_price_label column is this migration's concern.
 */
final class Version20260717083923 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product.second_price_label';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD second_price_label VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP second_price_label');
    }
}
