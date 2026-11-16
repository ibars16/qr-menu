<?php

namespace App\EventSubscriber;

use App\Attribute\RequiresAi;
use App\Entity\User;
use App\Service\AiAccess;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every /admin action marked #[RequiresAi]: refused before it runs when
 * Service\AiAccess doesn't allow it for the user's restaurant — an owner
 * normally never gets here then (SubscriptionGate), but its reseller or
 * the super-admin "como" the owner does. Message in the admin locale
 * (AdminLocaleSubscriber has already set it).
 */
final class AiAccessGate implements EventSubscriberInterface
{
    public function __construct(
        private readonly AiAccess $aiAccess,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $attribute = $event->getAttributes(RequiresAi::class)[0] ?? null;
        if (!$attribute instanceof RequiresAi) {
            return;
        }

        $user = $this->security->getUser();
        $restaurant = $user instanceof User ? $user->getRestaurant() : null;
        if ($restaurant === null || $this->aiAccess->allows($restaurant, $attribute->feature)) {
            return;
        }

        $message = $this->translator->trans('ai_blocked', domain: 'admin_base');
        $event->setController(fn () => $this->refusal($event->getRequest(), $attribute, $message));
    }

    private function refusal(Request $request, RequiresAi $attribute, string $message): Response
    {
        if ($attribute->redirectRoute !== null) {
            $request->getSession()->getFlashBag()->add('error', $message);

            return new RedirectResponse($this->urlGenerator->generate($attribute->redirectRoute));
        }

        // `error` and `message` both, for the screens reading either one;
        // `stopped` ends the AI photo batch's loop with the message shown.
        return new JsonResponse(['error' => $message, 'message' => $message, 'stopped' => 'subscription', 'done' => false], Response::HTTP_FORBIDDEN);
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onKernelController'];
    }
}
