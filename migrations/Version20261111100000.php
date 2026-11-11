<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dish photos generated with AI (App\Service\DishPhoto):
 *  - product.image_source: NULL (owner's own photo, a clip's poster, or no
 *    photo) or 'ai' (generated). Internal only, never shown on the menu.
 *  - restaurant.ai_photo_count: photos generated so far (saved or not);
 *    capped at 2 while plan IS NULL.
 *  - menu_import_batch.ai_photos_status: "Crear sus fotos con IA" after an
 *    import — NULL | 'running' | 'paused' | 'dismissed' | 'done'.
 *  - menu_import_batch.ai_photos_skipped: product ids the AI couldn't make
 *    a photo for; the batch never retries them.
 * Existing rows: no AI photos, nothing counted, no batch started.
 */
final class Version20261111100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product.image_source, restaurant.ai_photo_count, menu_import_batch.ai_photos_status/ai_photos_skipped';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD image_source VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE restaurant ADD ai_photo_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE menu_import_batch ADD ai_photos_status VARCHAR(20) DEFAULT NULL');
        $this->addSql("ALTER TABLE menu_import_batch ADD ai_photos_skipped JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP image_source');
        $this->addSql('ALTER TABLE restaurant DROP ai_photo_count');
        $this->addSql('ALTER TABLE menu_import_batch DROP ai_photos_status');
        $this->addSql('ALTER TABLE menu_import_batch DROP ai_photos_skipped');
    }
}
