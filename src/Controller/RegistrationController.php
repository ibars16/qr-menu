<?php

namespace App\Controller;

use App\Form\RegistrationFormType;
use App\Service\AccountMailer;
use App\Service\AdminLocaleResolver;
use App\Service\RestaurantCreator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        Security $security,
        RestaurantCreator $restaurantCreator,
        AdminLocaleResolver $adminLocaleResolver,
        AccountMailer $accountMailer,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin_menu');
        }

        $form = $this->createForm(RegistrationFormType::class, options: [
            'detected_admin_locale' => $adminLocaleResolver->resolveFromRequest($request),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $restaurantCreator->isEmailTaken($form->get('email')->getData())) {
            $form->get('email')->addError(new FormError('error.email_taken', 'error.email_taken'));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $user = $restaurantCreator->create(
                $data['restaurantName'],
                $data['email'],
                $data['password'],
                $data['currency'],
                $data['language'],
                $adminLocaleResolver->resolve($data['adminLocale']),
            );
            $accountMailer->sendWelcome($user);

            // Log in automatically
            return $security->login($user, 'form_login', 'main');
        }

        return $this->render('security/register.html.twig', [
            'form' => $form,
        ]);
    }
}
