<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;
use App\Service\AccountMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * "Forgot your password?" from /admin/login — one login for owners, staff,
 * resellers and the platform owner. Logged out, so in the browser's
 * language (AdminLocaleSubscriber), like the sign-ups.
 *
 * - The answer to a request is always the same ("if that email has an
 *   account, we've sent a link"): with or without an account, and past the
 *   limits (3 per email, 10 per IP, per hour — rate_limiter.yaml), where
 *   simply nothing is sent.
 * - The link lasts 1 hour and works once. Asking for a new one or changing
 *   the password invalidates the older ones.
 * - The link's token is moved into the session and out of the URL straight
 *   away, so it can't leak through the Referer to the fonts/icons CDNs.
 */
#[Route('/admin/login/recuperar')]
class ResetPasswordController extends AbstractController
{
    private const SESSION_TOKEN = 'reset_password_token';

    public function __construct(
        private readonly ResetPasswordHelperInterface $helper,
        private readonly ResetPasswordRequestRepository $requests,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'admin_forgot_password', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        EntityManagerInterface $em,
        AccountMailer $mailer,
        #[Autowire(service: 'limiter.reset_password_email')] RateLimiterFactoryInterface $emailLimiter,
        #[Autowire(service: 'limiter.reset_password_ip')] RateLimiterFactoryInterface $ipLimiter,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin_menu');
        }

        $form = $this->createFormBuilder(options: ['translation_domain' => 'reset_password'])
            ->add('email', EmailType::class, [
                'label'       => 'request.email',
                'constraints' => [new NotBlank(message: 'error.email_blank')],
            ])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = mb_strtolower(trim((string) $form->get('email')->getData()));

            // IP first: a refused IP doesn't use up the email's own allowance.
            $allowed = $ipLimiter->create((string) $request->getClientIp())->consume()->isAccepted()
                && $emailLimiter->create($email)->consume()->isAccepted();

            if ($allowed) {
                $user = $em->getRepository(User::class)->createQueryBuilder('u')
                    ->where('LOWER(u.email) = :email')
                    ->setParameter('email', $email)
                    ->getQuery()
                    ->getOneOrNullResult();

                if ($user instanceof User) {
                    $this->requests->removeRequests($user); // a new link invalidates the older ones
                    try {
                        $token = $this->helper->generateResetToken($user);
                        $mailer->sendPasswordReset($user, $token->getToken());
                    } catch (ResetPasswordExceptionInterface) {
                        // Same answer whatever happens — see the class docblock.
                    }
                }
            }

            $this->addFlash('reset_sent', $this->translator->trans('request.sent', [], 'reset_password'));

            return $this->redirectToRoute('admin_forgot_password');
        }

        return $this->render('security/forgot_password.html.twig', [
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{token}', name: 'admin_reset_password_token', requirements: ['token' => '[A-Za-z0-9]{40}'], methods: ['GET'])]
    public function fromEmail(string $token, Request $request): Response
    {
        $request->getSession()->set(self::SESSION_TOKEN, $token);

        return $this->redirectToRoute('admin_reset_password');
    }

    #[Route('/nueva', name: 'admin_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $session = $request->getSession();
        $token = $session->get(self::SESSION_TOKEN);

        try {
            if (!is_string($token)) {
                throw new \UnexpectedValueException();
            }
            /** @var User $user */
            $user = $this->helper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface|\UnexpectedValueException) {
            $session->remove(self::SESSION_TOKEN);
            $this->addFlash('reset_error', $this->translator->trans('reset.invalid_link', [], 'reset_password'));

            return $this->redirectToRoute('admin_forgot_password');
        }

        // Same rules as the sign-up (RegistrationFormType).
        $form = $this->createFormBuilder(options: ['translation_domain' => 'reset_password'])
            ->add('password', RepeatedType::class, [
                'type'            => PasswordType::class,
                'first_options'   => ['label' => 'reset.password'],
                'second_options'  => ['label' => 'reset.password_repeat'],
                'invalid_message' => 'error.password_mismatch',
                'constraints'     => [
                    new NotBlank(message: 'error.password_blank'),
                    new Length(min: 8, minMessage: 'error.password_short'),
                ],
            ])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, $form->get('password')->getData()));
            $this->requests->removeRequests($user); // this link and any other one stop working
            $em->flush();
            $session->remove(self::SESSION_TOKEN);

            $this->addFlash('login_success', $this->translator->trans('reset.done', [], 'reset_password'));

            return $this->redirectToRoute('admin_login');
        }

        return $this->render('security/reset_password.html.twig', [
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
