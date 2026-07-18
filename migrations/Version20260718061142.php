<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds category.type: nullable, short varchar backing the CategoryType enum
 * (food/drink; null means unclassified). See Category::$type.
 *
 * The auto-generated diff also included unrelated pre-existing schema drift
 * (trigram index drops, DEFAULT-clause mismatches on category/product/
 * product_tag/restaurant columns, a stale unique_tag_locale index) —
 * stripped out here, same as every prior migration this session; only the
 * type column is this migration's concern.
 */
final class Version20260718061142 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add category.type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD type VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category DROP type');
    }
}
