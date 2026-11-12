<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CSRF guard for every panel action at once — many are JS fetches with no
 * token of their own: a POST / PUT / PATCH / DELETE to /admin, /partners or
 * /platform coming from another website is refused (403) before anything
 * runs. Same idea as Symfony's SameOriginCsrfTokenManager, which only acts
 * where a token is checked, applied here to the whole request:
 *
 *  - Sec-Fetch-Site (every current browser): 'same-origin' passes, and so
 *    does 'none' (started by the user, e.g. the address bar — never another
 *    site); 'cross-site' / 'same-site' don't.
 *  - Without it: Origin, else Referer, must be this site's own scheme+host.
 *  - Neither header (very old browsers, curl, scripts): passes. A CSRF
 *    attack only comes through a browser, and those send them.
 *
 * Everything else is untouched: the public menu (/r/…), the Stripe
 * webhooks, /register and /alta (with their own form token).
 */
final class SameOriginGuard implements EventSubscriberInterface
{
    private const PANEL_PATH = '#^/(admin|partners|platform)(/|$)#';
    private const UNSAFE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public static function getSubscribedEvents(): array
    {
        // Early: before the router, the firewall and any controller.
        return [KernelEvents::REQUEST => ['onKernelRequest', 64]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!in_array($request->getMethod(), self::UNSAFE_METHODS, true) || !preg_match(self::PANEL_PATH, $request->getPathInfo())) {
            return;
        }

        if (self::isFromAnotherSite($request)) {
            $event->setResponse(new Response('Forbidden', Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/plain; charset=UTF-8']));
        }
    }

    private static function isFromAnotherSite(Request $request): bool
    {
        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if ($fetchSite !== null) {
            return !in_array(strtolower($fetchSite), ['same-origin', 'none'], true);
        }

        $own = $request->getSchemeAndHttpHost();
        foreach (['Origin', 'Referer'] as $header) {
            $source = $request->headers->get($header);
            if ($source !== null) {
                return $source !== $own && !str_starts_with($source, $own . '/');
            }
        }

        return false;
    }
}
