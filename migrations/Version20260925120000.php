<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Product::$videoZoom (owner-chosen zoom of a dish clip, 0–100) and
 * Product::$videoWidth/$videoHeight (the clip's display size, read with
 * ffprobe on upload). All nullable: a clip without dimensions keeps
 * rendering exactly as before (object-fit:cover).
 */
final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Product.videoZoom / videoWidth / videoHeight (clip fit, nullable)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD video_zoom SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD video_width INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD video_height INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP video_zoom');
        $this->addSql('ALTER TABLE product DROP video_width');
        $this->addSql('ALTER TABLE product DROP video_height');
    }
}
