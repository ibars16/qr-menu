<?php

namespace App\EventSubscriber;

use App\Controller\Admin\MenusController;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gates every action on Admin\MenusController behind
 * Restaurant::$setMenusEnabled (default false) — one central check on the
 * controller class, rather than repeating it in each action (see
 * MenusController's own docblock for the feature this backs).
 *
 * 404, not a redirect to /admin/menu: a restaurant without the flag should
 * never learn the feature exists — the sidebar entry is hidden the same way
 * (see admin/base.html.twig), but this listener is the actual enforcement,
 * not just the UI hint.
 */
class SetMenusFeatureGate implements EventSubscriberInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $controller = $event->getController();
        $controllerObject = is_array($controller) ? $controller[0] : $controller;
        if (!$controllerObject instanceof MenusController) {
            return;
        }

        $user = $this->security->getUser();
        $restaurant = $user instanceof User ? $user->getRestaurant() : null;

        if (!$restaurant || !$restaurant->isSetMenusEnabled()) {
            throw new NotFoundHttpException();
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }
}
