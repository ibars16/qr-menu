<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Each reseller gets a signup link for its customers (/alta/{code}): the
 * restaurant created there belongs to it. Existing resellers get a random
 * code now; new ones get one in the constructor.
 */
final class Version20261105100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reseller.signup_code (unique) for the reseller signup link';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reseller ADD signup_code VARCHAR(32) DEFAULT NULL');
        $this->addSql("UPDATE reseller SET signup_code = replace(gen_random_uuid()::text, '-', '') WHERE signup_code IS NULL");
        $this->addSql('ALTER TABLE reseller ALTER signup_code SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_reseller_signup_code ON reseller (signup_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_reseller_signup_code');
        $this->addSql('ALTER TABLE reseller DROP signup_code');
    }
}
