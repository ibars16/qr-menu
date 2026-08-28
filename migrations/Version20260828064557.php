<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data-only migration (no schema change — `user.roles` is already a JSON
 * column, see Version20260604022603): promotes every existing user to
 * User::ROLE_OWNER. Introducing the Owner/Staff split (see
 * Admin\UsersController) means "roles = ['ROLE_USER']" no longer implies
 * full access — every account created before this point was, in practice,
 * the sole full-access user of its restaurant, so this preserves today's
 * behavior for all of them rather than silently locking anyone out.
 */
final class Version20260828064557 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill existing users to ROLE_OWNER ahead of the Owner/Staff role split';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE "user" SET roles = \'["ROLE_OWNER"]\'::json');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE "user" SET roles = \'["ROLE_USER"]\'::json');
    }
}
