<?php

namespace App\Service\Billing;

use App\Entity\Reseller;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A reseller's own Stripe account (Stripe Connect, Accounts v2), where its
 * restaurants pay it directly: the reseller is the seller in every respect
 * (full Stripe Dashboard, Stripe's fees and negative balances are its own,
 * direct charges — refunds, disputes and invoices are its). The platform
 * takes no fee.
 *
 * Here: creating the account and its Stripe-hosted onboarding, keeping
 * "can take card payments" up to date (from the return URL and the v2
 * account events, Controller\StripeConnectAccountEventsController), and
 * the reseller's price for its restaurants — a product and recurring
 * prices created in ITS account. A changed price is a new Stripe price for
 * new subscriptions; the old one is deactivated, and existing subscriptions
 * keep it (Stripe prices can't change amount).
 *
 * Country and currency come from config (STRIPE_CONNECT_COUNTRY,
 * STRIPE_CONNECT_CURRENCY), never from code.
 */
class ResellerConnect
{
    /** Stripe's own minimum for a charge in EUR is 0,50 €; a menu subscription below 1 € is a typo. */
    public const MIN_PRICE_CENTS = 100;
    public const MAX_PRICE_CENTS = 999900;

    public function __construct(
        private readonly StripeGatewayInterface $stripe,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.connect_country%')]
        private readonly string $country,
        #[Autowire('%app.connect_currency%')]
        private readonly string $currency,
    ) {
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * The Stripe-hosted onboarding page, creating the account first if the
     * reseller has none (once: it's never replaced). $contactEmail: its
     * user's, prefilled on Stripe's form.
     */
    public function onboardingUrl(Reseller $reseller, string $contactEmail, string $returnUrl, string $refreshUrl): string
    {
        if (!$reseller->hasConnectAccount()) {
            $reseller->setConnectAccountId($this->stripe->createConnectedAccount([
                'contact_email' => $contactEmail,
                'display_name'  => $reseller->getName(),
                'dashboard'     => 'full',
                'identity'      => ['country' => strtolower($this->country)],
                'defaults'      => [
                    'currency'         => strtolower($this->currency),
                    'responsibilities' => ['fees_collector' => 'stripe', 'losses_collector' => 'stripe'],
                ],
                'configuration' => ['merchant' => ['capabilities' => ['card_payments' => ['requested' => true]]]],
                'metadata'      => ['reseller_id' => (string) $reseller->getId()],
            ]));
            $this->em->flush();
        }

        return $this->stripe->createAccountOnboardingLink($reseller->getConnectAccountId(), $returnUrl, $refreshUrl);
    }

    /** Reads the account from Stripe and stores whether it can take card payments; flushes. */
    public function refresh(Reseller $reseller): void
    {
        if (!$reseller->hasConnectAccount()) {
            return;
        }

        $account = $this->stripe->retrieveConnectedAccount($reseller->getConnectAccountId());
        $status  = $account['configuration']['merchant']['capabilities']['card_payments']['status'] ?? null;
        $reseller->setConnectChargesEnabled($status === 'active');
        $this->em->flush();
    }

    /** From an account event: the reseller owning $accountId, if any, refreshed. */
    public function syncAccount(string $accountId): void
    {
        $reseller = $this->em->getRepository(Reseller::class)->findOneBy(['connectAccountId' => $accountId]);
        if ($reseller !== null) {
            $this->refresh($reseller);
        }
    }

    /**
     * Sets the reseller's price for its restaurants (cents; yearly null =
     * monthly only): a new Stripe price in its account for each amount that
     * changed, the old one deactivated. Flushes after each Stripe write, so
     * a failure halfway leaves the database matching Stripe.
     */
    public function setPrices(Reseller $reseller, int $monthly, ?int $yearly): void
    {
        $accountId = $reseller->getConnectAccountId() ?? throw new \LogicException('Reseller has no Stripe account.');

        if ($reseller->getConnectProductId() === null) {
            $reseller->setConnectProductId($this->stripe->createConnectedProduct($accountId, [
                'name'     => $this->translator->trans('connect.product_name', [], 'partners', $reseller->getAdminLocale()),
                'metadata' => ['reseller_id' => (string) $reseller->getId()],
            ]));
            $this->em->flush();
        }

        if ($monthly !== $reseller->getRestaurantPriceMonthly() || $reseller->getConnectPriceMonthlyId() === null) {
            $old = $reseller->getConnectPriceMonthlyId();
            $reseller->setRestaurantPriceMonthly($monthly, $this->createPrice($reseller, $monthly, 'month'));
            $this->em->flush();
            $this->deactivate($accountId, $old);
        }

        if ($yearly !== $reseller->getRestaurantPriceYearly() || ($yearly !== null && $reseller->getConnectPriceYearlyId() === null)) {
            $old = $reseller->getConnectPriceYearlyId();
            $reseller->setRestaurantPriceYearly($yearly, $yearly !== null ? $this->createPrice($reseller, $yearly, 'year') : null);
            $this->em->flush();
            $this->deactivate($accountId, $old);
        }
    }

    private function createPrice(Reseller $reseller, int $cents, string $interval): string
    {
        return $this->stripe->createConnectedPrice($reseller->getConnectAccountId(), [
            'product'     => $reseller->getConnectProductId(),
            'unit_amount' => $cents,
            'currency'    => strtolower($this->currency),
            'recurring'   => ['interval' => $interval],
            'metadata'    => ['reseller_id' => (string) $reseller->getId(), 'plan' => $interval === 'year' ? 'yearly' : 'monthly'],
        ]);
    }

    /** Best effort: an old price left active is harmless (nothing offers it any more). */
    private function deactivate(string $accountId, ?string $priceId): void
    {
        if ($priceId === null) {
            return;
        }
        try {
            $this->stripe->deactivateConnectedPrice($accountId, $priceId);
        } catch (\Throwable $e) {
            $this->logger->warning('Old connected price not deactivated', ['account' => $accountId, 'price' => $priceId, 'exception' => $e]);
        }
    }
}
