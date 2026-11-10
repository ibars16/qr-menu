<?php

namespace App\Controller;

use App\Entity\Reseller;
use App\Form\RegistrationFormType;
use App\Service\AdminLocaleResolver;
use App\Service\Billing\StripeBilling;
use App\Service\AccountMailer;
use App\Service\RestaurantCreator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A reseller's signup link for its customers (/alta/{code}, shown and
 * copied on /partners): the same form as /register, and the restaurant
 * created belongs to that reseller (counts for its extra restaurants) with
 * the usual first 30 days. Unknown code or reseller expired: "not
 * available" page and nothing is created.
 */
class ResellerSignupController extends AbstractController
{
    #[Route('/alta/{code}', name: 'reseller_signup', methods: ['GET', 'POST'])]
    public function signup(
        string $code,
        Request $request,
        EntityManagerInterface $em,
        Security $security,
        RestaurantCreator $restaurantCreator,
        AdminLocaleResolver $adminLocaleResolver,
        StripeBilling $billing,
        AccountMailer $accountMailer,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin_menu');
        }

        $reseller = $em->getRepository(Reseller::class)->findOneBy(['signupCode' => $code]);
        if ($reseller === null || $reseller->isExpired()) {
            return $this->render('security/reseller_signup_unavailable.html.twig', response: new Response(status: Response::HTTP_NOT_FOUND));
        }

        $form = $this->createForm(RegistrationFormType::class, options: [
            'detected_admin_locale' => $adminLocaleResolver->resolveFromRequest($request),
            'display_locale'        => $request->getLocale(),
        ]);
        $form->handleRequest($request);

        // Along with the other errors, as in RegistrationController.
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
                $reseller,
            );
            $billing->syncResellerExtrasOrLog($reseller);
            $accountMailer->sendWelcome($user);

            return $security->login($user, 'form_login', 'main');
        }

        return $this->render('security/register.html.twig', [
            'form'     => $form,
            'reseller' => $reseller,
        ]);
    }
}
