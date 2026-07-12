<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_preset flag to product_tag and unique index on product_tag_translation(tag_id, locale)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_tag ADD is_preset BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('CREATE UNIQUE INDEX unique_tag_locale ON product_tag_translation (tag_id, locale)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX unique_tag_locale');
        $this->addSql('ALTER TABLE product_tag DROP COLUMN is_preset');
    }
}
