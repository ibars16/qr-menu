<?php

namespace App\Controller\Partners;

use App\Entity\Reseller;
use App\Entity\User;
use App\Form\ResellerRegistrationFormType;
use App\Service\AdminLocaleResolver;
use App\Service\AccountMailer;
use App\Service\RestaurantCreator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The reseller's own sign-up, apart from the restaurants' /register and
 * linked from nothing a restaurant sees. Creates the Reseller (expired
 * until it pays: no trial) and its ROLE_RESELLER login, then logs it in.
 */
class ResellerRegistrationController extends AbstractController
{
    #[Route('/partners/registro', name: 'partners_register')]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        Security $security,
        RestaurantCreator $restaurantCreator,
        AdminLocaleResolver $adminLocaleResolver,
        AccountMailer $accountMailer,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin_menu');
        }

        $form = $this->createForm(ResellerRegistrationFormType::class, options: [
            'detected_admin_locale' => $request->getLocale(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            if ($restaurantCreator->isEmailTaken($data['email'])) {
                $form->get('email')->addError(new FormError('register.error.email_taken', 'register.error.email_taken'));
            } else {
                $reseller = new Reseller($data['company']);
                $reseller->setAdminLocale($adminLocaleResolver->resolve($data['adminLocale']));
                $em->persist($reseller);

                $user = new User();
                $user->setEmail($data['email']);
                $user->setFirstName($data['name']);
                $user->setRoles([User::ROLE_RESELLER]);
                $user->setPassword($hasher->hashPassword($user, $data['password']));
                $user->setReseller($reseller);
                $em->persist($user);
                $em->flush();
                $accountMailer->sendWelcome($user);

                $security->login($user, 'form_login', 'main');

                return $this->redirectToRoute('partners_index');
            }
        }

        return $this->render('partners/register.html.twig', [
            'form' => $form,
        ]);
    }
}
