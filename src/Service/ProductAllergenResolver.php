<?php

namespace App\Service;

use App\Entity\Allergen;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Enum\AllergenPresence;
use App\Repository\AllergenRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Computes each product's *effective* allergen list: the union of allergens
 * carried by its ingredients (both the Global Library and the restaurant's
 * own private ones), with any product-level ProductAllergenOverride layered
 * on top — override always wins for that specific allergen. FREE_FROM only
 * ever comes from an override; it is never computed.
 *
 * Batched by design (three queries total, independent of how many products
 * are being resolved) so this stays cheap on the public menu — the whole
 * page's allergen data, not one query per product. See the architecture
 * proposal this implements for why the resolution happens at read time
 * rather than being denormalized onto Product itself: ingredient data (the
 * source of truth) changes far less often than it's read, and a computed
 * value can never silently drift from what an admin actually tagged.
 *
 * @phpstan-type AllergenEntry array{allergen: Allergen, presence: AllergenPresence, source: 'computed'|'override', note: ?string}
 */
final class ProductAllergenResolver
{
    /**
     * Same safety-net TTL as ProductRepository::CACHE_TTL — the backstop
     * for GlobalIngredientAllergen writes (GlobalIngredientAllergenSeeder,
     * the classify:run allergen-classification task), which are global and
     * cross-restaurant with no single Restaurant::$menuContentVersion to
     * bump: a global-ingredient-allergen edit can take up to this long to
     * reach an affected restaurant's cached menu, instead of being instant.
     * Accepted trade-off — see the caching plan this implements.
     */
    private const CACHE_TTL = 21600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AllergenRepository $allergenRepository,
        private readonly CacheItemPoolInterface $cache,
    ) {}

    /**
     * $cacheKeyPrefix is opt-in — only ever passed from the public menu
     * path, so admin screens (which call this directly) keep getting live
     * data by default. Only the three raw ingredient/override SQL queries
     * are cached (plain scalar rows — safe to serialize); the Allergen
     * entities themselves and the union/override computation below always
     * run fresh from AllergenRepository::findAllOrdered(), which is cheap
     * and not restaurant-scoped, so caching it would add risk (Doctrine
     * entities don't survive being unserialized from a generic cache pool
     * across requests) for no real gain.
     *
     * @param  Product[] $products
     * @return array<int, list<array{allergen: Allergen, presence: AllergenPresence, source: 'computed'|'override', note: ?string}>> keyed by product id
     */
    public function resolveForProducts(array $products, ?string $cacheKeyPrefix = null): array
    {
        $productIds = array_values(array_filter(array_map(
            static fn (Product $p) => $p->getId(),
            $products
        )));

        if (empty($productIds)) {
            return [];
        }

        [$globalRows, $privateRows, $overrideRows] = $this->fetchAllergenRows($productIds, $cacheKeyPrefix);

        // Union across both ingredient sources, upgrading may_contain -> contains
        // if any source says contains — but never downgrading the reverse.
        $computed = [];
        foreach ([...$globalRows, ...$privateRows] as $row) {
            $pid = (int) $row['product_id'];
            $aid = (int) $row['allergen_id'];
            if (($computed[$pid][$aid] ?? null) === AllergenPresence::CONTAINS->value) {
                continue;
            }
            $computed[$pid][$aid] = $row['presence'];
        }

        $overridesByProduct = [];
        foreach ($overrideRows as $row) {
            $overridesByProduct[(int) $row['product_id']][(int) $row['allergen_id']] = [
                'presence' => $row['presence'],
                'note' => $row['note'],
            ];
        }

        $allergensById = [];
        foreach ($this->allergenRepository->findAllOrdered() as $allergen) {
            $allergensById[$allergen->getId()] = $allergen;
        }

        $result = [];
        foreach ($productIds as $pid) {
            $entries = [];
            $overridden = $overridesByProduct[$pid] ?? [];

            foreach ($overridden as $aid => $override) {
                $allergen = $allergensById[$aid] ?? null;
                if (!$allergen) {
                    continue;
                }
                $entries[] = [
                    'allergen' => $allergen,
                    'presence' => AllergenPresence::from($override['presence']),
                    'source' => 'override',
                    'note' => $override['note'],
                ];
            }

            foreach ($computed[$pid] ?? [] as $aid => $presence) {
                if (array_key_exists($aid, $overridden)) {
                    continue; // an override always wins over the computed value for that allergen
                }
                $allergen = $allergensById[$aid] ?? null;
                if (!$allergen) {
                    continue;
                }
                $entries[] = [
                    'allergen' => $allergen,
                    'presence' => AllergenPresence::from($presence),
                    'source' => 'computed',
                    'note' => null,
                ];
            }

            usort($entries, static fn (array $a, array $b) => $a['allergen']->getPosition() <=> $b['allergen']->getPosition());

            $result[$pid] = $entries;
        }

        return $result;
    }

    /** @return list<array{allergen: Allergen, presence: AllergenPresence, source: 'computed'|'override', note: ?string}> */
    public function resolveForProduct(Product $product): array
    {
        return $this->resolveForProducts([$product])[$product->getId()] ?? [];
    }

    /** @return array<int, list<array{allergen: Allergen, presence: AllergenPresence, source: 'computed'|'override', note: ?string}>> */
    public function resolveForRestaurant(Restaurant $restaurant, ?string $cacheKeyPrefix = null): array
    {
        $products = [];
        foreach ($restaurant->getCategories() as $category) {
            foreach ($category->getProducts() as $product) {
                $products[] = $product;
            }
        }

        return $this->resolveForProducts($products, $cacheKeyPrefix);
    }

    /**
     * @param int[] $productIds
     * @return array{0: list<array{product_id: string, allergen_id: string, presence: string}>, 1: list<array{product_id: string, allergen_id: string, presence: string}>, 2: list<array{product_id: string, allergen_id: string, presence: string, note: ?string}>}
     */
    private function fetchAllergenRows(array $productIds, ?string $cacheKeyPrefix): array
    {
        $cacheKey = $cacheKeyPrefix !== null
            ? 'allergen_rows_' . $cacheKeyPrefix
            : null;

        $item = $cacheKey !== null ? $this->cache->getItem($cacheKey) : null;
        if ($item !== null && $item->isHit()) {
            return $item->get();
        }

        $conn = $this->em->getConnection();

        $globalRows = $conn->executeQuery(
            'SELECT pgi.product_id, gia.allergen_id, gia.presence
             FROM product_global_ingredient pgi
             INNER JOIN global_ingredient_allergen gia ON gia.global_ingredient_id = pgi.global_ingredient_id
             WHERE pgi.product_id IN (?)',
            [$productIds],
            [ArrayParameterType::INTEGER]
        )->fetchAllAssociative();

        $privateRows = $conn->executeQuery(
            'SELECT pi.product_id, ia.allergen_id, ia.presence
             FROM product_ingredient pi
             INNER JOIN ingredient_allergen ia ON ia.ingredient_id = pi.ingredient_id
             WHERE pi.product_id IN (?)',
            [$productIds],
            [ArrayParameterType::INTEGER]
        )->fetchAllAssociative();

        $overrideRows = $conn->executeQuery(
            'SELECT product_id, allergen_id, presence, note
             FROM product_allergen_override
             WHERE product_id IN (?)',
            [$productIds],
            [ArrayParameterType::INTEGER]
        )->fetchAllAssociative();

        $rows = [$globalRows, $privateRows, $overrideRows];

        if ($item !== null) {
            $item->set($rows);
            $item->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);
        }

        return $rows;
    }
}
