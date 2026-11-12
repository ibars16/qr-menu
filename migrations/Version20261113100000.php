<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI dish photos per calendar month for restaurants that have paid
 * (App\Service\DishPhoto\DishPhotoQuota::MONTHLY_LIMIT):
 *  - restaurant.ai_photo_month: the first day of the month the counter
 *    below belongs to; NULL = none generated yet.
 *  - restaurant.ai_photo_month_count: photos generated in that month. A
 *    new month starts it again at 1 in the same UPDATE that reserves.
 * Existing rows: nothing counted this month.
 */
final class Version20261113100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add restaurant.ai_photo_month and restaurant.ai_photo_month_count';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant ADD ai_photo_month DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE restaurant ADD ai_photo_month_count INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant DROP ai_photo_month');
        $this->addSql('ALTER TABLE restaurant DROP ai_photo_month_count');
    }
}
