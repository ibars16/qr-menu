<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Security headers on every response (no full CSP: it could break things):
 *
 *  - X-Content-Type-Options: nosniff, and Referrer-Policy
 *    strict-origin-when-cross-origin (another site only ever sees our
 *    domain, never a panel URL).
 *  - Strict-Transport-Security, one year, only on an https request (never
 *    on http: it would be ignored there, and dev runs on plain http). No
 *    includeSubDomains / preload.
 *  - The panels (/admin — login included —, /partners, /platform) can't be
 *    shown inside another website (clickjacking): frame-ancestors 'none'
 *    plus X-Frame-Options DENY for old browsers. The public menu (/r/…) and
 *    everything else may be embedded on purpose (a restaurant's own site).
 *
 * A header a controller already set is left as it is.
 */
final class SecurityHeaders implements EventSubscriberInterface
{
    private const PANEL_PATH = '#^/(admin|partners|platform)(/|$)#';
    private const HSTS = 'max-age=31536000';

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -10]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        ];
        if ($request->isSecure()) {
            $defaults['Strict-Transport-Security'] = self::HSTS;
        }
        if (preg_match(self::PANEL_PATH, $request->getPathInfo()) === 1) {
            $defaults['X-Frame-Options'] = 'DENY';
            $defaults['Content-Security-Policy'] = "frame-ancestors 'none'";
        }

        foreach ($defaults as $name => $value) {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }
    }
}
