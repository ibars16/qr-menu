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
 * The owner's own subscription: the "Suscripción" screen (plan, next
 * charge, card, plan change, cancel, invoices — all through the Stripe
 * API), Stripe Checkout to subscribe, and the portal only for the card
 * form. Owner only — staff never pay — except the payment link, which
 * sorts everyone else out itself. Reachable while expired:
 * SubscriptionGate lets /admin/billing through. A reseller's restaurant
 * works the same once its reseller can charge it (Restaurant::
 * ownerPaysByCard(): Stripe connected and a price — StripeBilling does it
 * in the reseller's account, at its price); until then it only gets the
 * screen, for information (resellerInfo()), and every action is a 404.
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
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        $restaurant = $this->getUser()?->getRestaurant() ?? throw $this->createAccessDeniedException();
        if (!$restaurant->ownerPaysByCard()) {
            return $this->resellerInfo($restaurant, $em);
        }
        $overview = null;
        $invoices = null;
        $stripeError = false;

        if ($restaurant->hasLiveSubscription()) {
            try {
                $overview = $this->billing->overview($restaurant);
            } catch (\Throwable $e) {
                $this->logger->error('Stripe subscription overview failed', ['exception' => $e]);
                $stripeError = true;
            }
        }
        if ($restaurant->getStripeCustomerId() !== null) {
            try {
                $invoices = $this->billing->invoices($restaurant);
            } catch (\Throwable $e) {
                $this->logger->error('Stripe invoice list failed', ['exception' => $e]);
            }
        }

        return $this->render('admin/billing.html.twig', [
            'restaurant'   => $restaurant,
            'contact'      => $this->resellerContact($restaurant, $em),
            'overview'     => $overview,
            'invoices'     => $invoices,
            'stripe_error' => $stripeError,
        ]);
    }

    #[IsGranted(User::ROLE_OWNER)]
    #[Route('/plan', name: 'change_plan', methods: ['POST'])]
    public function changePlan(Request $request): Response
    {
        $plan = (string) $request->request->get('plan');

        return $this->manage($request, function (Restaurant $restaurant) use ($plan): void {
            if (isset(Restaurant::PLAN_MONTHS[$plan])) {
                $this->billing->changePlan($restaurant, $plan);
            }
        });
    }

    #[IsGranted(User::ROLE_OWNER)]
    #[Route('/plan/undo', name: 'undo_plan_change', methods: ['POST'])]
    public function undoPlanChange(Request $request): Response
    {
        return $this->manage($request, fn (Restaurant $restaurant) => $this->billing->undoPlanChange($restaurant));
    }

    #[IsGranted(User::ROLE_OWNER)]
    #[Route('/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(Request $request): Response
    {
        return $this->manage($request, fn (Restaurant $restaurant) => $this->billing->cancel($restaurant));
    }

    #[IsGranted(User::ROLE_OWNER)]
    #[Route('/resume', name: 'resume', methods: ['POST'])]
    public function resume(Request $request): Response
    {
        return $this->manage($request, fn (Restaurant $restaurant) => $this->billing->resume($restaurant));
    }

    /** "Cambiar tarjeta": the portal straight on its card form, back through portal-return. */
    #[IsGranted(User::ROLE_OWNER)]
    #[Route('/card', name: 'card', methods: ['POST'])]
    public function card(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('billing-manage', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $url = $this->billing->cardUpdateUrl($this->restaurant(), $this->generateUrl('admin_billing_portal_return', [], UrlGeneratorInterface::ABSOLUTE_URL));
        } catch (\Throwable $e) {
            return $this->stripeFailed($e);
        }

        return $url !== null ? new RedirectResponse($url, Response::HTTP_SEE_OTHER) : $this->redirectToRoute('admin_billing_index');
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
        // changing plan or card happens on the subscription screen.
        if ($restaurant->hasLiveSubscription()) {
            return $this->redirectToRoute('admin_billing_index');
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

    /** The expired page's button (a live subscription whose charge failed): the portal, card and invoices only. */
    #[IsGranted(User::ROLE_OWNER)]
    #[Route('/portal', name: 'portal', methods: ['POST'])]
    public function portal(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('billing-portal', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $url = $this->billing->portalUrl($this->restaurant(), $this->generateUrl('admin_billing_portal_return', [], UrlGeneratorInterface::ABSOLUTE_URL));
        } catch (\Throwable $e) {
            return $this->stripeFailed($e);
        }

        return $url !== null ? new RedirectResponse($url, Response::HTTP_SEE_OTHER) : $this->redirectToRoute('admin_menu');
    }

    /**
     * Back from the portal's card form: makes the new card the one the
     * subscription charges, and picks up any other change right away
     * instead of waiting for customer.subscription.updated.
     */
    #[IsGranted(User::ROLE_OWNER)]
    #[Route('/portal-return', name: 'portal_return', methods: ['GET'])]
    public function portalReturn(): Response
    {
        $restaurant = $this->restaurant();
        if ($restaurant->hasLiveSubscription()) {
            try {
                $this->billing->syncCardFromPortal($restaurant);
                $this->billing->syncSubscription($restaurant->getStripeSubscriptionId(), $restaurant->getStripeAccountId());
            } catch (\Throwable $e) {
                $this->logger->error('Stripe portal return sync failed', ['exception' => $e]);
            }
        }

        return $this->redirectToRoute('admin_billing_index');
    }

    /**
     * The link the platform owner copies from /platform, or a reseller from
     * /partners for its restaurants ("Copiar enlace de pago"). A plain GET with nothing secret in it: not logged in → login,
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

        if (!$restaurant->ownerPaysByCard()) {
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
            return $this->redirectToRoute('admin_billing_index');
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

    /**
     * A subscription-screen action (CSRF "billing-manage", live subscription
     * only); StripeBilling syncs the restaurant afterwards. Back on the
     * screen either way.
     *
     * @param callable(Restaurant): mixed $action
     */
    private function manage(Request $request, callable $action): Response
    {
        if (!$this->isCsrfTokenValid('billing-manage', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $restaurant = $this->restaurant();
        if ($restaurant->hasLiveSubscription()) {
            try {
                $action($restaurant);
            } catch (\Throwable $e) {
                return $this->stripeFailed($e, 'admin_billing_index');
            }
        }

        return $this->redirectToRoute('admin_billing_index');
    }

    private function stripeFailed(\Throwable $e, string $route = 'admin_menu'): Response
    {
        $this->logger->error('Stripe request failed', ['exception' => $e]);
        $this->addFlash('error', $this->translator->trans('billing.error', [], 'admin_base'));

        return $this->redirectToRoute($route);
    }

    /**
     * A reseller's restaurant: its subscription for information only — the
     * status and date its reseller manages from /partners, and who to
     * contact (the reseller's user's email). No payment actions yet.
     */
    private function resellerInfo(Restaurant $restaurant, EntityManagerInterface $em): Response
    {
        return $this->render('admin/billing_reseller.html.twig', [
            'restaurant' => $restaurant,
            'reseller'   => $restaurant->getReseller(),
            'contact'    => $this->resellerContact($restaurant, $em),
        ]);
    }

    /** The reseller's contact email, else its user's (its first one); null for a restaurant without reseller. */
    private function resellerContact(Restaurant $restaurant, EntityManagerInterface $em): ?string
    {
        $reseller = $restaurant->getReseller();
        if ($reseller === null) {
            return null;
        }

        return $reseller->getContactEmail()
            ?? $em->getRepository(User::class)->findOneBy(['reseller' => $reseller], ['id' => 'ASC'])?->getEmail();
    }

    private function restaurant(): Restaurant
    {
        $restaurant = $this->getUser()?->getRestaurant() ?? throw $this->createAccessDeniedException();
        if (!$restaurant->ownerPaysByCard()) {
            throw $this->createNotFoundException();
        }

        return $restaurant;
    }
}
