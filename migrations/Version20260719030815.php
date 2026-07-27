<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migrates the fixed-price-menu section model from (a) shared-position
 * scale (Product and MenuSection interleaved by a single Category-wide
 * position number — see the now-deleted Category::getAdminRows()) to (b)
 * an explicit FK: Product::$menuSection. The Menús admin screen needs
 * dishes to belong to a section, not just to sort near one.
 *
 * Hand-trimmed: doctrine:migrations:diff also picked up unrelated
 * pre-existing schema drift (stale trigram indexes, DEFAULT-clause
 * mismatches on unrelated columns) — only the new nullable
 * product.menu_section_id column, its FK (ON DELETE CASCADE — deleting a
 * section deletes its dishes, mirroring how deleting a category already
 * cascades to its products), and its index are kept here.
 *
 * Also runs a one-time data conversion (see migrateExistingMenuCategories())
 * for any menu-category that already existed under the old (a) model:
 * every product gets assigned to whichever section preceded it in the old
 * merged position order, with a synthesized leading "Platos" section for
 * any product that had no section before it at all (the "menu dishes
 * created before sections existed" case) — matching the mandatory-section
 * rule the new admin screen enforces going forward. This part is one-way;
 * down() only reverses the schema.
 */
final class Version20260719030815 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product.menu_section_id (b-shape) and convert existing (a)-shape menu categories';
    }

    public function up(Schema $schema): void
    {
        // Run immediately via the connection rather than addSql(): addSql()
        // only queues statements for the migration executor to run AFTER
        // up() returns, but migrateExistingMenuCategories() below needs the
        // new column to already exist while up() is still running.
        $this->connection->executeStatement('ALTER TABLE product ADD menu_section_id INT DEFAULT NULL');
        $this->connection->executeStatement('CREATE INDEX IDX_D34A04ADF98E57A8 ON product (menu_section_id)');
        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE
              product
            ADD
              CONSTRAINT FK_D34A04ADF98E57A8 FOREIGN KEY (menu_section_id) REFERENCES menu_section (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);

        $this->migrateExistingMenuCategories();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP CONSTRAINT FK_D34A04ADF98E57A8');
        $this->addSql('DROP INDEX IDX_D34A04ADF98E57A8');
        $this->addSql('ALTER TABLE product DROP menu_section_id');
    }

    private function migrateExistingMenuCategories(): void
    {
        $categories = $this->connection->fetchAllAssociative('SELECT id FROM category WHERE menu_price IS NOT NULL');

        foreach ($categories as $categoryRow) {
            $this->migrateCategory((int) $categoryRow['id']);
        }
    }

    private function migrateCategory(int $categoryId): void
    {
        $products = $this->connection->fetchAllAssociative(
            'SELECT id, position FROM product WHERE category_id = ? ORDER BY position ASC, id ASC',
            [$categoryId]
        );
        $sections = $this->connection->fetchAllAssociative(
            'SELECT id, position FROM menu_section WHERE category_id = ? ORDER BY position ASC, id ASC',
            [$categoryId]
        );

        if ($products === [] && $sections === []) {
            return;
        }

        // Replay the old merge-by-position order (exactly what the deleted
        // Category::getAdminRows() computed at read time) to decide, for
        // each product, which section precedes it.
        $rows = [];
        foreach ($products as $p) {
            $rows[] = ['type' => 'product', 'position' => (int) $p['position'], 'id' => (int) $p['id']];
        }
        foreach ($sections as $s) {
            $rows[] = ['type' => 'section', 'position' => (int) $s['position'], 'id' => (int) $s['id']];
        }
        usort($rows, static fn(array $a, array $b) => $a['position'] <=> $b['position']);

        $sectionOrder = [];       // section ids, final relative order
        $productsBySection = [];  // sectionId => [productId, ...] in order
        $currentSectionId = null;
        $defaultSectionId = null;

        foreach ($rows as $row) {
            if ($row['type'] === 'section') {
                $currentSectionId = $row['id'];
                if (!in_array($currentSectionId, $sectionOrder, true)) {
                    $sectionOrder[] = $currentSectionId;
                }
                continue;
            }

            if ($currentSectionId === null) {
                if ($defaultSectionId === null) {
                    $defaultSectionId = (int) $this->connection->fetchOne(
                        'INSERT INTO menu_section (category_id, label, position) VALUES (?, ?, 0) RETURNING id',
                        [$categoryId, 'Platos']
                    );
                    array_unshift($sectionOrder, $defaultSectionId);
                }
                $currentSectionId = $defaultSectionId;
            }

            $productsBySection[$currentSectionId][] = (int) $row['id'];
        }

        // Renumber sections to a clean 0..n-1 scale (their old position
        // values were on the shared product+section scale and are now
        // meaningless relative to each other), and each section's own
        // products to their own clean 0..n-1 scale.
        foreach ($sectionOrder as $index => $sectionId) {
            $this->connection->executeStatement('UPDATE menu_section SET position = ? WHERE id = ?', [$index, $sectionId]);

            foreach ($productsBySection[$sectionId] ?? [] as $productIndex => $productId) {
                $this->connection->executeStatement(
                    'UPDATE product SET menu_section_id = ?, position = ? WHERE id = ?',
                    [$sectionId, $productIndex, $productId]
                );
            }
        }
    }
}
