<?php

namespace App\Command;

use App\Entity\MenuImportBatch;
use App\Entity\MenuImportPage;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Enum\MenuImportPageStatus;
use App\Service\AI\AIProviderException;
use App\Service\AI\AIProviderFactory;
use App\Service\MenuImportAssembler;
use App\Service\MenuVisionPromptBuilder;
use App\Service\ProductAllergenResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mime\MimeTypes;

/**
 * Manual end-to-end check of the real vision model against a golden
 * (human-verified) expected result — see tests/fixtures/elian_menu.*. Runs
 * a REAL vision provider call (cost + non-determinism, hence never part of
 * the default test suite — see phpunit.dist.xml's vision-eval group
 * exclusion) and the real MenuImportAssembler, inside a transaction that is
 * always rolled back: nothing this command does is ever persisted.
 *
 * Run this whenever MenuVisionPromptBuilder changes, to see the actual
 * effect on a real menu photo rather than trusting the prompt-editing intent
 * alone. A clean run here doesn't replace ElianMenuFixtureTest (which
 * exercises the assembler deterministically, no AI call, every CI run) — it
 * only tells you the *model* still transcribes this specific menu the way
 * the golden file says it should.
 */
#[AsCommand(
    name: 'app:menu:eval',
    description: 'Parses a menu photo with the real vision model and diffs the result against a golden expected JSON — manual only, costs a real API call',
)]
final class MenuEvalCommand extends Command
{
    public function __construct(
        private readonly AIProviderFactory $providerFactory,
        private readonly MenuVisionPromptBuilder $promptBuilder,
        private readonly MenuImportAssembler $assembler,
        private readonly ProductAllergenResolver $allergenResolver,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('images', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Path(s) to the menu photo page(s), in page order', ['tests/fixtures/elian_menu.png'])
            ->addOption('golden', null, InputOption::VALUE_REQUIRED, 'Path to the golden expected JSON', 'tests/fixtures/elian_menu.expected.json')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Restaurant content language (ISO 639-1) to pass to the prompt', 'es');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $imagePaths = array_map($this->resolvePath(...), $input->getArgument('images'));
        $goldenPath = $this->resolvePath($input->getOption('golden'));
        $language = $input->getOption('language');

        foreach ($imagePaths as $imagePath) {
            if (!is_file($imagePath)) {
                $io->error("Image not found: {$imagePath}");
                return Command::FAILURE;
            }
        }
        if (!is_file($goldenPath)) {
            $io->error("Golden file not found: {$goldenPath}");
            return Command::FAILURE;
        }

        $golden = json_decode(file_get_contents($goldenPath), true, flags: JSON_THROW_ON_ERROR);

        $io->section(sprintf('Calling vision model (%d page(s))', count($imagePaths)));
        $pages = [];
        try {
            foreach ($imagePaths as $imagePath) {
                $io->writeln('  ' . basename($imagePath));
                $pages[] = $this->callVision($imagePath, $language);
            }
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $products = $this->assembleInSandbox($pages, $language);
            $actual = $this->buildActual($products);
        } finally {
            // Always rolled back — this command never writes anything real,
            // regardless of success or failure above.
            $connection->rollBack();
        }

        $io->section('Diff vs. golden');
        $diff = $this->diff($golden['categories'], $actual);
        $problems = $diff['problems'];
        $warnings = $diff['warnings'];

        foreach ($warnings as $warning) {
            $io->writeln('  <fg=yellow>⚠</> ' . $warning);
        }

        if (empty($problems)) {
            $io->success('Vision output matches the golden file.');
            return Command::SUCCESS;
        }

        foreach ($problems as $problem) {
            $io->writeln('  <fg=red>✗</> ' . $problem);
        }
        $io->error(sprintf('%d discrepancy/discrepancies vs. golden file.', count($problems)));

        return Command::FAILURE;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->projectDir . '/' . $path;
    }

    /** @return array<string, mixed> */
    private function callVision(string $imagePath, string $language): array
    {
        $imageBytes = file_get_contents($imagePath);
        $mimeType = (new MimeTypes())->guessMimeType($imagePath) ?? 'image/jpeg';
        $instructions = $this->promptBuilder->build($language);

        $lastError = null;
        foreach ($this->providerFactory->getAvailableVisionProviders() as $provider) {
            try {
                return $provider->analyzeMenuPage($imageBytes, $mimeType, $instructions)->data;
            } catch (AIProviderException $e) {
                $lastError = $e;
                continue;
            }
        }

        throw new \RuntimeException($lastError?->getMessage() ?? 'No vision-capable AI provider is currently configured.');
    }

    /**
     * @param array<int, array<string, mixed>> $pages one vision result per page, in page order
     * @return Product[]
     */
    private function assembleInSandbox(array $pages, string $language): array
    {
        $restaurant = new Restaurant();
        $restaurant->setName('__menu_eval_sandbox__');
        $restaurant->setSlug('__menu-eval-sandbox-' . bin2hex(random_bytes(4)) . '__');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage($language);
        $this->em->persist($restaurant);

        $batch = new MenuImportBatch($restaurant);
        $this->em->persist($batch);

        // One real MenuImportPage per page — a multi-page menu is one batch,
        // assembled once, exactly like the real import pipeline.
        foreach ($pages as $position => $visionData) {
            $page = new MenuImportPage($batch, "eval-{$position}.png", $position, hash('sha256', "eval-{$position}"));
            $page->setStatus(MenuImportPageStatus::EXTRACTED);
            $page->setDetectedLocale($visionData['detected_language'] ?? $language);
            $page->setExtractedData($visionData);
            $this->em->persist($page);
            $batch->addPage($page);
        }

        $this->em->flush();

        $this->assembler->assemble($batch);

        return $this->em->getRepository(Product::class)->findBy(['importBatch' => $batch]);
    }

    /**
     * @param Product[] $products
     * @return array<string, array{category: string, description: ?string, price: float, supplementPrice: ?float, ingredients: string[], allergens: string[], recommended: bool, tags: string[]}> keyed by dish name
     */
    private function buildActual(array $products): array
    {
        $allergenEntries = $this->allergenResolver->resolveForProducts($products);

        $actual = [];
        foreach ($products as $product) {
            $t = $product->getTranslation($product->getCategory()->getRestaurant()->getDefaultLanguage())
                ?? $product->getTranslations()->first();
            if (!$t) {
                continue;
            }

            $entries = [];
            foreach ($product->getIngredientLinks() as $link) {
                $entries[$link->getPosition()] = $link->getIngredient()->getTranslation($t->getLocale())?->getName();
            }
            foreach ($product->getGlobalIngredientLinks() as $link) {
                $entries[$link->getPosition()] = $link->getGlobalIngredient()->getTranslation($t->getLocale())?->getName();
            }
            ksort($entries);

            $catT = $product->getCategory()->getTranslation($t->getLocale());

            $actual[$t->getName()] = [
                'category' => $catT?->getName() ?? '',
                'description' => $t->getDescription(),
                'price' => $product->getBasePriceDecimal(),
                'supplementPrice' => $product->getSupplementPriceDecimal(),
                'ingredients' => array_values($entries),
                'allergens' => array_map(
                    static fn (array $e) => $e['allergen']->getCode(),
                    $allergenEntries[$product->getId()] ?? []
                ),
                'recommended' => false, // never true via import — see MenuImportAssembler's hard guard
                'tags' => array_map(static fn ($tag) => $tag->getCode(), $product->getTags()->toArray()),
            ];
        }

        return $actual;
    }

    /**
     * Tolerant diff: dishes matched by exact name first (not position — LLM
     * re-runs can reorder). When a golden dish has no exact match, it's
     * paired against any same-category actual dish within edit distance ≤2
     * (case-insensitive) instead of failing outright — real menu photos
     * routinely produce OCR-level name variance (e.g. "Raviales" vs.
     * "Ravioles") that isn't a genuine extraction regression; a true missing
     * or extra dish (distance >2, or no same-category candidate) still
     * fails. A near-match pair is still compared on every other field below
     * — only the name-spelling mismatch itself is downgraded to a warning.
     * Category/dish presence, counts, and supplement amounts are checked
     * exactly; ingredient lists are compared as sets (case-insensitive)
     * since which library entry a re-run happens to match can differ in
     * casing without being a real regression.
     *
     * @param array<int, array{name: string, dishes: array}> $goldenCategories
     * @param array<string, array{category: string, ...}> $actual
     * @return array{problems: string[], warnings: string[]}
     */
    private function diff(array $goldenCategories, array $actual): array
    {
        $problems = [];
        $warnings = [];
        $seenNames = [];
        $pendingMissing = []; // [['dish' => goldenDish, 'category' => name], ...]

        foreach ($goldenCategories as $goldenCategory) {
            $goldenDishNames = array_column($goldenCategory['dishes'], 'name');
            $actualInCategory = array_filter($actual, static fn ($a) => $a['category'] === $goldenCategory['name']);

            if (empty($actualInCategory) && !empty($goldenDishNames)) {
                $problems[] = "category \"{$goldenCategory['name']}\" is missing entirely from the vision output";
                continue;
            }

            if (count($actualInCategory) !== count($goldenDishNames)) {
                $problems[] = sprintf(
                    'category "%s": expected %d dishes, got %d',
                    $goldenCategory['name'],
                    count($goldenDishNames),
                    count($actualInCategory)
                );
            }

            foreach ($goldenCategory['dishes'] as $goldenDish) {
                $seenNames[] = $goldenDish['name'];
                if (!isset($actual[$goldenDish['name']])) {
                    $pendingMissing[] = ['dish' => $goldenDish, 'category' => $goldenCategory['name']];
                    continue;
                }

                $this->compareDish($goldenDish, $actual[$goldenDish['name']], $goldenDish['name'], $problems);
            }
        }

        $extraNames = array_values(array_diff(array_keys($actual), $seenNames));

        foreach ($pendingMissing as $index => $pending) {
            $goldenName = $pending['dish']['name'];
            $bestMatch = null;
            $bestDistance = null;
            foreach ($extraNames as $extraName) {
                if ($actual[$extraName]['category'] !== $pending['category']) {
                    continue; // only pair within the same category — avoids coincidental cross-category matches
                }
                $distance = $this->editDistance($goldenName, $extraName);
                if ($distance <= 2 && ($bestDistance === null || $distance < $bestDistance)) {
                    $bestMatch = $extraName;
                    $bestDistance = $distance;
                }
            }

            if ($bestMatch !== null) {
                $warnings[] = sprintf(
                    'near-match dish name (edit distance %d): golden "%s" vs. actual "%s" (category "%s")',
                    $bestDistance,
                    $goldenName,
                    $bestMatch,
                    $pending['category']
                );
                $this->compareDish($pending['dish'], $actual[$bestMatch], $goldenName, $problems);
                $extraNames = array_values(array_diff($extraNames, [$bestMatch]));
                unset($pendingMissing[$index]);
            }
        }

        foreach ($pendingMissing as $pending) {
            $problems[] = "missing dish: \"{$pending['dish']['name']}\" (category \"{$pending['category']}\")";
        }

        foreach ($extraNames as $name) {
            $problems[] = "unexpected extra dish: \"{$name}\"";
        }

        return ['problems' => $problems, 'warnings' => $warnings];
    }

    /** @param array{supplementPrice: ?float, ingredients: string[], allergens: string[]} $actualDish */
    private function compareDish(array $goldenDish, array $actualDish, string $displayName, array &$problems): void
    {
        if ((float) $goldenDish['supplementPrice'] !== (float) ($actualDish['supplementPrice'] ?? 0.0)
            && !($goldenDish['supplementPrice'] === null && $actualDish['supplementPrice'] === null)
        ) {
            $problems[] = sprintf(
                'supplementPrice mismatch for "%s": expected %s, got %s',
                $displayName,
                var_export($goldenDish['supplementPrice'], true),
                var_export($actualDish['supplementPrice'], true)
            );
        }

        $expectedIngredients = array_map('mb_strtolower', $goldenDish['ingredients']);
        $actualIngredients = array_map('mb_strtolower', $actualDish['ingredients']);
        sort($expectedIngredients);
        sort($actualIngredients);
        if ($expectedIngredients !== $actualIngredients) {
            $problems[] = sprintf(
                'ingredients mismatch for "%s": expected [%s], got [%s]',
                $displayName,
                implode(', ', $goldenDish['ingredients']),
                implode(', ', $actualDish['ingredients'])
            );
        }

        if ($goldenDish['allergens'] !== $actualDish['allergens']) {
            $problems[] = sprintf(
                'allergens mismatch for "%s": expected [%s], got [%s]',
                $displayName,
                implode(', ', $goldenDish['allergens']),
                implode(', ', $actualDish['allergens'])
            );
        }
    }

    /** UTF-8-safe Levenshtein edit distance, case-insensitive (menu dish names are rarely ASCII-only). */
    private function editDistance(string $a, string $b): int
    {
        $a = mb_str_split(mb_strtolower($a));
        $b = mb_str_split(mb_strtolower($b));
        $lenA = count($a);
        $lenB = count($b);

        $row = range(0, $lenB);
        for ($i = 1; $i <= $lenA; $i++) {
            $prev = $row[0];
            $row[0] = $i;
            for ($j = 1; $j <= $lenB; $j++) {
                $tmp = $row[$j];
                $row[$j] = min(
                    $row[$j] + 1,
                    $row[$j - 1] + 1,
                    $prev + ($a[$i - 1] === $b[$j - 1] ? 0 : 1)
                );
                $prev = $tmp;
            }
        }

        return $row[$lenB];
    }
}
