<?php

namespace App\Command;

use App\Service\ExchangeRateUpdater;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:update-exchange-rates',
    description: 'Updates currency exchange rates.'
)]
class UpdateExchangeRatesCommand extends Command
{
    public function __construct(
        private ExchangeRateUpdater $updater
    ) {
        parent::__construct();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int
    {
        $output->writeln('Updating exchange rates...');

        $this->updater->updateRates();

        $output->writeln('Done.');

        return Command::SUCCESS;
    }
}
