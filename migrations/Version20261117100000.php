<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Acepto los términos y la política de privacidad" at sign-up: when the
 * account accepted them and which version (the terms' date in
 * config/legal.yaml). Both NULL for existing accounts and for those created
 * by someone else (staff, the platform, a reseller).
 */
final class Version20261117100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.terms_accepted_at and user.terms_version';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD terms_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD terms_version VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP terms_accepted_at');
        $this->addSql('ALTER TABLE "user" DROP terms_version');
    }
}
