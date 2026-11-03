<?php

namespace App\Controller\Partners;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\Billing\StripeBilling;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The reseller's own subscription: the same screen as the owner's
 * (/admin/billing, through billing/_subscription.html.twig) without the
 * plan change, Stripe Checkout to subscribe (no trial: charged right away)
 * and the portal only for the card form.
 */
#[Route('/partners/billing', name: 'partners_billing_')]
#[IsGranted(User::ROLE_RESELLER)]
class PartnersBillingController extends AbstractController
{
    public function __construct(
        private readonly StripeBilling $billing,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        #[Autowire('%app.reseller_included_restaurants%')]
        private readonly int $includedRestaurants,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $reseller = $this->reseller();
        $overview = null;
        $invoices = null;
        $stripeError = false;

        if ($reseller->hasLiveSubscription()) {
            try {
                $overview = $this->billing->overview($reseller);
            } catch (\Throwable $e) {
                $this->logger->error('Stripe reseller subscription overview failed', ['exception' => $e]);
                $stripeError = true;
            }
        }
        if ($reseller->getStripeCustomerId() !== null) {
            try {
                $invoices = $this->billing->invoices($reseller);
            } catch (\Throwable $e) {
                $this->logger->error('Stripe reseller invoice list failed', ['exception' => $e]);
            }
        }

        return $this->render('partners/billing.html.twig', [
            'reseller'     => $reseller,
            'overview'     => $overview,
            'invoices'     => $invoices,
            'stripe_error' => $stripeError,
            'included'     => $this->includedRestaurants,
        ]);
    }

    #[Route('/checkout', name: 'checkout', methods: ['POST'])]
    public function checkout(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('partners-billing-checkout', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $plan = $request->request->get('plan');
        if (!isset(Restaurant::PLAN_MONTHS[$plan])) {
            return $this->redirectToRoute('partners_index');
        }

        $reseller = $this->reseller();
        // Already paying through Stripe: never a second subscription.
        if ($reseller->hasLiveSubscription()) {
            return $this->redirectToRoute('partners_billing_index');
        }

        try {
            $url = $this->billing->startResellerCheckout(
                $reseller,
                $this->getUser(),
                $plan,
                $this->generateUrl('partners_billing_return', [], UrlGeneratorInterface::ABSOLUTE_URL) . '?session_id={CHECKOUT_SESSION_ID}',
                $this->generateUrl('partners_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        } catch (\Throwable $e) {
            return $this->stripeFailed($e, 'partners_index');
        }

        return new RedirectResponse($url, Response::HTTP_SEE_OTHER);
    }

    #[Route('/return', name: 'return', methods: ['GET'])]
    public function return(Request $request): Response
    {
        $sessionId = $request->query->get('session_id');
        if (is_string($sessionId) && str_starts_with($sessionId, 'cs_')) {
            try {
                $this->billing->syncCheckoutSession($sessionId, $this->reseller());
            } catch (\Throwable $e) {
                // The webhook will apply it anyway; just don't block the reseller.
                $this->logger->error('Stripe reseller checkout return sync failed', ['exception' => $e]);
            }
        }

        return $this->redirectToRoute('partners_index');
    }

    #[Route('/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(Request $request): Response
    {
        return $this->manage($request, fn (Reseller $reseller) => $this->billing->cancel($reseller));
    }

    #[Route('/resume', name: 'resume', methods: ['POST'])]
    public function resume(Request $request): Response
    {
        return $this->manage($request, fn (Reseller $reseller) => $this->billing->resume($reseller));
    }

    /** "Cambiar tarjeta": the portal straight on its card form, back through portal-return. */
    #[Route('/card', name: 'card', methods: ['POST'])]
    public function card(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('partners-billing-manage', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $url = $this->billing->cardUpdateUrl($this->reseller(), $this->generateUrl('partners_billing_portal_return', [], UrlGeneratorInterface::ABSOLUTE_URL));
        } catch (\Throwable $e) {
            return $this->stripeFailed($e);
        }

        return $url !== null ? new RedirectResponse($url, Response::HTTP_SEE_OTHER) : $this->redirectToRoute('partners_billing_index');
    }

    /** Back from the portal's card form: as BillingController::portalReturn(). */
    #[Route('/portal-return', name: 'portal_return', methods: ['GET'])]
    public function portalReturn(): Response
    {
        $reseller = $this->reseller();
        if ($reseller->hasLiveSubscription()) {
            try {
                $this->billing->syncCardFromPortal($reseller);
                $this->billing->syncSubscription($reseller->getStripeSubscriptionId());
            } catch (\Throwable $e) {
                $this->logger->error('Stripe reseller portal return sync failed', ['exception' => $e]);
            }
        }

        return $this->redirectToRoute('partners_billing_index');
    }

    /** @param callable(Reseller): mixed $action */
    private function manage(Request $request, callable $action): Response
    {
        if (!$this->isCsrfTokenValid('partners-billing-manage', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $reseller = $this->reseller();
        if ($reseller->hasLiveSubscription()) {
            try {
                $action($reseller);
            } catch (\Throwable $e) {
                return $this->stripeFailed($e);
            }
        }

        return $this->redirectToRoute('partners_billing_index');
    }

    private function stripeFailed(\Throwable $e, string $route = 'partners_billing_index'): Response
    {
        $this->logger->error('Stripe reseller request failed', ['exception' => $e]);
        $this->addFlash('error', $this->translator->trans('billing.error', [], 'admin_base'));

        return $this->redirectToRoute($route);
    }

    private function reseller(): Reseller
    {
        return $this->getUser()?->getReseller() ?? throw $this->createAccessDeniedException();
    }
}
