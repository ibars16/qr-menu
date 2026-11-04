<?php

namespace App\Command;

use App\Entity\Reseller;
use App\Service\Billing\StripeBilling;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Daily: the card payments of each reseller's restaurants (in its Stripe
 * account) paused while the reseller is expired — the paused time is never
 * billed — and resumed once it's up to date (StripeBilling::syncResellerCollection()).
 * Resuming also happens at once when the reseller pays (Stripe or
 * "Registrar pago" on /platform); pausing only here. Safe to run by hand
 * and again: it only changes what isn't as it should be.
 *
 * Nothing schedules it on its own: run it from the server's crontab, e.g.
 * `0 4 * * * php /path/to/bin/console app:resellers:sync-billing`.
 */
#[AsCommand(
    name: 'app:resellers:sync-billing',
    description: "Pauses / resumes card payments of resellers' restaurants by whether the reseller is up to date.",
)]
class SyncResellerBillingCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StripeBilling $billing,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would change.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $failed = 0;

        foreach ($this->em->getRepository(Reseller::class)->findBy([], ['id' => 'ASC']) as $reseller) {
            $result = $this->billing->syncResellerCollection($reseller, $dryRun);
            $state = $reseller->isExpired() ? 'vencido' : 'al día';
            foreach (['paused' => 'pausado', 'resumed' => 'reanudado', 'failed' => 'ERROR'] as $key => $label) {
                foreach ($result[$key] as $name) {
                    $output->writeln(sprintf('%s (%s) · %s: %s%s', $reseller->getName(), $state, $name, $label, $dryRun && $key !== 'failed' ? ' (simulado)' : ''));
                }
            }
            $failed += count($result['failed']);
        }

        $output->writeln($failed > 0 ? sprintf('Terminado con %d errores (ver el log).', $failed) : 'Terminado.');

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
