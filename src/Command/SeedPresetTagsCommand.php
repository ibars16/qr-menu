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
    description: 'Seeds the 8 default dietary tags for all restaurants that are missing them.',
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

        // Collect the codes that already exist for this restaurant.
        $existingCodes = [];
        foreach ($restaurant->getProductTags() as $tag) {
            $existingCodes[] = $tag->getCode();
        }

        // Find which preset codes are completely missing.
        $missingCodes = array_diff($presetCodes, $existingCodes);

        if (empty($missingCodes)) {
            $io->writeln('  All preset tags already exist — skipping.');
            return;
        }

        $io->writeln(sprintf(
            '  Missing preset codes: <comment>%s</comment>',
            implode(', ', $missingCodes)
        ));

        // Use the same seeder logic but only for missing codes.
        $this->seeder->seedForRestaurant($restaurant, $missingCodes);
        $this->em->flush();

        $io->writeln(sprintf('  <info>Created %d missing preset tag(s) with all translations.</info>', count($missingCodes)));
    }

    private function loadPresetCodes(): array
    {
        $file = $this->projectDir . '/config/preset_tags.yaml';
        $data = Yaml::parseFile($file);
        return array_column($data['preset_tags'], 'code');
    }
}
