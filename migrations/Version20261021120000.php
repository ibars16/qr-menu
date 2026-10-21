<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ProductTagTranslation::$source — who wrote a tag's translation:
 * 'human' (the owner, admin), 'ai' (AiTagTranslator) or 'preset'
 * (config/preset_tags.yaml via DefaultTagSeeder /
 * app:tags:resync-preset-translations). Renaming a tag drops its 'ai' and
 * 'preset' rows so they're regenerated from the new name; 'human' stays.
 *
 * Backfill: system tags' translations in any language other than the
 * restaurant's default can only have come from the preset YAML → 'preset'.
 * Everything else stays 'human' — custom tags' non-default rows can't be
 * told apart reliably (e.g. trattoria-roma's legacy pre-isSystem tags carry
 * 'en' rows that weren't AI-generated), so none are marked 'ai'.
 */
final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product_tag_translation.source (human|ai|preset); mark system tags\' non-default translations as preset';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_tag_translation ADD source VARCHAR(10) DEFAULT \'human\' NOT NULL');
        $this->addSql('UPDATE product_tag_translation t
            SET source = \'preset\'
            FROM product_tag g
            JOIN restaurant r ON r.id = g.restaurant_id
            WHERE g.id = t.tag_id
              AND g.is_system = true
              AND t.locale <> r.default_language');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_tag_translation DROP source');
    }
}
