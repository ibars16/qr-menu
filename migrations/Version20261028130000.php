<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Subscription plan for the platform panel (/platform).
 *
 * restaurant.plan ('monthly' | 'yearly'): the last plan paid for; null =
 * never paid (free trial until paid_until). restaurant.last_paid_at: date of
 * the last payment registered from /platform; null = none.
 *
 * No backfill: every existing restaurant keeps both null, i.e. on trial
 * until its current paid_until.
 */
final class Version20261028130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add restaurant.plan (monthly|yearly) and restaurant.last_paid_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant ADD plan VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE restaurant ADD last_paid_at DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant DROP plan');
        $this->addSql('ALTER TABLE restaurant DROP last_paid_at');
    }
}
