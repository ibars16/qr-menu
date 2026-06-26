<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260626120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split theme into layout + theme (standard/compact/grid × classic-dark/classic-warm/glass/ocean/noir)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE restaurant ADD layout VARCHAR(20) NOT NULL DEFAULT 'standard'");

        // Migrate existing combined theme values to separate layout + theme
        $this->addSql("UPDATE restaurant SET layout = 'compact',  theme = 'classic-dark' WHERE theme = 'bold'");
        $this->addSql("UPDATE restaurant SET layout = 'grid',     theme = 'classic-warm' WHERE theme = 'grid'");
        $this->addSql("UPDATE restaurant SET layout = 'standard', theme = 'glass'        WHERE theme = 'glass'");
        $this->addSql("UPDATE restaurant SET layout = 'standard', theme = 'classic-dark' WHERE theme = 'classic'");
    }

    public function down(Schema $schema): void
    {
        // Reverse: restore old combined theme values from layout+theme combos
        $this->addSql("UPDATE restaurant SET theme = 'bold'    WHERE layout = 'compact'  AND theme = 'classic-dark'");
        $this->addSql("UPDATE restaurant SET theme = 'grid'    WHERE layout = 'grid'     AND theme = 'classic-warm'");
        $this->addSql("UPDATE restaurant SET theme = 'glass'   WHERE layout = 'standard' AND theme = 'glass'");
        $this->addSql("UPDATE restaurant SET theme = 'classic' WHERE layout = 'standard' AND theme = 'classic-dark'");
        $this->addSql("ALTER TABLE restaurant DROP COLUMN layout");
    }
}
