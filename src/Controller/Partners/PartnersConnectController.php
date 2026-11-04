<?php

namespace App\Controller\Partners;

use App\Entity\Reseller;
use App\Entity\User;
use App\Service\Billing\ResellerConnect;
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
 * The reseller's own Stripe account, from the "Cobro con tarjeta" card on
 * /partners: "Conectar Stripe" (creates the account once, then Stripe's
 * hosted onboarding), the way back from it, a fresh link when Stripe's has
 * expired, and its price for its restaurants (ResellerConnect). There is no
 * "disconnect". Like everything else on /partners, nothing changes while
 * the reseller itself is expired.
 */
#[Route('/partners/stripe', name: 'partners_connect_')]
#[IsGranted(User::ROLE_RESELLER)]
class PartnersConnectController extends AbstractController
{
    public function __construct(
        private readonly ResellerConnect $connect,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/conectar', name: 'start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('partners-connect-start', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        return $this->toOnboarding();
    }

    /** Stripe's refresh_url: the link expired or was already used — a new one. */
    #[Route('/reintentar', name: 'refresh', methods: ['GET'])]
    public function refresh(): Response
    {
        return $this->toOnboarding();
    }

    /** Stripe's return_url: back from onboarding, finished or not; the account itself says which. */
    #[Route('/vuelta', name: 'return', methods: ['GET'])]
    public function return(): Response
    {
        $reseller = $this->reseller();
        try {
            $this->connect->refresh($reseller);
        } catch (\Throwable $e) {
            $this->logger->error('Stripe connected account status not read', ['reseller' => $reseller->getId(), 'exception' => $e]);
        }

        if ($reseller->isConnectReady()) {
            $this->addFlash('success', $this->translator->trans('connect.flash.connected', [], 'partners'));
        } elseif ($reseller->hasConnectAccount()) {
            $this->addFlash('error', $this->translator->trans('connect.flash.pending', [], 'partners'));
        }

        return $this->redirectToRoute('partners_index', ['_fragment' => 'connect']);
    }

    #[Route('/precio', name: 'prices', methods: ['POST'])]
    public function prices(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('partners-connect-prices', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $reseller = $this->reseller();
        if ($reseller->isExpired() || !$reseller->hasConnectAccount()) {
            return $this->redirectToRoute('partners_index');
        }

        $monthly = self::cents((string) $request->request->get('monthly'));
        $yearlyInput = trim((string) $request->request->get('yearly'));
        $yearly = $yearlyInput === '' ? null : self::cents($yearlyInput);
        if ($monthly === null || ($yearlyInput !== '' && $yearly === null)) {
            $this->addFlash('error', $this->translator->trans('connect.error.price', [
                '%min%' => intdiv(ResellerConnect::MIN_PRICE_CENTS, 100),
                '%max%' => intdiv(ResellerConnect::MAX_PRICE_CENTS, 100),
            ], 'partners'));

            return $this->redirectToRoute('partners_index', ['_fragment' => 'connect']);
        }

        try {
            $this->connect->setPrices($reseller, $monthly, $yearly);
            $this->addFlash('success', $this->translator->trans('connect.flash.prices_saved', [], 'partners'));
        } catch (\Throwable $e) {
            $this->logger->error('Reseller prices not saved in its Stripe account', ['reseller' => $reseller->getId(), 'exception' => $e]);
            $this->addFlash('error', $this->translator->trans('connect.error.stripe', [], 'partners'));
        }

        return $this->redirectToRoute('partners_index', ['_fragment' => 'connect']);
    }

    private function toOnboarding(): Response
    {
        $reseller = $this->reseller();
        if ($reseller->isExpired()) {
            return $this->redirectToRoute('partners_index');
        }

        try {
            $url = $this->connect->onboardingUrl(
                $reseller,
                $this->getUser()->getUserIdentifier(),
                $this->generateUrl('partners_connect_return', [], UrlGeneratorInterface::ABSOLUTE_URL),
                $this->generateUrl('partners_connect_refresh', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Stripe Connect onboarding not started', ['reseller' => $reseller->getId(), 'exception' => $e]);
            $this->addFlash('error', $this->translator->trans('connect.error.stripe', [], 'partners'));

            return $this->redirectToRoute('partners_index', ['_fragment' => 'connect']);
        }

        return new RedirectResponse($url, Response::HTTP_SEE_OTHER);
    }

    /** "25", "25,5", "25.50" → 2550; null when not a price in range. */
    private static function cents(string $input): ?int
    {
        if (!preg_match('/^\s*(\d{1,4})(?:[.,](\d{1,2}))?\s*$/', $input, $m)) {
            return null;
        }
        $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $cents >= ResellerConnect::MIN_PRICE_CENTS && $cents <= ResellerConnect::MAX_PRICE_CENTS ? $cents : null;
    }

    private function reseller(): Reseller
    {
        return $this->getUser()?->getReseller() ?? throw $this->createAccessDeniedException();
    }
}
