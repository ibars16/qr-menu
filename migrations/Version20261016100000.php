<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contact details and social links for the restaurant, edited from the
 * admin's Configuración screen: Restaurant::$socialLinks (normalized URL per
 * network, absent key = not set), $address, $phone (as typed by the owner,
 * trimmed) and $openingHours ({"mon": [["13:00","16:00"], …], "sun": []};
 * absent day = not configured). All nullable, no backfill: existing rows
 * simply have none of them set.
 */
final class Version20261016100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Restaurant.socialLinks / address / phone / openingHours (nullable)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant ADD social_links JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE restaurant ADD address VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE restaurant ADD phone VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE restaurant ADD opening_hours JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restaurant DROP social_links');
        $this->addSql('ALTER TABLE restaurant DROP address');
        $this->addSql('ALTER TABLE restaurant DROP phone');
        $this->addSql('ALTER TABLE restaurant DROP opening_hours');
    }
}
