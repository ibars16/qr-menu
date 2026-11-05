<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A reseller "como" one of its owners (switch_user) that reaches /partners,
 * or the super-admin "como" an owner that reaches /platform — typically the
 * browser's back arrow onto its own panel, then an action there — would
 * get access_control's bare 403 (the owner has neither role). Instead: out
 * of the owner's session and back to its own panel, with a message saying
 * so (the action isn't run). The super-admin "como" a reseller is in that
 * reseller's own /partners, so a 403 there is a real one (left alone: the
 * super-admin lacks ROLE_RESELLER); reaching /platform takes it back.
 *
 * And those panels' pages aren't kept by the browser (no-store), so the
 * back arrow asks for them again rather than showing a stale copy whose
 * forms would run as the owner.
 */
class ImpersonationExitSubscriber implements EventSubscriberInterface
{
    /** Own panel => the role the impersonator must have there. */
    private const PANELS = [
        '/partners' => User::ROLE_RESELLER,
        '/platform' => User::ROLE_SUPER_ADMIN,
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $exception = $event->getThrowable();
        $panel = self::panelOf($request->getPathInfo());
        if (!$event->isMainRequest() || $panel === null
            || !($exception instanceof AccessDeniedException || $exception instanceof AccessDeniedHttpException)) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token instanceof SwitchUserToken) {
            return;
        }
        $impersonator = $token->getOriginalToken()->getUser();
        if (!$impersonator instanceof User || !in_array(self::PANELS[$panel], $impersonator->getRoles(), true)) {
            return;
        }

        if ($request->hasSession()) {
            // /platform is Spanish only; /partners in the reseller's language.
            $locale = $panel === '/partners' ? $impersonator->getReseller()?->getAdminLocale() : 'es';
            $request->getSession()->getFlashBag()->add('success', $this->translator->trans('impersonation_left', [], 'partners', $locale));
        }
        // switch_user's own exit (as "Volver a mi panel"): back to the panel as itself.
        $event->setResponse(new RedirectResponse($panel . '?_switch_user=_exit', Response::HTTP_SEE_OTHER));
        $event->allowCustomResponseCode();
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && self::panelOf($event->getRequest()->getPathInfo()) !== null) {
            $event->getResponse()->headers->addCacheControlDirective('no-store');
        }
    }

    /** '/partners' or '/platform' for their pages (the reseller sign-up excepted), else null. */
    private static function panelOf(string $path): ?string
    {
        foreach (array_keys(self::PANELS) as $panel) {
            if (($path === $panel || str_starts_with($path, $panel . '/')) && !str_starts_with($path, '/partners/registro')) {
                return $panel;
            }
        }

        return null;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before the security ExceptionListener (1) turns it into the 403.
            KernelEvents::EXCEPTION => ['onKernelException', 2],
            KernelEvents::RESPONSE  => 'onKernelResponse',
        ];
    }
}
