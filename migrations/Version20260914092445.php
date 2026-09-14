<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Product::$videoClip — a nullable filename column, same shape as
 * $image, added purely so a dish can carry an optional short clip
 * alongside its existing photo (see Product.php's docblock on the
 * property). Does not touch $image or any existing column/index.
 *
 * NOTE: doctrine:migrations:diff also picked up a batch of pre-existing
 * schema drift unrelated to this change (dropped DEFAULTs on several
 * tables, a few dropped indexes) — that's tracked separately as its own
 * cleanup debt, not folded in here on purpose.
 */
final class Version20260914092445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Product.videoClip (nullable filename, mirrors Product.image)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD video_clip VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP video_clip');
    }
}
