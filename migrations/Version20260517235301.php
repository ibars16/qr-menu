<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260517235301 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE category (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, position INTEGER NOT NULL, active BOOLEAN NOT NULL, restaurant_id INTEGER NOT NULL, CONSTRAINT FK_64C19C1B1E7706E FOREIGN KEY (restaurant_id) REFERENCES restaurant (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_64C19C1B1E7706E ON category (restaurant_id)');
        $this->addSql('CREATE TABLE category_translation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, locale VARCHAR(5) NOT NULL, name VARCHAR(255) NOT NULL, category_id INTEGER NOT NULL, CONSTRAINT FK_3F2070412469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_3F2070412469DE2 ON category_translation (category_id)');
        $this->addSql('CREATE TABLE ingredient (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(100) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6BAF787077153098 ON ingredient (code)');
        $this->addSql('CREATE TABLE ingredient_translation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, locale VARCHAR(5) NOT NULL, name VARCHAR(255) NOT NULL, ingredient_id INTEGER NOT NULL, CONSTRAINT FK_C1A8BF6933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_C1A8BF6933FE08C ON ingredient_translation (ingredient_id)');
        $this->addSql('CREATE TABLE product (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, image VARCHAR(255) DEFAULT NULL, base_price INTEGER NOT NULL, calories INTEGER DEFAULT NULL, spicy_level INTEGER DEFAULT NULL, vegetarian BOOLEAN NOT NULL, vegan BOOLEAN NOT NULL, gluten_free BOOLEAN NOT NULL, active BOOLEAN NOT NULL, position INTEGER NOT NULL, category_id INTEGER NOT NULL, CONSTRAINT FK_D34A04AD12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_D34A04AD12469DE2 ON product (category_id)');
        $this->addSql('CREATE TABLE product_ingredient (product_id INTEGER NOT NULL, ingredient_id INTEGER NOT NULL, PRIMARY KEY (product_id, ingredient_id), CONSTRAINT FK_F8C8275B4584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F8C8275B933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_F8C8275B4584665A ON product_ingredient (product_id)');
        $this->addSql('CREATE INDEX IDX_F8C8275B933FE08C ON product_ingredient (ingredient_id)');
        $this->addSql('CREATE TABLE product_translation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, locale VARCHAR(5) NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, product_id INTEGER NOT NULL, CONSTRAINT FK_1846DB704584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_1846DB704584665A ON product_translation (product_id)');
        $this->addSql('CREATE TABLE restaurant (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, logo VARCHAR(255) DEFAULT NULL, primary_color VARCHAR(20) NOT NULL, currency VARCHAR(3) NOT NULL, default_language VARCHAR(5) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EB95123F989D9B62 ON restaurant (slug)');
        $this->addSql('CREATE TABLE "table" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, number VARCHAR(50) NOT NULL, qr_token VARCHAR(255) NOT NULL, restaurant_id INTEGER NOT NULL, CONSTRAINT FK_F6298F46B1E7706E FOREIGN KEY (restaurant_id) REFERENCES restaurant (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F6298F461AE26361 ON "table" (qr_token)');
        $this->addSql('CREATE INDEX IDX_F6298F46B1E7706E ON "table" (restaurant_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE category_translation');
        $this->addSql('DROP TABLE ingredient');
        $this->addSql('DROP TABLE ingredient_translation');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE product_ingredient');
        $this->addSql('DROP TABLE product_translation');
        $this->addSql('DROP TABLE restaurant');
        $this->addSql('DROP TABLE "table"');
    }
}
