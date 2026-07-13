<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713074307 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename product_tag.is_preset to is_system (same values, stronger meaning — see ProductTag), and add a unique constraint on (restaurant_id, code) so a system tag\'s code can never collide with a restaurant-created one';
    }

    public function up(Schema $schema): void
    {
        // A plain rename — not add-new/drop-old — so every existing
        // preset/system flag survives exactly as it was, for every
        // restaurant, with no backfill statement to get wrong.
        $this->addSql('ALTER TABLE product_tag RENAME COLUMN is_preset TO is_system');
        $this->addSql('CREATE UNIQUE INDEX unique_product_tag_restaurant_code ON product_tag (restaurant_id, code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX unique_product_tag_restaurant_code');
        $this->addSql('ALTER TABLE product_tag RENAME COLUMN is_system TO is_preset');
    }
}
