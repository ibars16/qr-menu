<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use App\Service\EmailChange;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Mi cuenta": the logged-in panel user (owner or staff) changes their own
 * email or password. Both need the current password; a new email only
 * takes over once confirmed from its inbox (EmailChange). The session survives
 * the change: the token's user is this same entity, so what gets stored at
 * the end of the request already carries the new email/password hash.
 * Not while impersonating ("Entrar como"): that's someone else's account.
 */
#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_STAFF')]
class AccountController extends AbstractController
{
    public const MIN_PASSWORD_LENGTH = 8;

    /** Seconds between two "Reenviar" of the email change link (per session). */
    private const RESEND_EVERY = 60;
    private const RESENT_AT = 'email_change_resent_at';

    #[Route('/cuenta', name: 'account', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        ResetPasswordRequestRepository $resetRequests,
        EmailChange $emailChange,
        TranslatorInterface $translator,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $impersonating = $this->isGranted('IS_IMPERSONATOR');
        $errors = [];
        $section = null;
        $newEmail = $user->getEmail();

        if ($request->isMethod('POST') && !$impersonating) {
            $section = (string) $request->request->get('section');
            if ($section === 'email') {
                $newEmail = UserRepository::normalizeEmail((string) $request->request->get('email'));
            }

            if (!$this->isCsrfTokenValid('admin_account', (string) $request->request->get('_token'))) {
                $errors['form'] = 'error.csrf';
            } elseif (!$hasher->isPasswordValid($user, (string) $request->request->get('current_password'))) {
                $errors['current_password'] = 'error.current_password_wrong';
            } elseif ($section === 'email') {
                if (!$newEmail || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'error.email_invalid';
                } elseif ($newEmail === $user->getEmail()) {
                    $errors['email'] = 'error.email_same';
                } elseif ($em->getRepository(User::class)->findOneByEmail($newEmail)) {
                    $errors['email'] = 'error.email_taken';
                } else {
                    // Takes over only once the link sent to it is clicked (EmailChange).
                    $emailChange->request($user, $newEmail);
                    $request->getSession()->set(self::RESENT_AT, time());
                    $this->addFlash('success', $translator->trans('flash.email_change_sent', ['%email%' => $newEmail], 'admin_account'));

                    return $this->redirectToRoute('admin_account');
                }
            } elseif ($section === 'password') {
                $new = (string) $request->request->get('new_password');
                if (mb_strlen($new) < self::MIN_PASSWORD_LENGTH) {
                    $errors['new_password'] = 'error.password_too_short';
                } elseif ($new !== (string) $request->request->get('new_password_confirm')) {
                    $errors['new_password_confirm'] = 'error.password_mismatch';
                } else {
                    $user->setPassword($hasher->hashPassword($user, $new));
                    $resetRequests->removeRequests($user); // a "forgot password" link sent before no longer works
                    $em->flush();
                    $this->addFlash('success', $translator->trans('flash.password_updated', [], 'admin_account'));

                    return $this->redirectToRoute('admin_account');
                }
            }
        }

        return $this->render('admin/account.html.twig', [
            'errors' => $errors,
            'section' => $section,
            'newEmail' => $newEmail,
            'impersonating' => $impersonating,
            'minPasswordLength' => self::MIN_PASSWORD_LENGTH,
        ], new Response(status: $errors ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/cuenta/cambio-email/reenviar', name: 'account_email_change_resend', methods: ['POST'])]
    public function resendEmailChange(Request $request, EmailChange $emailChange, TranslatorInterface $translator): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isGranted('IS_IMPERSONATOR') && $user->getPendingEmail() !== null
            && $this->isCsrfTokenValid('admin_account', (string) $request->request->get('_token'))) {
            $session = $request->getSession();
            if (time() - (int) $session->get(self::RESENT_AT, 0) < self::RESEND_EVERY) {
                $this->addFlash('warning', $translator->trans('flash.email_change_wait', [], 'admin_account'));
            } else {
                $session->set(self::RESENT_AT, time());
                $emailChange->request($user, $user->getPendingEmail());
                $this->addFlash('success', $translator->trans('flash.email_change_sent', ['%email%' => $user->getPendingEmail()], 'admin_account'));
            }
        }

        return $this->redirectToRoute('admin_account');
    }

    #[Route('/cuenta/cambio-email/cancelar', name: 'account_email_change_cancel', methods: ['POST'])]
    public function cancelEmailChange(Request $request, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isGranted('IS_IMPERSONATOR') && $user->getPendingEmail() !== null
            && $this->isCsrfTokenValid('admin_account', (string) $request->request->get('_token'))) {
            $user->setPendingEmail(null); // the link already sent stops working
            $em->flush();
            $this->addFlash('success', $translator->trans('flash.email_change_cancelled', [], 'admin_account'));
        }

        return $this->redirectToRoute('admin_account');
    }
}
