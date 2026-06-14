<?php

namespace App\Command;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Repository\RestaurantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Creates an admin user for a restaurant.'
)]
class CreateAdminUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private RestaurantRepository $restaurantRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email',    InputArgument::REQUIRED, 'Admin email')
            ->addArgument('password', InputArgument::REQUIRED, 'Admin password')
            ->addArgument('restaurant-slug', InputArgument::OPTIONAL, 'Restaurant slug (optional)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email      = $input->getArgument('email');
        $password   = $input->getArgument('password');
        $slug       = $input->getArgument('restaurant-slug');

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword(
            $this->hasher->hashPassword($user, $password)
        );

        if ($slug) {
            $restaurant = $this->restaurantRepo->findOneBy(['slug' => $slug]);
            if (!$restaurant) {
                $io->error("Restaurant with slug '{$slug}' not found.");
                return Command::FAILURE;
            }
            $user->setRestaurant($restaurant);
            $io->success("User created and linked to restaurant: {$restaurant->getName()}");
        } else {
            $io->warning('No restaurant slug provided — user created without restaurant.');
        }

        $this->em->persist($user);
        $this->em->flush();

        $io->success("Admin user created: {$email}");

        return Command::SUCCESS;
    }
}
