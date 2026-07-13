<?php

namespace App\Command;

use App\Service\GlobalIngredientAllergenSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:allergens:seed-global-ingredient-links',
    description: 'Seeds the starter Global Ingredient allergen links from config/global_ingredient_allergens.yaml',
)]
final class SeedGlobalIngredientAllergensCommand extends Command
{
    public function __construct(private readonly GlobalIngredientAllergenSeeder $seeder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->seeder->seed();

        $io->success(sprintf('%d links created, %d updated.', $result['linked'], $result['updated']));

        if (!empty($result['missingIngredients'])) {
            $io->warning('Ingredient codes not found in the Global Ingredient Library: ' . implode(', ', $result['missingIngredients']));
        }

        return Command::SUCCESS;
    }
}
