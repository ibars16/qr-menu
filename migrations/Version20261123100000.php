<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * app:billing:send-notices: the paidUntil each owner email ("tu prueba
 * gratis acaba mañana", "tu carta está pausada") was last sent for, so it
 * goes out once per period. NULL = never sent.
 */
final class Version20261123100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add restaurant.trial_ending_notice_for and restaurant.paused_notice_for';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant ADD trial_ending_notice_for DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE restaurant ADD paused_notice_for DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant DROP trial_ending_notice_for');
        $this->addSql('ALTER TABLE restaurant DROP paused_notice_for');
    }
}
