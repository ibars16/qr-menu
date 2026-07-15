<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI Menu Import, Phase 2: adds the two columns menu_import_page's extracted
 * content actually lands in — extracted_data (the raw structured JSON a
 * vision provider returns, see MenuVisionPromptBuilder for its shape) and
 * detected_locale (the menu's own printed language, as reported by that
 * same call). Both nullable — Phase 1's rows, and any page that later fails
 * extraction, simply leave them unset.
 *
 * The auto-generated diff again included the same unrelated pre-existing
 * drift as every migration this session (trigram index naming,
 * DEFAULT-clause mismatches on product_tag/restaurant/needs_review) —
 * stripped out; only the two new columns are this migration's concern.
 */
final class Version20260714090709 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI menu import Phase 2: extracted_data and detected_locale on menu_import_page';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE menu_import_page ADD extracted_data JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE menu_import_page ADD detected_locale VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE menu_import_page DROP extracted_data');
        $this->addSql('ALTER TABLE menu_import_page DROP detected_locale');
    }
}
