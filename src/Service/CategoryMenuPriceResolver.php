<?php

namespace App\Service;

/**
 * Validates and normalizes the "Precio del menú"/"Descripción" fields sent
 * by the Menús screen's create/edit forms (see Admin\MenusController). A
 * menu category always has a price — there is no "menu mode off" case here
 * any more (that toggle lived on the Categorías screen and has been removed
 * entirely; see MenuSection's docblock for the current split).
 */
final class CategoryMenuPriceResolver
{
    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, menuPrice: ?int, menuDescription: ?string}
     */
    public function resolve(array $data): array
    {
        $rawPrice = $data['menuPrice'] ?? null;
        if (!is_numeric($rawPrice) || (float) $rawPrice <= 0) {
            return ['ok' => false, 'menuPrice' => null, 'menuDescription' => null];
        }

        $description = trim((string) ($data['menuDescription'] ?? ''));

        return [
            'ok'              => true,
            'menuPrice'       => (int) round((float) $rawPrice * 100),
            'menuDescription' => $description !== '' ? $description : null,
        ];
    }
}
