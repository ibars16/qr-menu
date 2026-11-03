<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\AdminLocaleResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Applies each restaurant's Admin Panel language preference to admin
 * requests, and the reseller's own to its panel (/partners; the sign-up,
 * logged out, follows the browser). Scoped to /admin and /partners only —
 * the public menu keeps resolving its own language independently
 * (Restaurant::$defaultLanguage).
 */
class AdminLocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AdminLocaleResolver $localeResolver,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path    = $request->getPathInfo();
        $partners = str_starts_with($path, '/partners');
        if (!str_starts_with($path, '/admin') && !$partners) {
            return;
        }

        $user   = $this->security->getUser();
        $locale = $this->localeResolver->getDefaultLocale();

        if ($user instanceof User && ($restaurant = $user->getRestaurant())) {
            $locale = $this->localeResolver->resolve($restaurant->getAdminLocale());
        } elseif ($user instanceof User && ($reseller = $user->getReseller())) {
            $locale = $this->localeResolver->resolve($reseller->getAdminLocale());
        } elseif ($partners && !$user instanceof User) {
            $locale = $this->localeResolver->resolveFromRequest($request);
        }

        $request->setLocale($locale);

        // The framework's own LocaleAwareListener may have already run with
        // the pre-authentication locale; set it explicitly so it takes
        // effect for the rest of this request regardless of listener order.
        if ($this->translator instanceof \Symfony\Contracts\Translation\LocaleAwareInterface) {
            $this->translator->setLocale($locale);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Must run after the security firewall (priority 8) restores the
            // token from the session, otherwise Security::getUser() is still
            // null and every request falls back to the default locale.
            KernelEvents::REQUEST => ['onKernelRequest', 7],
        ];
    }
}
