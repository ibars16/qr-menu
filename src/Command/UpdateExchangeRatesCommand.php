<?php

namespace App\Command;

use App\Service\ExchangeRateUpdater;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Nothing in this repo or its deployment schedules this on its own — there is
 * no cron, systemd timer, or Symfony Scheduler worker wired up anywhere. Run
 * it periodically from outside the app (e.g. a server crontab entry like
 * `0 4 * * * php /path/to/bin/console app:update-exchange-rates`), or rates
 * silently go stale (ExchangeRateUpdater/CurrencyConverter keep serving the
 * last value they have, with no error, no matter how old it is).
 */
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
