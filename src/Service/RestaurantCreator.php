<?php

namespace App\Service;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * A new restaurant with its owner: unique slug, the preset dietary tags,
 * flushed. Shared by the self-signup (/register) and a reseller's
 * "+ Nuevo restaurante" (/partners), which passes itself as $reseller.
 */
class RestaurantCreator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly SluggerInterface $slugger,
        private readonly DefaultTagSeeder $tagSeeder,
    ) {
    }

    public function isEmailTaken(string $email): bool
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]) !== null;
    }

    /**
     * @param bool $confirmEmail a self sign-up (/register, /alta/{code}): the
     *   owner must confirm the email before the public menu is shown
     *   (User::$emailConfirmationPending, EmailConfirmation)
     * @return User the new owner (ROLE_OWNER), linked to the new restaurant
     */
    public function create(
        string $name,
        string $email,
        string $plainPassword,
        string $currency,
        string $language,
        string $adminLocale,
        ?Reseller $reseller = null,
        bool $confirmEmail = false,
    ): User {
        $restaurant = new Restaurant();
        $restaurant->setName($name);

        $baseSlug = strtolower($this->slugger->slug($name));
        $slug     = $baseSlug;
        $i        = 1;
        while ($this->em->getRepository(Restaurant::class)->findOneBy(['slug' => $slug])) {
            $slug = $baseSlug . '-' . $i++;
        }
        $restaurant->setSlug($slug);
        $restaurant->setCurrency($currency);
        $restaurant->setDefaultLanguage($language);
        $restaurant->setAdminLocale($adminLocale);
        $restaurant->setPrimaryColor('#C1440E');
        // New restaurants start on the light theme ("Clásico claro"); the
        // entity's own default (classic-dark) stays for existing rows.
        $restaurant->setTheme('maison');
        $restaurant->setReseller($reseller);
        // No free trial for a reseller's restaurant: off until it's paid
        // (Restaurant::isAwaitingFirstPayment()). Ours keep their 30 days.
        if ($reseller !== null) {
            $restaurant->deactivateNow();
        }

        $this->em->persist($restaurant);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles([User::ROLE_OWNER]);
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $user->setRestaurant($restaurant);
        $user->setEmailConfirmationPending($confirmEmail);
        $restaurant->getUsers()->add($user);

        $this->em->persist($user);
        $this->em->flush();

        $this->tagSeeder->seedForRestaurant($restaurant);
        $this->em->flush();

        return $user;
    }
}
