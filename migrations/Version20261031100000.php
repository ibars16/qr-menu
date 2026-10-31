<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * restaurant.stripe_cancel_pending: the live Stripe subscription won't renew
 * (cancel_at_period_end, or a cancel_at date) — set from
 * customer.subscription.updated, cleared when the owner undoes it or the
 * subscription is deleted. Only shown on /platform.
 *
 * No backfill: false for everyone; the next subscription event corrects it.
 */
final class Version20261031100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add restaurant.stripe_cancel_pending';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant ADD stripe_cancel_pending BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant DROP stripe_cancel_pending');
    }
}
