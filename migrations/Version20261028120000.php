<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * restaurant.paid_until: the only thing that decides whether a restaurant
 * is active (expired = paid_until before today).
 *
 * Backfill: every existing restaurant gets migration day + 30 days (the
 * free trial). The date is computed in PHP, not with CURRENT_DATE, so
 * "today" is the same (UTC) one the app uses. The default only exists for
 * the backfill and is dropped afterwards, so every future write has to set
 * it explicitly.
 */
final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add restaurant.paid_until (backfill: migration day + 30 days)';
    }

    public function up(Schema $schema): void
    {
        $backfill = (new \DateTimeImmutable('today +30 days', new \DateTimeZone('UTC')))->format('Y-m-d');

        $this->addSql(sprintf('ALTER TABLE restaurant ADD paid_until DATE DEFAULT \'%s\' NOT NULL', $backfill));
        $this->addSql('ALTER TABLE restaurant ALTER paid_until DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant DROP paid_until');
    }
}
