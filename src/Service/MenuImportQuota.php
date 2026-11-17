<?php

namespace App\Service;

use App\Entity\Restaurant;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "Importar carta" a day per restaurant (since midnight, server time),
 * counted from its own menu_import_batch / menu_import_page rows — every
 * upload counts, whatever came of it, since each one already went to the
 * AI:
 *
 *  - MAX_UPLOADS uploads and MAX_PAGES pages (two full 30-page menus;
 *    real restaurants have done at most 4 uploads in a day);
 *  - while the owner hasn't confirmed the email yet
 *    (Restaurant::isAwaitingEmailConfirmation()): MAX_UPLOADS_UNCONFIRMED
 *    uploads — enough to import the menu, not to farm the AI from fake
 *    sign-ups.
 *
 * Past it, MenuImportController shows the error on the import screen
 * itself (no new screen). Off when app.abuse_limits is (dev).
 */
final class MenuImportQuota
{
    public const MAX_UPLOADS = 5;
    public const MAX_PAGES = 60;
    public const MAX_UPLOADS_UNCONFIRMED = 2;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%app.abuse_limits%')] private readonly bool $enabled,
    ) {
    }

    /**
     * Why one more upload (of $pages pages) isn't allowed today: the
     * admin_menu_import message key and its parameters, or null if it is.
     *
     * @return array{0: string, 1: array<string, int>}|null
     */
    public function refusal(Restaurant $restaurant, ?int $pages = null): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        [$uploads, $pagesToday] = $this->usedToday($restaurant);

        if ($restaurant->isAwaitingEmailConfirmation() && $uploads >= self::MAX_UPLOADS_UNCONFIRMED) {
            return ['upload.error.daily_unconfirmed', ['%max%' => self::MAX_UPLOADS_UNCONFIRMED]];
        }
        if ($uploads >= self::MAX_UPLOADS || $pagesToday >= self::MAX_PAGES) {
            return ['upload.error.daily_limit', []];
        }
        if ($pages !== null && $pagesToday + $pages > self::MAX_PAGES) {
            return ['upload.error.daily_pages', ['%left%' => self::MAX_PAGES - $pagesToday]];
        }

        return null;
    }

    /** @return array{0: int, 1: int} uploads and pages since midnight */
    private function usedToday(Restaurant $restaurant): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(DISTINCT b.id) AS uploads, COUNT(p.id) AS pages
               FROM menu_import_batch b
               LEFT JOIN menu_import_page p ON p.batch_id = b.id
              WHERE b.restaurant_id = :restaurant AND b.created_at >= :since',
            ['restaurant' => $restaurant->getId(), 'since' => (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s')],
        );

        return [(int) ($row['uploads'] ?? 0), (int) ($row['pages'] ?? 0)];
    }
}
