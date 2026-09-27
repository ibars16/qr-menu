<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Product::$videoFocusX/$videoFocusY — the owner-chosen framing
 * (object-position %) of a dish clip inside its fixed box. Both nullable,
 * null = centred, so existing clips render exactly as before.
 */
final class Version20260925100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Product.videoFocusX / videoFocusY (clip framing, nullable smallint)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD video_focus_x SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD video_focus_y SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP video_focus_x');
        $this->addSql('ALTER TABLE product DROP video_focus_y');
    }
}
