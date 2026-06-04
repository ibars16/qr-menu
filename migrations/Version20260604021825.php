<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260604021825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE restaurant_table (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, number VARCHAR(50) NOT NULL, qr_token VARCHAR(255) NOT NULL, active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, restaurant_id INTEGER NOT NULL, CONSTRAINT FK_BC343C97B1E7706E FOREIGN KEY (restaurant_id) REFERENCES restaurant (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BC343C971AE26361 ON restaurant_table (qr_token)');
        $this->addSql('CREATE INDEX IDX_BC343C97B1E7706E ON restaurant_table (restaurant_id)');
        $this->addSql('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, roles CLOB NOT NULL, first_name VARCHAR(255) DEFAULT NULL, last_name VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, restaurant_id INTEGER DEFAULT NULL, CONSTRAINT FK_8D93D649B1E7706E FOREIGN KEY (restaurant_id) REFERENCES restaurant (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('CREATE INDEX IDX_8D93D649B1E7706E ON "user" (restaurant_id)');
        $this->addSql('DROP TABLE "table"');
        $this->addSql('ALTER TABLE category ADD COLUMN created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE category ADD COLUMN updated_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE TEMPORARY TABLE __temp__category_translation AS SELECT id, locale, name, category_id FROM category_translation');
        $this->addSql('DROP TABLE category_translation');
        $this->addSql('CREATE TABLE category_translation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, locale VARCHAR(5) NOT NULL, name VARCHAR(255) NOT NULL, category_id INTEGER NOT NULL, CONSTRAINT FK_3F2070412469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO category_translation (id, locale, name, category_id) SELECT id, locale, name, category_id FROM __temp__category_translation');
        $this->addSql('DROP TABLE __temp__category_translation');
        $this->addSql('CREATE INDEX IDX_3F2070412469DE2 ON category_translation (category_id)');
        $this->addSql('CREATE UNIQUE INDEX unique_category_locale ON category_translation (category_id, locale)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__ingredient AS SELECT id, code FROM ingredient');
        $this->addSql('DROP TABLE ingredient');
        $this->addSql('CREATE TABLE ingredient (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, restaurant_id INTEGER NOT NULL, CONSTRAINT FK_6BAF7870B1E7706E FOREIGN KEY (restaurant_id) REFERENCES restaurant (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO ingredient (id, code) SELECT id, code FROM __temp__ingredient');
        $this->addSql('DROP TABLE __temp__ingredient');
        $this->addSql('CREATE INDEX IDX_6BAF7870B1E7706E ON ingredient (restaurant_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__ingredient_translation AS SELECT id, locale, name, ingredient_id FROM ingredient_translation');
        $this->addSql('DROP TABLE ingredient_translation');
        $this->addSql('CREATE TABLE ingredient_translation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, locale VARCHAR(5) NOT NULL, name VARCHAR(255) NOT NULL, ingredient_id INTEGER NOT NULL, CONSTRAINT FK_C1A8BF6933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO ingredient_translation (id, locale, name, ingredient_id) SELECT id, locale, name, ingredient_id FROM __temp__ingredient_translation');
        $this->addSql('DROP TABLE __temp__ingredient_translation');
        $this->addSql('CREATE INDEX IDX_C1A8BF6933FE08C ON ingredient_translation (ingredient_id)');
        $this->addSql('CREATE UNIQUE INDEX unique_ingredient_locale ON ingredient_translation (ingredient_id, locale)');
        $this->addSql('ALTER TABLE product ADD COLUMN lactose_free BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE product ADD COLUMN contains_nuts BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE product ADD COLUMN halal BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE product ADD COLUMN kosher BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE product ADD COLUMN created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE product ADD COLUMN updated_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE TEMPORARY TABLE __temp__product_translation AS SELECT id, locale, name, description, product_id FROM product_translation');
        $this->addSql('DROP TABLE product_translation');
        $this->addSql('CREATE TABLE product_translation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, locale VARCHAR(5) NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, product_id INTEGER NOT NULL, CONSTRAINT FK_1846DB704584665A FOREIGN KEY (product_id) REFERENCES product (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO product_translation (id, locale, name, description, product_id) SELECT id, locale, name, description, product_id FROM __temp__product_translation');
        $this->addSql('DROP TABLE __temp__product_translation');
        $this->addSql('CREATE INDEX IDX_1846DB704584665A ON product_translation (product_id)');
        $this->addSql('CREATE UNIQUE INDEX unique_product_locale ON product_translation (product_id, locale)');
        $this->addSql('ALTER TABLE restaurant ADD COLUMN updated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE "table" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, number VARCHAR(50) NOT NULL COLLATE "BINARY", qr_token VARCHAR(255) NOT NULL COLLATE "BINARY", restaurant_id INTEGER NOT NULL, CONSTRAINT FK_F6298F46B1E7706E FOREIGN KEY (restaurant_id) REFERENCES restaurant (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_F6298F46B1E7706E ON "table" (restaurant_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F6298F461AE26361 ON "table" (qr_token)');
        $this->addSql('DROP TABLE restaurant_table');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('CREATE TEMPORARY TABLE __temp__category AS SELECT id, position, active, restaurant_id FROM category');
        $this->addSql('DROP TABLE category');
        $this->addSql('CREATE TABLE category (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, position INTEGER NOT NULL, active BOOLEAN NOT NULL, restaurant_id INTEGER NOT NULL, CONSTRAINT FK_64C19C1B1E7706E FOREIGN KEY (restaurant_id) REFERENCES restaurant (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO category (id, position, active, restaurant_id) SELECT id, position, active, restaurant_id FROM __temp__category');
        $this->addSql('DROP TABLE __temp__category');
        $this->addSql('CREATE INDEX IDX_64C19C1B1E7706E ON category (restaurant_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__category_translation AS SELECT id, locale, name, category_id FROM category_translation');
        $this->addSql('DROP TABLE category_translation');
        $this->addSql('CREATE TABLE category_translation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, locale VARCHAR(5) NOT NULL, name VARCHAR(255) NOT NULL, category_id INTEGER NOT NULL, CONSTRAINT FK_3F2070412469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO category_translation (id, locale, name, category_id) SELECT id, locale, name, category_id FROM __temp__category_translation');
        $this->addSql('DROP TABLE __temp__category_translation');
        $this->addSql('CREATE INDEX IDX_3F2070412469DE2 ON category_translation (category_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__ingredient AS SELECT id, code FROM ingredient');
        $this->addSql('DROP TABLE ingredient');
        $this->addSql('CREATE TABLE ingredient (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(100) NOT NULL)');
        $this->addSql('INSERT INTO ingredient (id, code) SELECT id, code FROM __temp__ingredient');
        $this->addSql('DROP TABLE __temp__ingredient');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6BAF787077153098 ON ingredient (code)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__ingredient_translation AS SELECT id, locale, name, ingredient_id FROM ingredient_translation');
        $this->addSql('DROP TABLE ingredient_translation');
        $this->addSql('CREATE TABLE ingredient_translation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, locale VARCHAR(5) NOT NULL, name VARCHAR(255) NOT NULL, ingredient_id INTEGER NOT NULL, CONSTRAINT FK_C1A8BF6933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO ingredient_translation (id, locale, name, ingredient_id) SELECT id, locale, name, ingredient_id FROM __temp__ingredient_translation');
        $this->addSql('DROP TABLE __temp__ingredient_translation');
        $this->addSql('CREATE INDEX IDX_C1A8BF6933FE08C ON ingredient_translation (ingredient_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__product AS SELECT id, image, base_price, calories, spicy_level, vegetarian, vegan, gluten_free, active, position, category_id FROM product');
        $this->addSql('DROP TABLE product');
        $this->addSql('CREATE TABLE product (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, image VARCHAR(255) DEFAULT NULL, base_price INTEGER NOT NULL, calories INTEGER DEFAULT NULL, spicy_level INTEGER DEFAULT NULL, vegetarian BOOLEAN NOT NULL, vegan BOOLEAN NOT NULL, gluten_free BOOLEAN NOT NULL, active BOOLEAN NOT NULL, position INTEGER NOT NULL, category_id INTEGER NOT NULL, CONSTRAINT FK_D34A04AD12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO product (id, image, base_price, calories, spicy_level, vegetarian, vegan, gluten_free, active, position, category_id) SELECT id, image, base_price, calories, spicy_level, vegetarian, vegan, gluten_free, active, position, category_id FROM __temp__product');
        $this->addSql('DROP TABLE __temp__product');
        $this->addSql('CREATE INDEX IDX_D34A04AD12469DE2 ON product (category_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__product_translation AS SELECT id, locale, name, description, product_id FROM product_translation');
        $this->addSql('DROP TABLE product_translation');
        $this->addSql('CREATE TABLE product_translation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, locale VARCHAR(5) NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, product_id INTEGER NOT NULL, CONSTRAINT FK_1846DB704584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO product_translation (id, locale, name, description, product_id) SELECT id, locale, name, description, product_id FROM __temp__product_translation');
        $this->addSql('DROP TABLE __temp__product_translation');
        $this->addSql('CREATE INDEX IDX_1846DB704584665A ON product_translation (product_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__restaurant AS SELECT id, name, slug, logo, primary_color, currency, default_language, created_at FROM restaurant');
        $this->addSql('DROP TABLE restaurant');
        $this->addSql('CREATE TABLE restaurant (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, logo VARCHAR(255) DEFAULT NULL, primary_color VARCHAR(20) NOT NULL, currency VARCHAR(3) NOT NULL, default_language VARCHAR(5) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO restaurant (id, name, slug, logo, primary_color, currency, default_language, created_at) SELECT id, name, slug, logo, primary_color, currency, default_language, created_at FROM __temp__restaurant');
        $this->addSql('DROP TABLE __temp__restaurant');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EB95123F989D9B62 ON restaurant (slug)');
    }
}
