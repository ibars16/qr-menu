<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\CategoryTranslation;
use App\Entity\MenuImportBatch;
use App\Entity\Product;
use App\Entity\ProductTranslation;
use App\Entity\Restaurant;
use App\Enum\MenuImportBatchStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * "⚠ Esta carta parece estar en {idioma}…" on Carta, for one import.
 *
 * The importer stores every text it reads under the menu's main language
 * (MenuImportAssembler), whatever language the photo was in. check(), right
 * after assembly, asks the AI the language of each of up to SAMPLE_SIZE of
 * the import's names (detectEach(): "unsure" for "Pizza margarita"); the
 * notice is set only when at least MIN_SURE answers are sure and at least
 * MIN_SHARE of them are one same language other than the main one. Any
 * doubt or failure: no notice.
 *
 * "Traducir" (step(), one AI call per request, like the main-language
 * change): only this import's dishes and the categories it created, still
 * pending review (needsReview — published or edited by hand is the owner's,
 * never touched), whose main-language text is still the one read from the
 * photo ('human'). The text read is kept as the owner's in its real
 * language; the main-language one becomes its AI translation
 * (MenuTextTranslator). Prices, allergens, photos and ingredients: never.
 * A failed batch writes nothing and is retried; what's done stays done.
 * "Dejarla así" (dismiss()): nothing changes, the notice is gone.
 */
final class MenuImportLanguageNotice
{
    public const MIN_SURE  = 5;
    public const MIN_SHARE = 0.7;
    private const SAMPLE_SIZE = 30;
    /** Same batch sizes as MenuLanguageChanger. */
    private const PRODUCT_BATCH = 25;
    private const NAME_BATCH = 60;

    public function __construct(
        private readonly EntityManagerInterface        $em,
        private readonly MenuLanguageDetectorInterface $detector,
        private readonly MenuTextTranslator            $textTranslator,
        private readonly LoggerInterface               $logger,
    ) {}

    /** After assembly: sets the notice when the import is clearly in another language. Flushes when it does. */
    public function check(MenuImportBatch $batch): void
    {
        if ($batch->getStatus() !== MenuImportBatchStatus::READY_FOR_REVIEW || $batch->getLanguageNotice() !== null) {
            return;
        }
        $main = $batch->getRestaurant()->getDefaultLanguage();

        $texts = [];
        foreach ([...$this->pendingProducts($batch, null), ...$this->pendingCategories($batch, null)] as $entity) {
            $name = trim($entity->getTranslation($main)->getName());
            if (count($texts) < self::SAMPLE_SIZE && !in_array($name, $texts, true)) {
                $texts[($entity instanceof Product ? 'p' : 'c') . $entity->getId()] = $name;
            }
        }
        if (count($texts) < self::MIN_SURE) {
            return;
        }

        try {
            $answers = $this->detector->detectEach($texts);
        } catch (\Throwable $e) {
            $this->logger->warning('Import language check failed.', ['batchId' => $batch->getId(), 'exception' => $e]);
            $answers = null;
        }
        if ($answers === null) {
            return;
        }

        $language = self::verdict($answers);
        $this->logger->info('Import language check.', [
            'batchId'  => $batch->getId(),
            'main'     => $main,
            'texts'    => count($texts),
            'detected' => $language ?? 'unsure',
        ]);
        if ($language === null || $language === $main) {
            return;
        }

        $batch->setDetectedLanguage($language);
        $batch->setLanguageNotice(MenuImportBatch::NOTICE_PENDING);
        $this->em->flush();
    }

    /**
     * The language nearly all the sure answers share: at least MIN_SURE sure
     * ones (null = "unsure" doesn't count), at least MIN_SHARE of them the
     * same; else null.
     *
     * @param array<string, ?string> $answers
     */
    public static function verdict(array $answers): ?string
    {
        $counts = array_count_values(array_filter($answers, static fn (?string $l) => $l !== null));
        $sure = array_sum($counts);
        if ($sure < self::MIN_SURE) {
            return null;
        }
        arsort($counts);
        $top = array_key_first($counts);

        return $counts[$top] / $sure >= self::MIN_SHARE ? (string) $top : null;
    }

    /**
     * The notices Carta shows: imports with one pending or half done, still
     * in another language than the main one, with something left to
     * translate. None while the main language is being changed.
     *
     * @return list<array{batch: MenuImportBatch, remaining: int, productIds: list<int>}>
     *         productIds: the dishes "Traducir" would translate (marked on Carta)
     */
    public function noticesFor(Restaurant $restaurant): array
    {
        if ($restaurant->getLanguageChangeTo() !== null) {
            return [];
        }
        $batches = $this->em->getRepository(MenuImportBatch::class)->findBy(
            ['restaurant' => $restaurant, 'languageNotice' => [MenuImportBatch::NOTICE_PENDING, MenuImportBatch::NOTICE_TRANSLATING]],
            ['id' => 'ASC'],
        );

        $notices = [];
        foreach ($batches as $batch) {
            if ($batch->getDetectedLanguage() === null || $batch->getDetectedLanguage() === $restaurant->getDefaultLanguage()) {
                continue;
            }
            $remaining = $this->remaining($batch);
            if ($remaining > 0) {
                $notices[] = [
                    'batch'      => $batch,
                    'remaining'  => $remaining,
                    'productIds' => array_map(static fn (Product $p) => $p->getId(), $this->pendingProducts($batch, $batch->getDetectedLanguage())),
                ];
            }
        }

        return $notices;
    }

