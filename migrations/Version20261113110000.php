<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * user.pending_email: a login email change waiting for its confirmation
 * link (App\Service\EmailChange). Existing rows: nothing pending.
 */
final class Version20261113110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.pending_email';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD pending_email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP pending_email');
    }
}
