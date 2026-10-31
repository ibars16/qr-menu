<?php

namespace App\Service\Billing;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Owners paying their own subscription through Stripe Checkout (mode
 * subscription), plus the webhook-side sync that turns Stripe's confirmed
 * payments into Restaurant::$paidUntil / $plan / $lastPaidAt.
 *
 * Every sync re-reads the object from Stripe (never trusts the webhook
 * payload's shape) and only moves dates forward (Restaurant::applyStripePeriod()),
 * so repeated or out-of-order events are harmless. A cancelled subscription
 * or a failed charge changes nothing: the restaurant simply expires on its
 * own paidUntil.
 */
class StripeBilling
{
    /**
     * Stripe Checkout refuses a trial_end closer than 48 hours; below that
     * the owner is charged right away (losing those last trial hours).
     */
    private const MIN_TRIAL_SECONDS = 48 * 3600;

    /** @param array<string, string> $priceIds plan => Stripe Price id (price_…) */
    public function __construct(
        private readonly StripeGatewayInterface $gateway,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly array $priceIds,
    ) {
    }

    /** @return string The Stripe Checkout URL to redirect the owner to. */
    public function startCheckout(Restaurant $restaurant, User $owner, string $plan, string $successUrl, string $cancelUrl, ?\DateTimeImmutable $now = null): string
    {
        $priceId = $this->priceIds[$plan] ?? '';
        if ($priceId === '') {
            throw new \InvalidArgumentException(sprintf('No Stripe price configured for plan "%s".', $plan));
        }

        // Saved before the session is created, so a second checkout (another
        // tab) reuses the same customer instead of creating a new one.
        if ($restaurant->getStripeCustomerId() === null) {
            $restaurant->setStripeCustomerId($this->gateway->createCustomer($owner->getEmail(), $restaurant->getName(), $restaurant->getId()));
            $this->em->flush();
        }

        $params = [
            'mode'                => 'subscription',
            'customer'            => $restaurant->getStripeCustomerId(),
            'client_reference_id' => (string) $restaurant->getId(),
            'line_items'          => [['price' => $priceId, 'quantity' => 1]],
            'subscription_data'   => ['metadata' => ['restaurant_id' => (string) $restaurant->getId()]],
            'success_url'         => $successUrl,
            'cancel_url'          => $cancelUrl,
        ];
        if (($trialEnd = $this->trialEndFor($restaurant, $now)) !== null) {
            $params['subscription_data']['trial_end'] = $trialEnd;
            // Checkout's own trial wording is easy to miss; the first charge date, said plainly.
            $params['custom_text'] = ['submit' => ['message' => $this->translator->trans(
                'billing.checkout_no_charge_until', ['%date%' => gmdate('d/m/Y', $trialEnd)], 'admin_base',
            )]];
        }

        return $this->gateway->createCheckoutSession($params);
    }

    /**
     * Unix time the first charge should fall on: the end of the last day
     * already covered (paidUntil inclusive, UTC), so the owner keeps the
     * days left. Null = charge now (expired, or under 48 hours left).
     */
    public function trialEndFor(Restaurant $restaurant, ?\DateTimeImmutable $now = null): ?int
    {
        $now = $now ?? new \DateTimeImmutable();
        if ($restaurant->isExpired(new \DateTimeImmutable($now->format('Y-m-d')))) {
            return null;
        }

        $trialEnd = (new \DateTimeImmutable($restaurant->getPaidUntil()->format('Y-m-d') . ' 00:00:00', new \DateTimeZone('UTC')))
            ->modify('+1 day')
            ->getTimestamp();

        return $trialEnd - $now->getTimestamp() >= self::MIN_TRIAL_SECONDS ? $trialEnd : null;
    }

    public function portalUrl(Restaurant $restaurant, string $returnUrl): ?string
    {
        if ($restaurant->getStripeCustomerId() === null) {
            return null;
        }

        return $this->gateway->createPortalSession($restaurant->getStripeCustomerId(), $returnUrl);
    }

    /**
     * checkout.session.completed, and the owner's own return from Checkout
     * (so they don't land on "expired" while the webhook is still on its
     * way). Links the subscription, sets the plan — an owner who subscribes
     * during the trial counts as "Activa" from here, not "Prueba gratis" —
     * and applies the first invoice if it's already paid.
     *
     * @param Restaurant|null $expected When given (owner's return), the
     *        session must belong to it — anything else is ignored.
     */
    public function syncCheckoutSession(string $sessionId, ?Restaurant $expected = null): void
    {
        $session = $this->gateway->retrieveCheckoutSession($sessionId);
        if (($session['mode'] ?? null) !== 'subscription' || !is_string($session['subscription'] ?? null)) {
            return;
        }

        $restaurant = $this->em->find(Restaurant::class, (int) ($session['client_reference_id'] ?? 0));
        if ($restaurant === null || ($expected !== null && $restaurant !== $expected)) {
            return;
        }

        $subscription = $this->gateway->retrieveSubscription($session['subscription']);
        $this->link($restaurant, $subscription);
        if (($plan = $this->planFor($subscription)) !== null) {
            $restaurant->setPlan($plan);
        }
        $this->em->flush();

        if (is_string($session['invoice'] ?? null)) {
            $this->syncInvoice($session['invoice']);
        }
    }

    /** invoice.paid: moves paidUntil to the end of the paid period. */
    public function syncInvoice(string $invoiceId): void
    {
        $invoice = $this->gateway->retrieveInvoice($invoiceId);
        $subscriptionId = $invoice['parent']['subscription_details']['subscription'] ?? null;
        if (($invoice['status'] ?? null) !== 'paid' || !is_string($subscriptionId)) {
            return;
        }

        $subscription = $this->gateway->retrieveSubscription($subscriptionId);
        $restaurant = $this->restaurantFor($subscription);
        $plan = $this->planFor($subscription);
        $periodEnd = max(array_map(static fn (array $line): int => (int) ($line['period']['end'] ?? 0), $invoice['lines']['data'] ?? []) ?: [0]);

        if ($restaurant === null || $plan === null || $periodEnd === 0) {
            $this->logger->warning('Stripe invoice not applied', [
                'invoice' => $invoiceId, 'subscription' => $subscriptionId,
                'restaurant_found' => $restaurant !== null, 'plan' => $plan, 'period_end' => $periodEnd,
            ]);
            return;
        }

        $this->link($restaurant, $subscription);

        // The trial's 0 € invoice still extends paidUntil to the trial end,
        // but isn't a payment.
        $paidAt = (int) ($invoice['amount_paid'] ?? 0) > 0
            ? self::utcDate((int) ($invoice['status_transitions']['paid_at'] ?? time()))
            : null;

        $restaurant->applyStripePeriod(self::utcDate($periodEnd), $plan, $paidAt);
        $this->em->flush();
    }

    /**
     * customer.subscription.updated, and the owner's return from the portal:
     * whether the linked subscription is set to cancel. Re-read from Stripe,
     * so a repeated or out-of-order event just re-applies the current state;
     * a subscription that isn't the restaurant's linked one is ignored.
     */
    public function syncSubscription(string $subscriptionId): void
    {
        $restaurant = $this->em->getRepository(Restaurant::class)->findOneBy(['stripeSubscriptionId' => $subscriptionId]);
        if ($restaurant === null) {
            return;
        }

        $restaurant->setStripeCancelPending(self::cancelPending($this->gateway->retrieveSubscription($subscriptionId)));
        $this->em->flush();
    }

    /** customer.subscription.deleted: no longer paying automatically. Dates and plan are left alone. */
    public function syncSubscriptionDeleted(string $subscriptionId): void
    {
        $restaurant = $this->em->getRepository(Restaurant::class)->findOneBy(['stripeSubscriptionId' => $subscriptionId]);
        if ($restaurant !== null) {
            $restaurant->setStripeSubscriptionId(null);
            $restaurant->setStripeCancelPending(false);
            $this->em->flush();
        }
    }

    /** @param array<string, mixed> $subscription */
    private function link(Restaurant $restaurant, array $subscription): void
    {
        $current = $restaurant->getStripeSubscriptionId();
        if ($current !== null && $current !== $subscription['id']) {
            // A second live subscription (e.g. two checkout tabs): logged for
            // the platform owner to cancel by hand in Stripe; the restaurant
            // keeps pointing at the first one.
            $this->logger->warning('Second Stripe subscription for one restaurant', [
                'restaurant' => $restaurant->getId(), 'kept' => $current, 'extra' => $subscription['id'],
            ]);
            return;
        }

        $restaurant->setStripeSubscriptionId($subscription['id']);
        $restaurant->setStripeCancelPending(self::cancelPending($subscription));
        if ($restaurant->getStripeCustomerId() === null && is_string($subscription['customer'] ?? null)) {
            $restaurant->setStripeCustomerId($subscription['customer']);
        }
    }

    /** @param array<string, mixed> $subscription */
    private function restaurantFor(array $subscription): ?Restaurant
    {
        $id = (int) ($subscription['metadata']['restaurant_id'] ?? 0);
        if ($id > 0 && ($restaurant = $this->em->find(Restaurant::class, $id)) !== null) {
            return $restaurant;
        }

        return $this->em->getRepository(Restaurant::class)->findOneBy(['stripeSubscriptionId' => $subscription['id']]);
    }

    /** @param array<string, mixed> $subscription */
    private function planFor(array $subscription): ?string
    {
        $priceId = $subscription['items']['data'][0]['price']['id'] ?? null;
        $plan = $priceId !== null ? array_search($priceId, $this->priceIds, true) : false;

        return $plan === false || $priceId === '' ? null : $plan;
    }

    /**
     * Won't renew: the portal's "cancel at period end" (cancel_at_period_end)
     * or a set cancel_at date — newer API versions use the latter.
     *
     * @param array<string, mixed> $subscription
     */
    private static function cancelPending(array $subscription): bool
    {
        return ($subscription['cancel_at_period_end'] ?? false) === true || ($subscription['cancel_at'] ?? null) !== null;
    }

    private static function utcDate(int $timestamp): \DateTimeImmutable
    {
        return new \DateTimeImmutable(gmdate('Y-m-d', $timestamp));
    }
}
