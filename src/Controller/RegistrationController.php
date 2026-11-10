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
            'display_locale'        => $request->getLocale(),
        ]);
        // ?email= from the login's "no hay ninguna cuenta con este email".
        if ($request->isMethod('GET')) {
            $form->get('email')->setData((string) $request->query->get('email'));
        }
        $form->handleRequest($request);

        // Checked along with the other errors (not only once the rest is
        // valid), so one submit tells everything that's wrong.
        if ($form->isSubmitted() && $form->get('email')->isValid() && $restaurantCreator->isEmailTaken((string) $form->get('email')->getData())) {
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
