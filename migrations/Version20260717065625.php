<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds product.glass_price: nullable, cents — the per-glass price for a
 * drink also sold by the bottle (e.g. "Copa 4,00€ / Botella 18,00€"). When
 * set, product.base_price is read as the bottle price. Kept completely
 * separate from product.base_price and product.supplement_price, same
 * reasoning as Version20260716055404.
 *
 * The auto-generated diff also included unrelated pre-existing schema drift
 * (trigram index drops, DEFAULT-clause mismatches on category/product/
 * product_tag/restaurant columns, a stale unique_tag_locale index) —
 * stripped out here, same as every prior migration this session; only the
 * glass_price column is this migration's concern.
 */
final class Version20260717065625 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product.glass_price';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD glass_price INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP glass_price');
    }
}
