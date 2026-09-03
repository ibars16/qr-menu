<?php

namespace App\Command;

use App\Entity\ProductTag;
use App\Entity\ProductTagTranslation;
use App\Entity\Restaurant;
use App\Repository\RestaurantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Updates already-seeded system tags' translations to match the current
 * text in config/preset_tags.yaml — the fix DefaultTagSeeder/
 * app:seed-preset-tags don't cover: both only ever create a tag a
 * restaurant is missing, never touch one that already exists, so editing
 * preset_tags.yaml alone only affects restaurants registered *after* the
 * edit. No AI involved — a preset tag's text always comes from the YAML,
 * never generated.
 *
 * Safe by default: a restaurant's current translation is only overwritten
 * when it still matches EXACTLY what the preset used to say — any entry
 * listed for this code in config/preset_tags_translation_history.yaml (see
 * that file's own docblock for why every past version is checked, not
 * just the immediately-previous one) — or is missing outright. Anything
 * else is assumed to be the owner's own customization and is left alone
 * unless --force is passed. This makes the command safely re-runnable for
 * any future preset retext: append the old wording to the history file,
 * then run this again for that code.
 */
#[AsCommand(
    name: 'app:tags:resync-preset-translations',
    description: "Updates already-seeded system tags' translations to match config/preset_tags.yaml, without touching owner-customized names.",
)]
final class ResyncPresetTagTranslationsCommand extends Command
{
    public function __construct(
        private readonly RestaurantRepository   $restaurantRepo,
        private readonly EntityManagerInterface $em,
        private readonly string                 $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Preset tag code to resync (e.g. "recommended")')
            ->addOption('restaurant', 'r', InputOption::VALUE_OPTIONAL, 'Only this restaurant ID (leave blank for all)')
            ->addOption('force', null, InputOption::VALUE_NONE, "Overwrite every restaurant's translation, including ones that don't match a known past preset wording (i.e. likely owner-customized)")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would change without writing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $code    = $input->getArgument('code');
        $force   = (bool) $input->getOption('force');
        $dryRun  = (bool) $input->getOption('dry-run');

        $target = $this->loadTargetTranslations($code);
        if ($target === null) {
            $io->error(sprintf('No preset with code "%s" in config/preset_tags.yaml.', $code));
            return Command::FAILURE;
        }

        $knownOldValues = $this->loadHistoryTranslations($code); // list of {locale: name} maps

        $restaurants = $input->getOption('restaurant')
            ? array_filter([$this->restaurantRepo->find((int) $input->getOption('restaurant'))])
            : $this->restaurantRepo->findAll();

        if (empty($restaurants)) {
            $io->error('No restaurants found.');
            return Command::FAILURE;
        }

        $updatedCount  = 0;
        $skippedCount  = 0;
        $touchedRestaurantIds = [];

        foreach ($restaurants as $restaurant) {
            $tag = $this->findSystemTag($restaurant, $code);
            if (!$tag) {
                continue; // never seeded here — app:seed-preset-tags' job, not this one's
            }

            $restaurantChanged = false;

            foreach ($target as $locale => $newName) {
                $translation = $tag->getTranslation($locale);
                $currentName = $translation?->getName();

                if ($currentName === $newName) {
                    continue; // already correct
                }

                $safe = $force
                    || $currentName === null
                    || $this->matchesAnyKnownOldValue($currentName, $locale, $knownOldValues);

                if (!$safe) {
                    $skippedCount++;
                    $io->writeln(sprintf(
                        '  <comment>[%d] %s — %s (%s): keeping customized "%s" (pass --force to overwrite)</comment>',
                        $restaurant->getId(), $restaurant->getName(), $code, $locale, $currentName
                    ));
                    continue;
                }

                $io->writeln(sprintf(
                    '  [%d] %s — %s (%s): "%s" → "%s"',
                    $restaurant->getId(), $restaurant->getName(), $code, $locale, $currentName ?? '∅', $newName
                ));

                if (!$dryRun) {
                    if (!$translation) {
                        $translation = new ProductTagTranslation();
                        $translation->setTag($tag);
                        $translation->setLocale($locale);
                        $this->em->persist($translation);
                    }
                    $translation->setName($newName);
                }

                $updatedCount++;
                $restaurantChanged = true;
            }

            if ($restaurantChanged && !$dryRun) {
                $restaurant->bumpMenuContentVersion();
                $touchedRestaurantIds[] = $restaurant->getId();
            }
        }

        if (!$dryRun && $updatedCount > 0) {
            $this->em->flush();

            // Same pool MenuController's menu-content cache reads/writes
            // (see doctrine.yaml) — without this, an already-cached menu
            // page keeps serving the old tag name until that cache entry's
            // TTL expires on its own.
            $clearCache = $this->getApplication()?->find('cache:pool:clear');
            $clearCache?->run(new ArrayInput([
                'pools' => ['doctrine.result_cache_pool'],
            ]), $output);
        }

        $io->newLine();
        if ($dryRun) {
            $io->success(sprintf('Dry run: %d translation(s) would change, %d skipped as customized.', $updatedCount, $skippedCount));
        } else {
            $io->success(sprintf(
                '%d translation(s) updated across %d restaurant(s), %d skipped as customized. Doctrine result cache cleared.',
                $updatedCount, count(array_unique($touchedRestaurantIds)), $skippedCount
            ));
        }

        return Command::SUCCESS;
    }

    private function findSystemTag(Restaurant $restaurant, string $code): ?ProductTag
    {
        foreach ($restaurant->getProductTags() as $tag) {
            if ($tag->isSystem() && $tag->getCode() === $code) {
                return $tag;
            }
        }

        return null;
    }

    /** @return array<string,string>|null locale => name, or null if the code isn't a known preset */
    private function loadTargetTranslations(string $code): ?array
    {
        $file = $this->projectDir . '/config/preset_tags.yaml';
        $presets = Yaml::parseFile($file)['preset_tags'];

        foreach ($presets as $preset) {
            if ($preset['code'] === $code) {
                return $preset['translations'];
            }
        }

        return null;
    }

    /** @return list<array<string,string>> each a full past {locale: name} map for this code */
    private function loadHistoryTranslations(string $code): array
    {
        $file = $this->projectDir . '/config/preset_tags_translation_history.yaml';
        if (!is_file($file)) {
            return [];
        }

        $history = Yaml::parseFile($file)['history'] ?? [];

        return $history[$code] ?? [];
    }

    /** @param list<array<string,string>> $knownOldValues */
    private function matchesAnyKnownOldValue(string $currentName, string $locale, array $knownOldValues): bool
    {
        foreach ($knownOldValues as $pastTranslations) {
            if (($pastTranslations[$locale] ?? null) === $currentName) {
                return true;
            }
        }

        return false;
    }
}