    /** Dishes and categories of the import still to translate. */
    public function remaining(MenuImportBatch $batch): int
    {
        $language = $batch->getDetectedLanguage();

        return $language === null ? 0 : count($this->pendingProducts($batch, $language)) + count($this->pendingCategories($batch, $language));
    }

    public function isOpen(MenuImportBatch $batch): bool
    {
        return in_array($batch->getLanguageNotice(), [MenuImportBatch::NOTICE_PENDING, MenuImportBatch::NOTICE_TRANSLATING], true);
    }

    /**
     * One batch of "Traducir": dishes first, then categories. Flushes.
     *
     * @return array{done: bool, remaining: int, error: bool}
     *         error: the AI gave nothing usable this time (retry)
     */
    public function step(MenuImportBatch $batch): array
    {
        $restaurant = $batch->getRestaurant();
        $from = $batch->getDetectedLanguage();
        $main = $restaurant->getDefaultLanguage();
        if (!$this->isOpen($batch) || $from === null || $from === $main) {
            return ['done' => true, 'remaining' => 0, 'error' => false];
        }
        if ($restaurant->getLanguageChangeTo() !== null) {
            return ['done' => false, 'remaining' => $this->remaining($batch), 'error' => true];
        }
        $batch->setLanguageNotice(MenuImportBatch::NOTICE_TRANSLATING);

        $products   = $this->pendingProducts($batch, $from);
        $categories = $this->pendingCategories($batch, $from);
        $stored = match (true) {
            $products !== []   => $this->translateProducts(array_slice($products, 0, self::PRODUCT_BATCH), $from, $main),
            $categories !== [] => $this->translateCategories(array_slice($categories, 0, self::NAME_BATCH), $from, $main),
            default            => null,
        };

        if ($stored > 0) {
            $restaurant->bumpMenuContentVersion();
        }
        $remaining = $this->remaining($batch);
        if ($remaining === 0) {
            $batch->setLanguageNotice(MenuImportBatch::NOTICE_TRANSLATED);
        }
        $this->em->flush();

        return ['done' => $remaining === 0, 'remaining' => $remaining, 'error' => $stored === 0];
    }

    /** "Dejarla así". Flushes. */
    public function dismiss(MenuImportBatch $batch): void
    {
        if ($this->isOpen($batch)) {
            $batch->setLanguageNotice(MenuImportBatch::NOTICE_DISMISSED);
            $this->em->flush();
        }
    }

    // ── What is translated ──

    /**
     * The import's dishes still pending review whose main-language text is
     * still the one read from the photo — and, with $from, none written by
     * the owner in $from already.
     *
     * @return list<Product>
     */
    private function pendingProducts(MenuImportBatch $batch, ?string $from): array
    {
        $products = $this->em->getRepository(Product::class)->findBy(['importBatch' => $batch, 'needsReview' => true], ['id' => 'ASC']);

        return array_values(array_filter($products, fn (Product $p) => $this->isPending($p, $batch, $from)));
    }

    /** @return list<Category> the categories the import created, same conditions */
    private function pendingCategories(MenuImportBatch $batch, ?string $from): array
    {
        $categories = $this->em->getRepository(Category::class)->findBy(['importBatch' => $batch, 'needsReview' => true], ['id' => 'ASC']);

        return array_values(array_filter($categories, fn (Category $c) => $this->isPending($c, $batch, $from)));
    }

    private function isPending(Product|Category $entity, MenuImportBatch $batch, ?string $from): bool
    {
        $mainRow = $entity->getTranslation($batch->getRestaurant()->getDefaultLanguage());
        if ($mainRow === null || $mainRow->getSource() !== ProductTranslation::SOURCE_HUMAN || trim($mainRow->getName()) === '') {
            return false;
        }
        $fromRow = $from !== null ? $entity->getTranslation($from) : null;

        return $fromRow === null || $fromRow->getSource() === ProductTranslation::SOURCE_AI;
    }

    /**
     * The text read becomes the source, in its real language — stored only
     * for the dishes the AI translated, so a failed batch changes nothing.
     *
     * @param list<Product> $products
     */
    private function translateProducts(array $products, string $from, string $main): int
    {
        $sources = [];
        foreach ($products as $product) {
            $read = $product->getTranslation($main);
            $source = new ProductTranslation();
            $source->setProduct($product);
            $source->setLocale($from);
            $source->setName($read->getName());
            $source->setDescription($read->getDescription());
            $sources[$product->getId()] = $source;
        }

        $written = $this->textTranslator->translateProducts($sources, $main);
        foreach ($written as $product) {
            $source = $sources[$product->getId()];
            $row = $product->getTranslation($from);
            if ($row === null) {
                $product->addTranslation($source);
                $this->em->persist($source);
            } else {
                $row->setName($source->getName());
                $row->setDescription($source->getDescription());
                $row->setSource(ProductTranslation::SOURCE_HUMAN);
            }
        }

        return count($written);
    }

    /** @param list<Category> $categories */
    private function translateCategories(array $categories, string $from, string $main): int
    {
        $sources = [];
        foreach ($categories as $category) {
            $source = new CategoryTranslation();
            $source->setCategory($category);
            $source->setLocale($from);
            $source->setName($category->getTranslation($main)->getName());
            $sources[$category->getId()] = $source;
        }

        $written = $this->textTranslator->translateCategories($sources, $main);
        foreach ($written as $category) {
            $source = $sources[$category->getId()];
            $row = $category->getTranslation($from);
            if ($row === null) {
                $category->addTranslation($source);
                $this->em->persist($source);
            } else {
                $row->setName($source->getName());
                $row->setSource(CategoryTranslation::SOURCE_HUMAN);
            }
        }

        return count($written);
    }
}
