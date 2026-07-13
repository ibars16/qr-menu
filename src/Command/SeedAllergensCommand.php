<?php

namespace App\Command;

use App\Service\AllergenSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:allergens:seed',
    description: 'Seeds/updates the allergen taxonomy from config/allergens.yaml',
)]
final class SeedAllergensCommand extends Command
{
    public function __construct(private readonly AllergenSeeder $seeder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->seeder->seed();

        $io->success(sprintf(
            '%d created, %d updated, %d translations written. Regulatory schemes: %s.',
            $result['created'],
            $result['updated'],
            $result['translationsWritten'],
            implode(', ', $result['schemes']) ?: '(none)'
        ));

        return Command::SUCCESS;
    }
}
