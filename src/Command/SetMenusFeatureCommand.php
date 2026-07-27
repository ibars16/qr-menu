<?php

namespace App\Command;

use App\Repository\RestaurantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:restaurant:set-menus',
    description: 'Enables or disables the "Set menus" admin feature for one restaurant.'
)]
class SetMenusFeatureCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RestaurantRepository $restaurantRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Restaurant slug')
            ->addArgument('state', InputArgument::REQUIRED, '"on" or "off"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $slug  = $input->getArgument('slug');
        $state = strtolower($input->getArgument('state'));

        if (!in_array($state, ['on', 'off'], true)) {
            $io->error('State must be "on" or "off".');
            return Command::FAILURE;
        }

        $restaurant = $this->restaurantRepo->findOneBy(['slug' => $slug]);
        if (!$restaurant) {
            $io->error("Restaurant with slug '{$slug}' not found.");
            return Command::FAILURE;
        }

        $oldState = $restaurant->isSetMenusEnabled();
        $newState = $state === 'on';
        $restaurant->setSetMenusEnabled($newState);
        $this->em->flush();

        $io->success(sprintf(
            'Set menus for "%s" (%s): %s → %s',
            $restaurant->getName(),
            $slug,
            $oldState ? 'on' : 'off',
            $newState ? 'on' : 'off',
        ));

        return Command::SUCCESS;
    }
}
