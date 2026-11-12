<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * user.email_confirmation_pending: a self sign-up (/register, /alta/{code})
 * that hasn't confirmed its email yet — its public menu stays hidden.
 * Existing accounts: false (nothing changes for them).
 */
final class Version20261112100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.email_confirmation_pending';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD email_confirmation_pending BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP email_confirmation_pending');
    }
}
