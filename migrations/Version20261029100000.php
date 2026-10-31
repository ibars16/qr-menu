<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stripe billing for restaurant owners.
 *
 * restaurant.stripe_customer_id: the Stripe customer (cus_…), created on the
 * first checkout and reused afterwards (one per restaurant).
 * restaurant.stripe_subscription_id: the current Stripe subscription
 * (sub_…); null = none.
 *
 * No backfill: every existing restaurant has neither.
 */
final class Version20261029100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add restaurant.stripe_customer_id and restaurant.stripe_subscription_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant ADD stripe_customer_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE restaurant ADD stripe_subscription_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_restaurant_stripe_customer ON restaurant (stripe_customer_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_restaurant_stripe_customer');
        $this->addSql('ALTER TABLE restaurant DROP stripe_customer_id');
        $this->addSql('ALTER TABLE restaurant DROP stripe_subscription_id');
    }
}
