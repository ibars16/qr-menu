<?php

namespace App\Controller\Admin;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\Billing\StripeBilling;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The owner's own subscription: redirects to Stripe Checkout / the Stripe
 * Customer Portal, no screens of its own. Owner only — staff never pay —
 * except the payment link, which sorts everyone else out itself.
 * Reachable while expired: SubscriptionGate lets /admin/billing through.
 */
#[Route('/admin/billing', name: 'admin_billing_')]
class BillingController extends AbstractController
{
    public function __construct(
        private readonly StripeBilling $billing,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[IsGranted(User::ROLE_OWNER)]
    #[Route('/checkout', name: 'checkout', methods: ['POST'])]
    public function checkout(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('billing-checkout', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $plan = $request->request->get('plan');
        if (!isset(Restaurant::PLAN_MONTHS[$plan])) {
            return $this->redirectToRoute('admin_menu');
        }

        $restaurant = $this->restaurant();

        // Already paying through Stripe: never a second subscription —
        // changing plan or card happens in the portal.
        if ($restaurant->hasLiveSubscription()) {
            return $this->toPortal($restaurant);
        }

        try {
            $url = $this->billing->startCheckout(
                $restaurant,
                $this->getUser(),
                $plan,
                $this->generateUrl('admin_billing_return', [], UrlGeneratorInterface::ABSOLUTE_URL) . '?session_id={CHECKOUT_SESSION_ID}',
                $this->generateUrl('admin_menu', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        } catch (\Throwable $e) {
            return $this->stripeFailed($e);
        }

        return new RedirectResponse($url, Response::HTTP_SEE_OTHER);
    }

    #[IsGranted(User::ROLE_OWNER)]
    #[Route('/return', name: 'return', methods: ['GET'])]
    public function return(Request $request): Response
    {
        $sessionId = $request->query->get('session_id');
        if (is_string($sessionId) && str_starts_with($sessionId, 'cs_')) {
            try {
                $this->billing->syncCheckoutSession($sessionId, $this->restaurant());
            } catch (\Throwable $e) {
                // The webhook will apply it anyway; just don't block the owner.
                $this->logger->error('Stripe checkout return sync failed', ['exception' => $e]);
            }
        }

        return $this->redirectToRoute('admin_menu');
    }

    /**
     * Back from the portal: picks up a cancel (or its undo) right away
     * instead of waiting for customer.subscription.updated.
     */
    #[IsGranted(User::ROLE_OWNER)]
    #[Route('/portal-return', name: 'portal_return', methods: ['GET'])]
    public function portalReturn(): Response
    {
        $subscriptionId = $this->restaurant()->getStripeSubscriptionId();
        if ($subscriptionId !== null) {
            try {
                $this->billing->syncSubscription($subscriptionId);
            } catch (\Throwable $e) {
                // The webhook will apply it anyway; just don't block the owner.
                $this->logger->error('Stripe portal return sync failed', ['exception' => $e]);
            }
        }

        return $this->redirectToRoute('admin_menu');
    }

    #[IsGranted(User::ROLE_OWNER)]
    #[Route('/portal', name: 'portal', methods: ['POST'])]
    public function portal(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('billing-portal', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        return $this->toPortal($this->restaurant());
    }

    /**
     * The link the platform owner copies from /platform ("Copiar enlace de
     * pago"). A plain GET with nothing secret in it: not logged in → login,
     * then back here (the firewall's saved target path). Only this
     * restaurant's owner gets the plan dialog (or the portal when already
     * paying); anyone else lands where they normally would.
     *
     * The super-admin opening it is switched (switch_user) to that owner and
     * comes back here, seeing exactly what the owner would; already "as"
     * another owner, Symfony exits that one first.
     */
    #[Route('/pay/{id}', name: 'pay', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pay(int $id, EntityManagerInterface $em): Response
    {
        $restaurant = $this->getUser()?->getRestaurant();
        $impersonating = $this->isGranted('IS_IMPERSONATOR');

        if ($this->isGranted(User::ROLE_SUPER_ADMIN) || ($impersonating && $restaurant?->getId() !== $id)) {
            $owner = $this->ownerOf($id, $em);
            if ($owner === null) {
                return $this->redirectToRoute('platform_index', $impersonating ? ['_switch_user' => '_exit'] : []);
            }

            return $this->redirectToRoute('admin_billing_pay', ['id' => $id, '_switch_user' => $owner->getUserIdentifier()]);
        }

        if ($restaurant === null || $restaurant->getId() !== $id) {
            return $this->redirectToRoute('admin_menu');
        }

        if (!$this->isGranted(User::ROLE_OWNER)) {
            // Expired: the expired page already says it.
            if (!$restaurant->isExpired()) {
                $this->addFlash('warning', $this->translator->trans('billing.staff_expired', [], 'admin_base'));
            }

            return $this->redirectToRoute('admin_menu');
        }

        if ($restaurant->hasLiveSubscription()) {
            return $this->toPortal($restaurant);
        }

        // Expired, admin_menu is the expired page with the same two plans.
        return $this->redirectToRoute('admin_menu', ['billing' => 'choose']);
    }

    /** Same "first owner" as /platform shows: lowest id with ROLE_OWNER. */
    private function ownerOf(int $restaurantId, EntityManagerInterface $em): ?User
    {
        foreach ($em->getRepository(User::class)->findBy(['restaurant' => $restaurantId], ['id' => 'ASC']) as $user) {
            if (in_array(User::ROLE_OWNER, $user->getRoles(), true)) {
                return $user;
            }
        }

        return null;
    }

    private function toPortal(Restaurant $restaurant): Response
    {
        try {
            $url = $this->billing->portalUrl($restaurant, $this->generateUrl('admin_billing_portal_return', [], UrlGeneratorInterface::ABSOLUTE_URL));
        } catch (\Throwable $e) {
            return $this->stripeFailed($e);
        }

        return $url !== null ? new RedirectResponse($url, Response::HTTP_SEE_OTHER) : $this->redirectToRoute('admin_menu');
    }

    private function stripeFailed(\Throwable $e): Response
    {
        $this->logger->error('Stripe request failed', ['exception' => $e]);
        $this->addFlash('error', $this->translator->trans('billing.error', [], 'admin_base'));

        return $this->redirectToRoute('admin_menu');
    }

    private function restaurant(): Restaurant
    {
        return $this->getUser()?->getRestaurant() ?? throw $this->createAccessDeniedException();
    }
}
