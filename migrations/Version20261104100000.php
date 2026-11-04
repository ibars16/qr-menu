<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Resellers charge their restaurants through their own Stripe account
 * (Stripe Connect, Accounts v2: full Dashboard, Stripe-collected fees and
 * losses, direct charges). The reseller's connected account and whether it
 * can take card payments yet; its price for its restaurants (cents, the
 * yearly one optional) and the product/prices created in ITS account; and,
 * per restaurant, the Stripe account its customer and subscription live in
 * (used from step 2 on).
 *
 * No backfill: every reseller starts without Stripe connected.
 */
final class Version20261104100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reseller Stripe Connect account, restaurant price and connected product/prices; restaurant.stripe_account_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reseller ADD connect_account_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE reseller ADD connect_charges_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE reseller ADD restaurant_price_monthly INT DEFAULT NULL');
        $this->addSql('ALTER TABLE reseller ADD restaurant_price_yearly INT DEFAULT NULL');
        $this->addSql('ALTER TABLE reseller ADD connect_product_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE reseller ADD connect_price_monthly_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE reseller ADD connect_price_yearly_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_reseller_connect_account ON reseller (connect_account_id)');

        $this->addSql('ALTER TABLE restaurant ADD stripe_account_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant DROP stripe_account_id');

        $this->addSql('DROP INDEX UNIQ_reseller_connect_account');
        $this->addSql('ALTER TABLE reseller DROP connect_price_yearly_id');
        $this->addSql('ALTER TABLE reseller DROP connect_price_monthly_id');
        $this->addSql('ALTER TABLE reseller DROP connect_product_id');
        $this->addSql('ALTER TABLE reseller DROP restaurant_price_yearly');
        $this->addSql('ALTER TABLE reseller DROP restaurant_price_monthly');
        $this->addSql('ALTER TABLE reseller DROP connect_charges_enabled');
        $this->addSql('ALTER TABLE reseller DROP connect_account_id');
    }
}
