<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AccountMailer;
use App\Service\EmailConfirmation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Confirma tu email" (EmailConfirmation): the link from the email — works
 * logged in or not — and the panel's "Reenviar".
 */
class EmailConfirmationController extends AbstractController
{
    /** Seconds between two "Reenviar" (per session). */
    private const RESEND_EVERY = 60;

    #[Route('/confirmar-email/{id}/{check}', name: 'app_confirm_email', requirements: ['id' => '\d+', 'check' => '[0-9a-f]{16}'], methods: ['GET'])]
    public function confirm(int $id, string $check, Request $request, EmailConfirmation $confirmation, AccountMailer $mailer, TranslatorInterface $translator): Response
    {
        $user = $confirmation->confirm($request, $id, $check);
        $locale = $user !== null ? $mailer->localeFor($user) : $request->getLocale();
        $current = $this->getUser();

        // Logged in as that same account: straight back to the panel.
        if ($user !== null && $current instanceof User && $current->getId() === $user->getId()) {
            $this->addFlash('success', $translator->trans('email_confirmation.done', [], 'admin_base', $locale));

            return $this->redirectToRoute('admin_menu');
        }

        return $this->render('security/email_confirmed.html.twig', [
            'confirmed' => $user !== null,
            'locale' => $locale,
        ], new Response(status: $user !== null ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST));
    }

    #[Route('/admin/confirmar-email/reenviar', name: 'admin_confirm_email_resend', methods: ['POST'])]
    public function resend(Request $request, AccountMailer $mailer, TranslatorInterface $translator): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isCsrfTokenValid('confirm_email_resend', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_menu');
        }

        if ($user->isEmailConfirmationPending()) {
            $session = $request->getSession();
            $last = (int) $session->get('confirm_email_resent_at', 0);
            if (time() - $last < self::RESEND_EVERY) {
                $this->addFlash('warning', $translator->trans('email_confirmation.wait', [], 'admin_base'));
            } else {
                $session->set('confirm_email_resent_at', time());
                $mailer->sendEmailConfirmation($user);
                $this->addFlash('success', $translator->trans('email_confirmation.sent', ['%email%' => $user->getEmail()], 'admin_base'));
            }
        }

        $back = (string) $request->request->get('back', '');

        return str_starts_with($back, '/admin') && !str_starts_with($back, '//') ? $this->redirect($back) : $this->redirectToRoute('admin_menu');
    }
}
