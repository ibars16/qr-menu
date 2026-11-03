<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Http\SecurityEvents;

/**
 * Works around symfony/security-http 8.0.11: ContextListener expects a
 * SwitchUserToken to carry ROLE_PREVIOUS_ADMIN (it adds it to the user's
 * roles before comparing them with the token's), but SwitchUserListener no
 * longer puts it there — so with a non-Equatable User every impersonated
 * session is logged out on the next request.
 *
 * Only for the two who may switch (Security\SwitchUserVoter): the
 * super-admin and a reseller.
 *
 * The marker lives only on the session token, never in User::$roles, and
 * grants nothing (not in role_hierarchy, checked nowhere). Exiting restores
 * the original token, which never had it.
 */
class SwitchUserMarker implements EventSubscriberInterface
{
    public const ROLE_PREVIOUS_ADMIN = 'ROLE_PREVIOUS_ADMIN';

    public function onSwitchUser(SwitchUserEvent $event): void
    {
        $token = $event->getToken();
        if (!$token instanceof SwitchUserToken
            || array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_RESELLER], $token->getOriginalToken()->getRoleNames()) === []
            || in_array(self::ROLE_PREVIOUS_ADMIN, $token->getRoleNames(), true)) {
            return;
        }

        $event->setToken(new SwitchUserToken(
            $token->getUser(),
            $token->getFirewallName(),
            [...$token->getRoleNames(), self::ROLE_PREVIOUS_ADMIN],
            $token->getOriginalToken(),
            $token->getOriginatedFromUri(),
        ));
    }

    public static function getSubscribedEvents(): array
    {
        return [SecurityEvents::SWITCH_USER => 'onSwitchUser'];
    }
}
