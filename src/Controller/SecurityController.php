<?php

namespace App\Controller;

use App\EventSubscriber\AdminLocaleSubscriber;
use App\Service\AdminLocaleResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/admin/login', name: 'admin_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Redirect if already logged in
        if ($this->getUser()) {
            return $this->redirectToRoute('admin_menu');
        }

        $error        = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error'         => $error,
        ]);
    }

    /**
     * Logged-out screens whose language picker (security/_top_actions)
     * comes back to them: login, "forgot password" (both), sign-up.
     */
    private const LOCALE_BACK = '#^/(admin/login(/[A-Za-z0-9_-]+)*|register|alta/[A-Za-z0-9_-]+)$#';

    /**
     * The language picker of the logged-out screens. Remembered in the
     * session and used only by them (AdminLocaleSubscriber::SESSION_LOCALE):
     * logged in, the panel keeps the restaurant's / reseller's own language.
     */
    #[Route('/admin/login/idioma', name: 'admin_login_locale', methods: ['POST'])]
    public function locale(Request $request, AdminLocaleResolver $localeResolver): Response
    {
        $locale = (string) $request->request->get('locale');
        if ($localeResolver->isSupported($locale)) {
            $request->getSession()->set(AdminLocaleSubscriber::SESSION_LOCALE, $locale);
        }

        // Only our own screens: never an open redirect.
        $back = (string) $request->request->get('back');
        if (preg_match(self::LOCALE_BACK, $back) === 1) {
            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_login', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/logout', name: 'admin_logout')]
    public function logout(): void
    {
        // Symfony handles this — method body never executes
        throw new \LogicException('This should never be reached.');
    }
}
