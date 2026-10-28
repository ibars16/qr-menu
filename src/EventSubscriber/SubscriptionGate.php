<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Two rules on every /admin request (login and logout excepted):
 *
 * - A super-admin (platform owner, no restaurant) is sent to /platform.
 * - A user whose restaurant is expired (Restaurant::isExpired(), i.e.
 *   paidUntil before today) gets only the "subscription expired" page.
 *
 * Checked per request rather than at login, so an already-open session or a
 * remember-me cookie stops working the moment paidUntil passes or the
 * platform owner hits "Desactivar ya".
 */
class SubscriptionGate implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/admin') || str_starts_with($path, '/admin/login') || str_starts_with($path, '/admin/logout')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        if (in_array(User::ROLE_SUPER_ADMIN, $user->getRoles(), true)) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('platform_index')));
            return;
        }

        $restaurant = $user->getRestaurant();
        if ($restaurant && $restaurant->isExpired()) {
            $event->setResponse(new Response(
                $this->twig->render('admin/subscription_expired.html.twig', ['restaurant' => $restaurant]),
                Response::HTTP_FORBIDDEN,
            ));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the firewall (8) has restored the user, and after
            // AdminLocaleSubscriber (7) so the expired page is rendered in
            // the restaurant's admin locale.
            KernelEvents::REQUEST => ['onKernelRequest', 6],
        ];
    }
}
