<?php

namespace App\EventSubscriber;

use App\Entity\Restaurant;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Two rules on every /admin request (login and logout excepted):
 *
 * - A super-admin (platform owner, no restaurant) is sent to /platform,
 *   a reseller (no restaurant either) to its own panel, /partners.
 * - A user whose restaurant is expired (Restaurant::isExpired(), i.e.
 *   paidUntil before today) gets only the "subscription expired" page —
 *   unless it's its reseller or the super-admin "como" the owner
 *   (switch_user), there to set up the menu while it's unpaid or expired
 *   (preparingAsOwner()). The public menu and Smart Waiter stay off.
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
        // /admin/billing: an expired owner has to be able to pay.
        if (!str_starts_with($path, '/admin') || str_starts_with($path, '/admin/login') || str_starts_with($path, '/admin/logout') || str_starts_with($path, '/admin/billing')) {
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

        if (in_array(User::ROLE_RESELLER, $user->getRoles(), true)) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('partners_index')));
            return;
        }

        $restaurant = $user->getRestaurant();
        if ($restaurant && $restaurant->isExpired() && !$this->preparingAsOwner($restaurant)) {
            $event->setResponse(new Response(
                $this->twig->render('admin/subscription_expired.html.twig', ['restaurant' => $restaurant]),
                Response::HTTP_FORBIDDEN,
            ));
        }
    }

    /**
     * Its reseller (or the super-admin) acting as the owner may use the
     * whole panel while only the restaurant's own period is the problem
     * (awaiting its first payment or expired) — never while archived or
     * while the reseller itself is expired.
     */
    private function preparingAsOwner(Restaurant $restaurant): bool
    {
        $token = $this->security->getToken();
        if (!$token instanceof SwitchUserToken || $restaurant->isArchived() || $restaurant->getReseller()?->isExpired()) {
            return false;
        }

        $original = $token->getOriginalToken()->getUser();
        if (!$original instanceof User) {
            return false;
        }
        if (in_array(User::ROLE_SUPER_ADMIN, $original->getRoles(), true)) {
            return true;
        }

        $reseller = $restaurant->getReseller();

        return $reseller !== null
            && in_array(User::ROLE_RESELLER, $original->getRoles(), true)
            && $original->getReseller()?->getId() === $reseller->getId();
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
