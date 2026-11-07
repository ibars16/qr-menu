<?php

namespace App\Service;

use App\Entity\OwnerTextTranslation;
use App\Entity\Restaurant;
use App\Repository\OwnerTextTranslationRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Deletes the restaurant's OwnerTextTranslation rows nothing refers to any
 * more — a translation of a tagline, price label, set-menu description or
 * section label the owner has since rewritten or deleted, or of an
 * ingredient no dish uses now. Rows are keyed by the source text's hash, so
 * an edit never touches the old row: without this they'd pile up forever.
 *
 * A row is live when its hash is one of:
 * - hash(default language, text) for every owner text the restaurant holds
 *   now, in any state (an inactive dish's labels too: a main-language
 *   change converts every one, and a 'human' row there is the owner's
 *   original it brings back on the way back);
 * - hash(locale, name) for every name, in every language, of every
 *   ingredient (private or global) on any of its dishes — a superset of
 *   the sources Product::ingredientTranslationSource() picks, on purpose.
 * The whole restaurant is looked at, not the field just edited: the same
 * text in two places ("Copa" on two dishes) is one row, kept while either
 * is left. A 'human' row whose text the owner rewrote goes too — that
 * original belonged to the old text.
 *
 * Nothing is deleted while a main-language change is half done
 * (languageChangeTo set): it stores rows for the new language before the
 * texts and the default language switch, so they'd look unused — it
 * prunes itself once it's finished (MenuLanguageChanger::finish()).
 *
 * Called after the flush of every admin save that can leave a text behind.
 * Reads the database (not the entities), so it sees what was just saved.
 * Never calls the AI; nothing rendered changes (an unused row is never
 * read), so no menu content version bump. A failure is logged and the
 * save it follows still succeeds: the rows are just left for the next one.
 */
final class OwnerTextTranslationPruner
{
    public function __construct(
        private readonly Connection                     $connection,
        private readonly OwnerTextTranslationRepository $repository,
        private readonly LoggerInterface                $logger,
    ) {}

    /** @return int rows deleted */
    public function prune(Restaurant $restaurant): int
    {
        if ($restaurant->getId() === null) {
            return 0;
        }

        try {
            $hashes = $this->liveHashes($restaurant->getId());
            if ($hashes === null) {
                return 0;
            }

            return $this->repository->deleteUnreferenced($restaurant, $hashes);
        } catch (\Throwable $e) {
            $this->logger->warning('Owner text translation prune failed; unused rows left for the next save.', [
                'restaurantId' => $restaurant->getId(),
                'exception'    => $e,
            ]);

            return 0;
        }
    }

    /**
     * Every source hash the restaurant still refers to, or null while a
     * main-language change is half done (nothing may be pruned then).
     *
     * @return string[]|null
     */
    public function liveHashes(int $restaurantId): ?array
    {
        $restaurant = $this->connection->fetchAssociative(
            'SELECT default_language, language_change_to, tagline FROM restaurant WHERE id = ?',
            [$restaurantId],
        );
        if ($restaurant === false || $restaurant['language_change_to'] !== null) {
            return null;
        }
        $default = $restaurant['default_language'];

        $texts = [$restaurant['tagline']];
        foreach ([
            'SELECT menu_description FROM category WHERE restaurant_id = ?',
            'SELECT s.label FROM menu_section s JOIN category c ON c.id = s.category_id WHERE c.restaurant_id = ?',
            'SELECT p.base_price_label FROM product p JOIN category c ON c.id = p.category_id WHERE c.restaurant_id = ?',
            'SELECT v.label FROM product_price_variant v JOIN product p ON p.id = v.product_id JOIN category c ON c.id = p.category_id WHERE c.restaurant_id = ?',
        ] as $sql) {
            array_push($texts, ...$this->connection->fetchFirstColumn($sql, [$restaurantId]));
        }

        $live = [];
        foreach ($texts as $text) {
            $text = trim((string) $text);
            if ($text !== '') {
                $live[OwnerTextTranslation::hash($default, $text)] = true;
            }
        }

        foreach ([
            'SELECT DISTINCT t.locale, t.name FROM ingredient_translation t
               JOIN product_ingredient pi ON pi.ingredient_id = t.ingredient_id
               JOIN product p ON p.id = pi.product_id JOIN category c ON c.id = p.category_id
              WHERE c.restaurant_id = ?',
            'SELECT DISTINCT t.locale, t.name FROM global_ingredient_translation t
               JOIN product_global_ingredient pg ON pg.global_ingredient_id = t.global_ingredient_id
               JOIN product p ON p.id = pg.product_id JOIN category c ON c.id = p.category_id
              WHERE c.restaurant_id = ?',
        ] as $sql) {
            foreach ($this->connection->fetchAllNumeric($sql, [$restaurantId]) as [$locale, $name]) {
                $name = trim((string) $name);
                if ($name !== '') {
                    $live[OwnerTextTranslation::hash($locale, $name)] = true;
                }
            }
        }

        return array_keys($live);
    }
}
