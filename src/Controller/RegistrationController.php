<?php

namespace App\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Service\AdminLocaleResolver;
use App\Service\DefaultTagSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        Security $security,
        SluggerInterface $slugger,
        DefaultTagSeeder $tagSeeder,
        AdminLocaleResolver $adminLocaleResolver,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin_menu');
        }

        $form = $this->createForm(RegistrationFormType::class, options: [
            'detected_admin_locale' => $adminLocaleResolver->resolveFromRequest($request),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            // 1. Create Restaurant
            $restaurant = new Restaurant();
            $restaurant->setName($data['restaurantName']);

            $baseSlug = strtolower($slugger->slug($data['restaurantName']));
            $slug     = $baseSlug;
            $i        = 1;
            while ($em->getRepository(Restaurant::class)->findOneBy(['slug' => $slug])) {
                $slug = $baseSlug . '-' . $i++;
            }
            $restaurant->setSlug($slug);
            $restaurant->setCurrency($data['currency']);
            $restaurant->setDefaultLanguage($data['language']);
            $restaurant->setAdminLocale($adminLocaleResolver->resolve($data['adminLocale']));
            $restaurant->setPrimaryColor('#C1440E');

            $em->persist($restaurant);

            // 2. Create User
            $user = new User();
            $user->setEmail($data['email']);
            $user->setRoles(['ROLE_USER']);
            $user->setPassword($hasher->hashPassword($user, $data['password']));
            $user->setRestaurant($restaurant);

            $em->persist($user);
            $em->flush();

            // 3. Seed preset dietary tags for the new restaurant
            $tagSeeder->seedForRestaurant($restaurant);
            $em->flush();

            // 4. Log in automatically
            return $security->login($user, 'form_login', 'main');
        }

        return $this->render('security/register.html.twig', [
            'form' => $form,
        ]);
    }
}
