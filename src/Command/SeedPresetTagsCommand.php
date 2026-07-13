<?php

namespace App\Command;

use App\Entity\Restaurant;
use App\Repository\RestaurantRepository;
use App\Service\DefaultTagSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'app:seed-preset-tags',
    description: 'Seeds the default system tags for all restaurants that are missing them.',
)]
final class SeedPresetTagsCommand extends Command
{
    public function __construct(
        private readonly RestaurantRepository  $restaurantRepo,
        private readonly DefaultTagSeeder      $seeder,
        private readonly EntityManagerInterface $em,
        private readonly string                $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'restaurant',
            'r',
            InputOption::VALUE_OPTIONAL,
            'Seed only for a specific restaurant ID (leave blank to seed all)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $presetCodes = $this->loadPresetCodes();

        $restaurants = $input->getOption('restaurant')
            ? [$this->restaurantRepo->find((int) $input->getOption('restaurant'))]
            : $this->restaurantRepo->findAll();

        $restaurants = array_filter($restaurants); // drop nulls if --restaurant id not found

        if (empty($restaurants)) {
            $io->error('No restaurants found.');
            return Command::FAILURE;
        }

        foreach ($restaurants as $restaurant) {
            $this->seedRestaurant($restaurant, $presetCodes, $io);
        }

        $io->success('Done.');
        return Command::SUCCESS;
    }

    private function seedRestaurant(Restaurant $restaurant, array $presetCodes, SymfonyStyle $io): void
    {
        $io->section(sprintf('[%d] %s', $restaurant->getId(), $restaurant->getName()));

        // A preset only counts as "already there" if the restaurant's own
        // tag for that code is actually the real system tag — never a
        // restaurant-created custom tag that merely happens to share the
        // same code (e.g. a manually-made "Vegetarian" tag created before
        // this restaurant ever got the real preset seeded). Treating any
        // code match as "already seeded" was the bug that let this happen
        // in the first place.
        $existingSystemCodes = [];
        $collisions = []; // code => non-system ProductTag already occupying it
        foreach ($restaurant->getProductTags() as $tag) {
            if ($tag->isSystem()) {
                $existingSystemCodes[] = $tag->getCode();
            } elseif (in_array($tag->getCode(), $presetCodes, true)) {
                $collisions[$tag->getCode()] = $tag;
            }
        }

        $missingCodes = array_diff($presetCodes, $existingSystemCodes);

        if (empty($missingCodes)) {
            $io->writeln('  All system tags already exist — skipping.');
            return;
        }

        // A system tag's code is immutable by design (see ProductTag), so a
        // collision can't be resolved automatically by renaming the
        // existing custom tag out of the way — and it shouldn't be resolved
        // by silently creating a duplicate-looking tag either. Report it
        // plainly and let a human decide (rename or remove the custom tag
        // via the Tags screen, then re-run this command) rather than either
        // failing loudly or skipping silently.
        $blocked = array_intersect($missingCodes, array_keys($collisions));
        foreach ($blocked as $code) {
            $tag = $collisions[$code];
            $io->writeln(sprintf(
                '  <error>Cannot seed system tag "%s" — a restaurant-created tag (#%d, %d dish(es)) already uses that code. Rename or remove it from the Tags screen, then re-run this command.</error>',
                $code,
                $tag->getId(),
                $tag->getProducts()->count()
            ));
        }

        $toSeed = array_diff($missingCodes, $blocked);
        if (empty($toSeed)) {
            return;
        }

        $io->writeln(sprintf(
            '  Missing system tags: <comment>%s</comment>',
            implode(', ', $toSeed)
        ));

        // Use the same seeder logic but only for the codes that are both
        // missing and actually safe to create.
        $this->seeder->seedForRestaurant($restaurant, $toSeed);
        $this->em->flush();

        $io->writeln(sprintf('  <info>Created %d missing system tag(s) with all translations.</info>', count($toSeed)));
    }

    private function loadPresetCodes(): array
    {
        $file = $this->projectDir . '/config/preset_tags.yaml';
        $data = Yaml::parseFile($file);
        return array_column($data['preset_tags'], 'code');
    }
}
