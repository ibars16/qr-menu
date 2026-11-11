<?php

namespace App\EventSubscriber;

use App\Entity\Category;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * While a restaurant's carta is empty (no normal categories — fixed-price
 * Menús don't count, same scope as MenuAdminController::menu()), every
 * admin screen sends the user to /admin/menu, whose empty state walks them
 * through creating it (first category or "Importar carta con IA").
 *
 * Only page navigations (GET, not XHR/JSON) are redirected, and only away
 * from screens that aren't part of building the carta: Carta itself, the
 * AI import, the product/ingredient endpoints it calls, billing (paying
 * must always work), the language-change screen and login/logout pass.
 */
class EmptyMenuRedirect implements EventSubscriberInterface
{
    /** Route-name prefixes that stay reachable with an empty carta. */
    private const ALLOWED_ROUTE_PREFIXES = [
        'admin_menu',          // admin_menu + admin_menu_import_*
        'admin_billing_',
        'admin_product_',
        'admin_ingredients_',
        'admin_language_change',
        'admin_login',
        'admin_logout',
        'admin_forgot_password',
        'admin_reset_password',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if (!$request->isMethod('GET') || !str_starts_with($route, 'admin_') || $request->isXmlHttpRequest()) {
            return;
        }
        // fetch() doesn't send X-Requested-With: only real page loads ask for HTML.
        if (!in_array('text/html', $request->getAcceptableContentTypes(), true)) {
            return;
        }
        foreach (self::ALLOWED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return;
            }
        }

        $user = $this->security->getUser();
        $restaurant = $user instanceof User ? $user->getRestaurant() : null;
        if ($restaurant === null) {
            return;
        }

        foreach ($restaurant->getCategories() as $category) {
            /** @var Category $category */
            if (!$category->isFixedPriceMenu()) {
                return;
            }
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_menu')));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the router (32), the firewall (8) and SubscriptionGate (6):
            // super-admins/resellers and expired restaurants are handled there first.
            KernelEvents::REQUEST => ['onKernelRequest', 5],
        ];
    }
}
