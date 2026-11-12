<?php

namespace App\Service\Billing;

use App\Entity\Reseller;
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
 * Resellers pay the same way (the same fields, PaidPeriodTrait), with two
 * lines: the base plan (quantity 1) and, only while there are any, the
 * restaurants in use over the included ones (quantity = how many). The
 * customer and the subscription carry reseller_id instead of restaurant_id.
 *
 * A reseller's restaurant whose reseller can charge it (Stripe Connect,
 * Reseller::canChargeRestaurants()) pays the same way, but in the
 * reseller's own account (Restaurant::$stripeAccountId; direct charges, no
 * platform fee) and at the reseller's price; its plan is read from the
 * price's interval, since the reseller's prices change over time. Events
 * from a connected account (Controller\StripeConnectWebhookController)
 * pass that account, and only ever touch restaurants of the reseller that
 * owns it.
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

    /** Checkout's client_reference_id for a reseller: "reseller-<id>" (a restaurant's is its bare id). */
    private const RESELLER_REFERENCE = 'reseller-';

    /**
     * @param array<string, string> $priceIds plan => Stripe Price id (price_…)
     * @param array<string, string> $resellerPriceIds plan => base Price id, and
     *        extra_<plan> => the extra restaurant's Price id
     */
    public function __construct(
        private readonly StripeGatewayInterface $gateway,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly array $priceIds,
        private readonly array $resellerPriceIds,
        private readonly int $includedRestaurants,
    ) {
    }

    /** @return string The Stripe Checkout URL to redirect the owner to. */
    public function startCheckout(Restaurant $restaurant, User $owner, string $plan, string $successUrl, string $cancelUrl, ?\DateTimeImmutable $now = null): string
    {
        $priceId = $this->planPriceId($restaurant, $plan) ?? '';
        if ($priceId === '') {
            throw new \InvalidArgumentException(sprintf('No Stripe price configured for plan "%s".', $plan));
        }

        // A reseller's restaurant: everything in the reseller's account.
        if (($reseller = $restaurant->getReseller()) !== null && $restaurant->getStripeAccountId() === null) {
            $restaurant->setStripeAccountId($reseller->getConnectAccountId());
        }

        // Saved before the session is created, so a second checkout (another
        // tab) reuses the same customer instead of creating a new one.
        if ($restaurant->getStripeCustomerId() === null) {
            $restaurant->setStripeCustomerId($this->gatewayFor($restaurant)->createCustomer($owner->getEmail(), $restaurant->getName(), ['restaurant_id' => (string) $restaurant->getId()]));
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
            // Days already paid by hand. Checkout's own trial wording is easy to miss; the first charge date, said plainly.
            $params['custom_text'] = ['submit' => ['message' => $this->translator->trans(
                'billing.checkout_no_charge_until', ['%date%' => gmdate('d/m/Y', $trialEnd)], 'admin_base',
            )]];
        }

        return $this->gatewayFor($restaurant)->createCheckoutSession($params);
    }

    /**
     * The price of $plan for new subscriptions: the platform's, or for a
     * reseller's restaurant its reseller's (null when it doesn't offer it).
     */
    private function planPriceId(Restaurant $restaurant, string $plan): ?string
    {
        $reseller = $restaurant->getReseller();
        if ($reseller === null) {
            return ($this->priceIds[$plan] ?? '') !== '' ? $this->priceIds[$plan] : null;
        }
        if (!$reseller->canChargeRestaurants() || $reseller->getConnectAccountId() === null) {
            return null;
        }

        return $reseller->restaurantPriceIdFor($plan);
    }

    /** The gateway on the account $customer's Stripe objects live in. */
    private function gatewayFor(Restaurant|Reseller $customer): StripeGatewayInterface
    {
        return $this->gateway->forAccount(self::accountOf($customer));
    }

    /** The connected account (a reseller's) a restaurant pays in; null = the platform. */
    private static function accountOf(Restaurant|Reseller $customer): ?string
    {
        return $customer instanceof Restaurant ? $customer->getStripeAccountId() : null;
    }

    /**
     * An object found from an event or a session belongs where it came
     * from: the platform's own customers to platform events, a reseller's
     * restaurant to its reseller's account only — so one connected account
     * can never touch another's restaurants.
     */
    private static function belongsTo(Restaurant|Reseller $customer, ?string $account): bool
    {
        if ($account === null) {
            return self::accountOf($customer) === null;
        }

        return $customer instanceof Restaurant
            && $customer->getReseller()?->getConnectAccountId() === $account
            && in_array($customer->getStripeAccountId(), [null, $account], true);
    }

    /**
     * A reseller subscribing: no trial, charged right away — the base plan
     * plus the extra restaurants already in use (resellerExtraQuantity()).
     *
     * @return string The Stripe Checkout URL.
     */
    public function startResellerCheckout(Reseller $reseller, User $user, string $plan, string $successUrl, string $cancelUrl): string
    {
        $basePriceId  = $this->resellerPriceIds[$plan] ?? '';
        $extraPriceId = $this->resellerPriceIds['extra_' . $plan] ?? '';
        if ($basePriceId === '' || $extraPriceId === '') {
            throw new \InvalidArgumentException(sprintf('No Stripe reseller prices configured for plan "%s".', $plan));
        }

        if ($reseller->getStripeCustomerId() === null) {
            $reseller->setStripeCustomerId($this->gateway->createCustomer($user->getEmail(), $reseller->getName(), ['reseller_id' => (string) $reseller->getId()]));
            $this->em->flush();
        }

        $lineItems = [['price' => $basePriceId, 'quantity' => 1]];
        if (($extra = $this->resellerExtraQuantity($reseller)) > 0) {
            $lineItems[] = ['price' => $extraPriceId, 'quantity' => $extra];
        }

        return $this->gateway->createCheckoutSession([
            'mode'                => 'subscription',
            'customer'            => $reseller->getStripeCustomerId(),
            'client_reference_id' => self::RESELLER_REFERENCE . $reseller->getId(),
            'line_items'          => $lineItems,
            'subscription_data'   => ['metadata' => ['reseller_id' => (string) $reseller->getId()]],
            'success_url'         => $successUrl,
            'cancel_url'          => $cancelUrl,
        ]);
    }

    /** The reseller's restaurants in use (not archived) over the included ones. */
    public function resellerExtraQuantity(Reseller $reseller): int
    {
        $inUse = (int) $this->em->getRepository(Restaurant::class)->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.reseller = :reseller')->andWhere('r.archivedAt IS NULL')
            ->setParameter('reseller', $reseller)
            ->getQuery()->getSingleScalarResult();

        return max(0, $inUse - $this->includedRestaurants);
    }

    /**
     * syncResellerExtras() that never fails the caller (the restaurant is
     * already created/archived): logged, and fixed by the next change.
     */
    public function syncResellerExtrasOrLog(Reseller $reseller): void
    {
        try {
            $this->syncResellerExtras($reseller);
        } catch (\Throwable $e) {
            $this->logger->error('Stripe reseller extra restaurants not updated; retried on the next change', [
                'reseller' => $reseller->getId(), 'exception' => $e,
            ]);
        }
    }

    /**
     * Its restaurants paying it by card (in its connected account): their
     * collection paused while the reseller is expired (Stripe voids the
     * invoices, so the paused time is never billed), resumed once it's up
     * to date. On resume, a restaurant whose own paidUntil passed meanwhile
     * (its renewal was voided) starts a new period charged right away
     * (billing_cycle_anchor now, always_invoice: prorated), applied at once; otherwise
     * it just carries on. Each subscription is re-read and only changed if
     * it isn't as it should be, so running it again does nothing twice.
     * One restaurant failing doesn't stop the others.
     *
     * @return array{paused: list<string>, resumed: list<string>, failed: list<string>} restaurant names
     */
    public function syncResellerCollection(Reseller $reseller, bool $dryRun = false, ?\DateTimeImmutable $today = null): array
    {
        $result = ['paused' => [], 'resumed' => [], 'failed' => []];
        $account = $reseller->getConnectAccountId();
        if ($account === null) {
            return $result;
        }

        $pause = $reseller->isExpired($today);
        $restaurants = $this->em->getRepository(Restaurant::class)->findBy(['reseller' => $reseller, 'stripeAccountId' => $account], ['id' => 'ASC']);
        foreach ($restaurants as $restaurant) {
            if (!$restaurant->hasLiveSubscription()) {
                continue;
            }
            try {
                $changed = $pause ? $this->pauseCollection($restaurant, $dryRun) : $this->resumeCollection($restaurant, $dryRun, $today);
                if ($changed) {
                    $result[$pause ? 'paused' : 'resumed'][] = $restaurant->getName();
                }
            } catch (\Throwable $e) {
                $result['failed'][] = $restaurant->getName();
                $this->logger->error('Stripe collection of a reseller restaurant not ' . ($pause ? 'paused' : 'resumed') . '; retried on the next run', [
                    'reseller' => $reseller->getId(), 'restaurant' => $restaurant->getId(), 'exception' => $e,
                ]);
            }
        }

        return $result;
    }

    /** syncResellerCollection() right after the reseller is up to date again (it pays); never fails the caller. */
    public function resumeResellerCollectionOrLog(Reseller $reseller): void
    {
        if ($reseller->isExpired()) {
            return;
        }
        try {
            $this->syncResellerCollection($reseller);
        } catch (\Throwable $e) {
            $this->logger->error('Stripe collection of reseller restaurants not resumed; retried by app:resellers:sync-billing', [
                'reseller' => $reseller->getId(), 'exception' => $e,
            ]);
        }
    }

    /** @return bool whether it was (or, in a dry run, would be) paused now */
    private function pauseCollection(Restaurant $restaurant, bool $dryRun): bool
    {
        $subscription = $this->liveSubscription($restaurant);
        if (!self::collectable($subscription) || ($subscription['pause_collection']['behavior'] ?? null) === 'void') {
            return false;
        }
        if (!$dryRun) {
            $this->gatewayFor($restaurant)->updateSubscription($subscription['id'], ['pause_collection' => ['behavior' => 'void']]);
        }

        return true;
    }

    /** @return bool whether it was (or, in a dry run, would be) resumed now */
    private function resumeCollection(Restaurant $restaurant, bool $dryRun, ?\DateTimeImmutable $today): bool
    {
        $subscription = $this->liveSubscription($restaurant);
        if (!self::collectable($subscription) || ($subscription['pause_collection'] ?? null) === null) {
            return false;
        }
        if ($dryRun) {
            return true;
        }

        $params = ['pause_collection' => ''];
        $newPeriod = $restaurant->isOwnPeriodExpired($today);
        if ($newPeriod) {
            // Flexible billing mode (our subscriptions'): an anchor reset
            // only invoices with always_invoice — prorated, so the unused
            // part of the voided period is credited.
            $params['billing_cycle_anchor'] = ['type' => 'now'];
            $params['proration_behavior']   = 'always_invoice';
        }
        $updated = $this->gatewayFor($restaurant)->updateSubscription($subscription['id'], $params);

        // The new period's invoice is charged right away: apply it now
        // instead of waiting for invoice.paid (which does the same again).
        $invoiceId = $updated['latest_invoice'] ?? null;
        if ($newPeriod && $invoiceId !== null) {
            $this->syncInvoice(self::id($invoiceId), self::accountOf($restaurant));
        }

        return true;
    }

    /** @param array<string, mixed> $subscription Stripe still bills it (not ended). */
    private static function collectable(array $subscription): bool
    {
        return !in_array($subscription['status'] ?? null, ['canceled', 'incomplete_expired'], true);
    }

    /**
     * After a restaurant is created, archived or restored: sets the extra
     * line to the count (not +1/-1, so a missed update is fixed by the next
     * one), prorated on the next invoice. Added when it's needed, removed
     * when it drops to 0. Nothing to do without a live subscription.
     */
    public function syncResellerExtras(Reseller $reseller): void
    {
        if (!$reseller->hasLiveSubscription()) {
            return;
        }

        $subscription = $this->liveSubscription($reseller);
        $plan = $this->planFor($subscription) ?? throw new \LogicException('Reseller subscription without a known base price.');
        $extraPriceId = $this->resellerPriceIds['extra_' . $plan] ?? '';
        $wanted = $this->resellerExtraQuantity($reseller);
        $item = $this->extraItem($subscription);

        $change = match (true) {
            $item === null && $wanted > 0                          => ['price' => $extraPriceId, 'quantity' => $wanted],
            $item !== null && $wanted === 0                        => ['id' => $item['id'], 'deleted' => true],
            $item !== null && (int) ($item['quantity'] ?? 0) !== $wanted => ['id' => $item['id'], 'quantity' => $wanted],
            default                                                => null,
        };
        if ($change !== null) {
            $this->gateway->updateSubscription($subscription['id'], [
                'items'              => [$change],
                'proration_behavior' => 'create_prorations',
            ]);
        }
    }

    /**
     * Unix time the first charge should fall on: the end of the last day
     * already paid (paidUntil inclusive, UTC), so days paid by hand
     * (/platform, the reseller) aren't charged twice. Null = charge now:
     * never paid (the free trial ends the moment they subscribe, so the
     * first real payment — and the paid AI photo allowance — come at once),
     * expired, or under 48 hours left.
     */
    public function trialEndFor(Restaurant $restaurant, ?\DateTimeImmutable $now = null): ?int
    {
        $now = $now ?? new \DateTimeImmutable();
        if ($restaurant->getLastPaidAt() === null || $restaurant->isExpired(new \DateTimeImmutable($now->format('Y-m-d')))) {
            return null;
        }

        $trialEnd = (new \DateTimeImmutable($restaurant->getPaidUntil()->format('Y-m-d') . ' 00:00:00', new \DateTimeZone('UTC')))
            ->modify('+1 day')
            ->getTimestamp();

        return $trialEnd - $now->getTimestamp() >= self::MIN_TRIAL_SECONDS ? $trialEnd : null;
    }

    public function portalUrl(Restaurant|Reseller $restaurant, string $returnUrl): ?string
    {
        if ($restaurant->getStripeCustomerId() === null) {
            return null;
        }

        return $this->gatewayFor($restaurant)->createPortalSession($restaurant->getStripeCustomerId(), $returnUrl);
    }

    /**
     * The portal straight on its card form (the portal is configured to
     * allow only that and invoices); back on $returnUrl either way.
     */
    public function cardUpdateUrl(Restaurant|Reseller $restaurant, string $returnUrl): ?string
    {
        if ($restaurant->getStripeCustomerId() === null) {
            return null;
        }

        return $this->gatewayFor($restaurant)->createPortalSession($restaurant->getStripeCustomerId(), $returnUrl, [
            'type'             => 'payment_method_update',
            'after_completion' => ['type' => 'redirect', 'redirect' => ['return_url' => $returnUrl]],
        ]);
    }

    // ── Owner's subscription screen (/admin/billing) ──

    /**
     * Everything the screen shows about the live subscription, read from
     * Stripe. Amounts in cents; dates are Unix times.
     *
     * A reseller's also has its extra line (null without one), and no plan
     * change (other_plan null): that's only for restaurants. Its next charge
     * is the lines' total, prorations not included.
     *
     * @return array{plan: ?string, amount: int, currency: string, status: string, trialing: bool,
     *     cancel_pending: bool, next_charge: ?array{date: int, amount: int},
     *     card: ?array{brand: string, last4: string, exp_month: int, exp_year: int},
     *     pending_change: ?array{plan: string, date: int, amount: int},
     *     other_plan: ?array{plan: string, amount: int}, change_at: ?int,
     *     extra: ?array{quantity: int, unit_amount: int}}
     */
    public function overview(Restaurant|Reseller $restaurant): array
    {
        $gateway = $this->gatewayFor($restaurant);
        $connected = self::accountOf($restaurant) !== null;
        $subscription = $this->liveSubscription($restaurant, ['default_payment_method']);
        $baseItem = $this->baseItem($subscription);
        $price = $baseItem['price'];
        $plan = $this->planFor($subscription, $connected);
        $trialing = ($subscription['status'] ?? null) === 'trialing';
        $cancelPending = self::cancelPending($subscription);
        $pendingChange = $this->pendingChange($subscription, $gateway, $connected);
        $extraItem = $restaurant instanceof Reseller ? $this->extraItem($subscription) : null;
        $extra = $extraItem !== null ? ['quantity' => (int) $extraItem['quantity'], 'unit_amount' => (int) $extraItem['price']['unit_amount']] : null;

        $nextCharge = null;
        if (!$cancelPending) {
            $nextCharge = [
                'date'   => (int) ($trialing ? $subscription['trial_end'] : $baseItem['current_period_end']),
                'amount' => $pendingChange['amount'] ?? (int) $price['unit_amount'] + ($extra !== null ? $extra['quantity'] * $extra['unit_amount'] : 0),
            ];
        }

        $otherPlan = null;
        $otherKey = $plan === Restaurant::PLAN_MONTHLY ? Restaurant::PLAN_YEARLY : ($plan === Restaurant::PLAN_YEARLY ? Restaurant::PLAN_MONTHLY : null);
        if ($restaurant instanceof Restaurant && $otherKey !== null && ($otherPriceId = $this->planPriceId($restaurant, $otherKey)) !== null) {
            $otherPlan = ['plan' => $otherKey, 'amount' => (int) $gateway->retrievePrice($otherPriceId)['unit_amount']];
        }

        return [
            'plan'           => $plan,
            'amount'         => (int) $price['unit_amount'],
            'currency'       => (string) $price['currency'],
            'status'         => (string) ($subscription['status'] ?? ''),
            'trialing'       => $trialing,
            'cancel_pending' => $cancelPending,
            'next_charge'    => $nextCharge,
            'card'           => self::cardOf($this->chargedPaymentMethod($subscription, $gateway)),
            'pending_change' => $pendingChange,
            'other_plan'     => $otherPlan,
            // When a plan change takes effect: null = right away (trial).
            'change_at'      => $trialing ? null : (int) $baseItem['current_period_end'],
            'extra'          => $extra,
        ];
    }

    /**
     * The customer's latest invoices (drafts left out).
     *
     * @return list<array{date: int, amount: int, currency: string, status: string, url: ?string}>
     */
    public function invoices(Restaurant|Reseller $restaurant, int $limit = 12): array
    {
        if ($restaurant->getStripeCustomerId() === null) {
            return [];
        }

        $rows = [];
        foreach ($this->gatewayFor($restaurant)->listInvoices($restaurant->getStripeCustomerId(), $limit) as $invoice) {
            if (($invoice['status'] ?? null) === 'draft') {
                continue;
            }
            $rows[] = [
                'date'     => (int) $invoice['created'],
                'amount'   => (int) $invoice['total'],
                'currency' => (string) $invoice['currency'],
                'status'   => (string) $invoice['status'],
                'url'      => $invoice['hosted_invoice_url'] ?? $invoice['invoice_pdf'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Switch plan. In the trial: right away, no charge, trial end kept.
     * With a paid period: at its end, through a subscription schedule
     * (current price until then, the new one after) — never prorated.
     * Refused while set to cancel, or with a change already scheduled.
     */
    public function changePlan(Restaurant $restaurant, string $plan): void
    {
        $priceId = $this->planPriceId($restaurant, $plan) ?? '';
        $gateway = $this->gatewayFor($restaurant);
        $subscription = $this->liveSubscription($restaurant);
        if ($priceId === '' || self::cancelPending($subscription) || ($subscription['schedule'] ?? null) !== null) {
            throw new \LogicException('Plan change not allowed now.');
        }

        $item = $subscription['items']['data'][0];
        if ($item['price']['id'] === $priceId) {
            return;
        }

        if (($subscription['status'] ?? null) === 'trialing') {
            $gateway->updateSubscription($subscription['id'], [
                'items'              => [['id' => $item['id'], 'price' => $priceId]],
                'proration_behavior' => 'none',
                'trial_end'          => $subscription['trial_end'],
            ]);
        } else {
            $schedule = $gateway->createSubscriptionSchedule(['from_subscription' => $subscription['id']]);
            $current = $schedule['phases'][0];
            $gateway->updateSubscriptionSchedule($schedule['id'], [
                'end_behavior'       => 'release',
                'proration_behavior' => 'none',
                'phases'             => [
                    ['items' => [['price' => $item['price']['id'], 'quantity' => 1]], 'start_date' => $current['start_date'], 'end_date' => $current['end_date']],
                    ['items' => [['price' => $priceId, 'quantity' => 1]], 'duration' => ['interval' => $plan === Restaurant::PLAN_YEARLY ? 'year' : 'month', 'interval_count' => 1]],
                ],
            ]);
        }

        $this->syncSubscription($subscription['id'], self::accountOf($restaurant));
    }

    /** Drops a scheduled plan change; the subscription stays as it is now. */
    public function undoPlanChange(Restaurant $restaurant): void
    {
        $subscription = $this->liveSubscription($restaurant);
        if (($subscription['schedule'] ?? null) !== null) {
            $this->gatewayFor($restaurant)->releaseSubscriptionSchedule(self::id($subscription['schedule']));
        }
        $this->syncSubscription($subscription['id'], self::accountOf($restaurant));
    }

    /** At period end. A scheduled plan change is dropped first (Stripe won't cancel a scheduled subscription). */
    public function cancel(Restaurant|Reseller $restaurant): void
    {
        $subscription = $this->liveSubscription($restaurant);
        if (($subscription['schedule'] ?? null) !== null) {
            $this->gatewayFor($restaurant)->releaseSubscriptionSchedule(self::id($subscription['schedule']));
        }
        $this->gatewayFor($restaurant)->updateSubscription($subscription['id'], ['cancel_at_period_end' => true]);
        $this->syncSubscription($subscription['id'], self::accountOf($restaurant));
    }

    /** "No cancelar": clears both ways a cancel can be stored. */
    public function resume(Restaurant|Reseller $restaurant): void
    {
        $subscription = $this->liveSubscription($restaurant);
        if (($subscription['cancel_at_period_end'] ?? false) === true) {
            $subscription = $this->gatewayFor($restaurant)->updateSubscription($subscription['id'], ['cancel_at_period_end' => false]);
        }
        if (($subscription['cancel_at'] ?? null) !== null) {
            $this->gatewayFor($restaurant)->updateSubscription($subscription['id'], ['cancel_at' => '']);
        }
        $this->syncSubscription($subscription['id'], self::accountOf($restaurant));
    }

    /**
     * Back from the portal's card form. If the new card only landed on the
     * customer (newer than the subscription's own), the subscription would
     * keep charging the old one — so it's copied over.
     */
    public function syncCardFromPortal(Restaurant|Reseller $restaurant): void
    {
        $subscription = $this->liveSubscription($restaurant, ['default_payment_method']);
        $own = is_array($subscription['default_payment_method'] ?? null) ? $subscription['default_payment_method'] : null;
        $customerCard = $this->customerPaymentMethod($subscription, $this->gatewayFor($restaurant));

        if ($own !== null && $customerCard !== null && $customerCard['id'] !== $own['id'] && (int) $customerCard['created'] > (int) $own['created']) {
            $this->gatewayFor($restaurant)->updateSubscription($subscription['id'], ['default_payment_method' => $customerCard['id']]);
        }
    }

    /**
     * @param list<string> $expand
     * @return array<string, mixed>
     */
    private function liveSubscription(Restaurant|Reseller $restaurant, array $expand = []): array
    {
        $id = $restaurant->getStripeSubscriptionId() ?? throw new \LogicException('No live Stripe subscription.');

        return $this->gatewayFor($restaurant)->retrieveSubscription($id, $expand);
    }

    /**
     * The plan change waiting in the subscription's schedule, if any.
     *
     * @param array<string, mixed> $subscription
     * @return array{plan: string, date: int, amount: int}|null
     */
    private function pendingChange(array $subscription, StripeGatewayInterface $gateway, bool $connected): ?array
    {
        if (($subscription['schedule'] ?? null) === null) {
            return null;
        }

        $current = $subscription['items']['data'][0]['price']['id'];
        foreach ($gateway->retrieveSubscriptionSchedule(self::id($subscription['schedule']))['phases'] ?? [] as $phase) {
            $priceId = self::id($phase['items'][0]['price']);
            if ($priceId === $current || (int) $phase['start_date'] <= time()) {
                continue;
            }
            $price = $gateway->retrievePrice($priceId);
            $plan = $connected ? self::planOfInterval($price) : array_search($priceId, $this->priceIds, true);
            // A price no longer configured: as in planFor().
            if (!is_string($plan) && self::isDirectRestaurantSubscription($subscription)) {
                $plan = self::planOfInterval($price);
            }
            if (is_string($plan)) {
                return ['plan' => $plan, 'date' => (int) $phase['start_date'], 'amount' => (int) $price['unit_amount']];
            }
        }

        return null;
    }

    /**
     * The card Stripe will actually charge: the subscription's own, else the customer's default.
     *
     * @param array<string, mixed> $subscription with default_payment_method expanded
     * @return array<string, mixed>|null
     */
    private function chargedPaymentMethod(array $subscription, StripeGatewayInterface $gateway): ?array
    {
        return is_array($subscription['default_payment_method'] ?? null)
            ? $subscription['default_payment_method']
            : $this->customerPaymentMethod($subscription, $gateway);
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array<string, mixed>|null
     */
    private function customerPaymentMethod(array $subscription, StripeGatewayInterface $gateway): ?array
    {
        $customer = $gateway->retrieveCustomer(self::id($subscription['customer']));
        $card = $customer['invoice_settings']['default_payment_method'] ?? null;

        return is_array($card) ? $card : null;
    }

    /**
     * @param array<string, mixed>|null $paymentMethod
     * @return array{brand: string, last4: string, exp_month: int, exp_year: int}|null
     */
    private static function cardOf(?array $paymentMethod): ?array
    {
        $card = $paymentMethod['card'] ?? null;

        return is_array($card) ? [
            'brand'     => (string) $card['brand'],
            'last4'     => (string) $card['last4'],
            'exp_month' => (int) $card['exp_month'],
            'exp_year'  => (int) $card['exp_year'],
        ] : null;
    }

    /** An expandable field: the id whether Stripe sent the id or the object. */
    private static function id(mixed $field): string
    {
        return is_array($field) ? (string) $field['id'] : (string) $field;
    }

    /**
     * checkout.session.completed, and the owner's own return from Checkout
     * (so they don't land on "expired" while the webhook is still on its
     * way). Links the subscription, sets the plan — an owner who subscribes
     * during the trial counts as "Activa" from here, not "Prueba gratis" —
     * and applies the first invoice if it's already paid.
     *
     * @param Restaurant|Reseller|null $expected When given (the customer's own
     *        return), the session must belong to it — anything else is ignored.
     * @param string|null $account The connected account the event came from;
     *        on the owner's own return, the restaurant's.
     */
    public function syncCheckoutSession(string $sessionId, Restaurant|Reseller|null $expected = null, ?string $account = null): void
    {
        if ($expected !== null && $account === null) {
            $account = self::accountOf($expected);
        }
        $gateway = $this->gateway->forAccount($account);
        $session = $gateway->retrieveCheckoutSession($sessionId);
        if (($session['mode'] ?? null) !== 'subscription' || !is_string($session['subscription'] ?? null)) {
            return;
        }

        $reference = (string) ($session['client_reference_id'] ?? '');
        $restaurant = str_starts_with($reference, self::RESELLER_REFERENCE)
            ? $this->em->find(Reseller::class, (int) substr($reference, strlen(self::RESELLER_REFERENCE)))
            : $this->em->find(Restaurant::class, (int) $reference);
        if ($restaurant === null || ($expected !== null && $restaurant !== $expected) || !self::belongsTo($restaurant, $account)) {
            return;
        }

        $subscription = $gateway->retrieveSubscription($session['subscription']);
        $this->link($restaurant, $subscription, $account);
        if (($plan = $this->planFor($subscription, $account !== null)) !== null) {
            $restaurant->setPlan($plan);
        }
        $this->em->flush();

        if (is_string($session['invoice'] ?? null)) {
            $this->syncInvoice($session['invoice'], $account);
        }
    }

    /** invoice.paid: moves paidUntil to the end of the paid period. */
    public function syncInvoice(string $invoiceId, ?string $account = null): void
    {
        $gateway = $this->gateway->forAccount($account);
        $invoice = $gateway->retrieveInvoice($invoiceId);
        $subscriptionId = $invoice['parent']['subscription_details']['subscription'] ?? null;
        if (($invoice['status'] ?? null) !== 'paid' || !is_string($subscriptionId)) {
            return;
        }

        $subscription = $gateway->retrieveSubscription($subscriptionId);
        $restaurant = $this->customerFor($subscription, $account);
        $plan = $this->planFor($subscription, $account !== null);
        $periodEnd = max(array_map(static fn (array $line): int => (int) ($line['period']['end'] ?? 0), $invoice['lines']['data'] ?? []) ?: [0]);

        if ($restaurant === null || $plan === null || $periodEnd === 0) {
            $this->logger->warning('Stripe invoice not applied', [
                'invoice' => $invoiceId, 'subscription' => $subscriptionId,
                'restaurant_found' => $restaurant !== null, 'plan' => $plan, 'period_end' => $periodEnd,
            ]);
            return;
        }

        $this->link($restaurant, $subscription, $account);

        // The trial's 0 € invoice still extends paidUntil to the trial end,
        // but isn't a payment.
        $paidAt = (int) ($invoice['amount_paid'] ?? 0) > 0
            ? self::utcDate((int) ($invoice['status_transitions']['paid_at'] ?? time()))
            : null;

        $restaurant->applyStripePeriod(self::utcDate($periodEnd), $plan, $paidAt);
        $this->em->flush();

        // A reseller paying again: its restaurants' card payments resume now.
        if ($restaurant instanceof Reseller) {
            $this->resumeResellerCollectionOrLog($restaurant);
        }
    }

    /**
     * customer.subscription.updated, the owner's return from the portal and
     * every action on /admin/billing: whether the linked subscription is set
     * to cancel, and its current plan. Re-read from Stripe,
     * so a repeated or out-of-order event just re-applies the current state;
     * a subscription that isn't the restaurant's linked one is ignored.
     */
    public function syncSubscription(string $subscriptionId, ?string $account = null): void
    {
        $restaurant = $this->linkedTo($subscriptionId, $account);
        if ($restaurant === null) {
            return;
        }

        $subscription = $this->gateway->forAccount($account)->retrieveSubscription($subscriptionId);
        $restaurant->setStripeCancelPending(self::cancelPending($subscription));
        if (($plan = $this->planFor($subscription, $account !== null)) !== null) {
            $restaurant->setPlan($plan);
        }
        $this->em->flush();
    }

    /** customer.subscription.deleted: no longer paying automatically. Dates and plan are left alone. */
    public function syncSubscriptionDeleted(string $subscriptionId, ?string $account = null): void
    {
        $restaurant = $this->linkedTo($subscriptionId, $account);
        if ($restaurant !== null) {
            $restaurant->setStripeSubscriptionId(null);
            $restaurant->setStripeCancelPending(false);
            $this->em->flush();
        }
    }

    /** @param array<string, mixed> $subscription */
    private function link(Restaurant|Reseller $restaurant, array $subscription, ?string $account = null): void
    {
        $current = $restaurant->getStripeSubscriptionId();
        if ($current !== null && $current !== $subscription['id']) {
            // A second live subscription (e.g. two checkout tabs): logged for
            // the platform owner to cancel by hand in Stripe; the restaurant
            // keeps pointing at the first one.
            $this->logger->warning('Second Stripe subscription for one customer', [
                $restaurant instanceof Reseller ? 'reseller' : 'restaurant' => $restaurant->getId(), 'kept' => $current, 'extra' => $subscription['id'],
            ]);
            return;
        }

        $restaurant->setStripeSubscriptionId($subscription['id']);
        $restaurant->setStripeCancelPending(self::cancelPending($subscription));
        if ($account !== null && $restaurant instanceof Restaurant) {
            $restaurant->setStripeAccountId($account);
        }
        if ($restaurant->getStripeCustomerId() === null && is_string($subscription['customer'] ?? null)) {
            $restaurant->setStripeCustomerId($subscription['customer']);
        }
    }

    /**
     * Whose subscription this is: its metadata (reseller_id or
     * restaurant_id), else whoever is already linked to it.
     *
     * @param array<string, mixed> $subscription
     */
    private function customerFor(array $subscription, ?string $account = null): Restaurant|Reseller|null
    {
        $resellerId = (int) ($subscription['metadata']['reseller_id'] ?? 0);
        if ($resellerId > 0 && ($reseller = $this->em->find(Reseller::class, $resellerId)) !== null) {
            return self::belongsTo($reseller, $account) ? $reseller : null;
        }
        $id = (int) ($subscription['metadata']['restaurant_id'] ?? 0);
        if ($id > 0 && ($restaurant = $this->em->find(Restaurant::class, $id)) !== null) {
            return self::belongsTo($restaurant, $account) ? $restaurant : null;
        }

        return $this->linkedTo($subscription['id'], $account);
    }

    private function linkedTo(string $subscriptionId, ?string $account = null): Restaurant|Reseller|null
    {
        $customer = $this->em->getRepository(Restaurant::class)->findOneBy(['stripeSubscriptionId' => $subscriptionId])
            ?? $this->em->getRepository(Reseller::class)->findOneBy(['stripeSubscriptionId' => $subscriptionId]);

        return $customer !== null && self::belongsTo($customer, $account) ? $customer : null;
    }

    /**
     * The plan of the subscription's base line: a restaurant's price, or a
     * reseller's base price (never the extra one).
     *
     * @param array<string, mixed> $subscription
     */
    private function planFor(array $subscription, bool $connected = false): ?string
    {
        // A reseller's prices change over time (old subscriptions keep old
        // ones): its restaurants' plan is the price's interval.
        if ($connected) {
            return self::planOfInterval($subscription['items']['data'][0]['price'] ?? []);
        }

        foreach ($subscription['items']['data'] ?? [] as $item) {
            $priceId = (string) ($item['price']['id'] ?? '');
            if ($priceId === '') {
                continue;
            }
            foreach ([$this->priceIds, $this->resellerPriceIds] as $prices) {
                $plan = array_search($priceId, $prices, true);
                if (is_string($plan) && isset(Restaurant::PLAN_MONTHS[$plan])) {
                    return $plan;
                }
            }
        }

        // A restaurant's own subscription on a price no longer configured
        // (our prices changed, Stripe keeps it on the old one): its plan is
        // the price's interval.
        if (self::isDirectRestaurantSubscription($subscription)) {
            return self::planOfInterval($subscription['items']['data'][0]['price'] ?? []);
        }

        return null;
    }

    /**
     * A platform restaurant's subscription (restaurant_id in its metadata),
     * not a reseller's.
     *
     * @param array<string, mixed> $subscription
     */
    private static function isDirectRestaurantSubscription(array $subscription): bool
    {
        return (int) ($subscription['metadata']['restaurant_id'] ?? 0) > 0
            && (int) ($subscription['metadata']['reseller_id'] ?? 0) === 0;
    }

    /** @param array<string, mixed> $price */
    private static function planOfInterval(array $price): ?string
    {
        return match ($price['recurring']['interval'] ?? null) {
            'month' => Restaurant::PLAN_MONTHLY,
            'year'  => Restaurant::PLAN_YEARLY,
            default => null,
        };
    }

    /**
     * The line that isn't the extra one (a restaurant's only line).
     *
     * @param array<string, mixed> $subscription
     * @return array<string, mixed>
     */
    private function baseItem(array $subscription): array
    {
        foreach ($subscription['items']['data'] as $item) {
            if (!$this->isExtraPrice((string) ($item['price']['id'] ?? ''))) {
                return $item;
            }
        }

        return $subscription['items']['data'][0];
    }

    /**
     * A reseller's extra-restaurants line, if it has one.
     *
     * @param array<string, mixed> $subscription
     * @return array<string, mixed>|null
     */
    private function extraItem(array $subscription): ?array
    {
        foreach ($subscription['items']['data'] ?? [] as $item) {
            if ($this->isExtraPrice((string) ($item['price']['id'] ?? ''))) {
                return $item;
            }
        }

        return null;
    }

    private function isExtraPrice(string $priceId): bool
    {
        $plan = $priceId !== '' ? array_search($priceId, $this->resellerPriceIds, true) : false;

        return is_string($plan) && str_starts_with($plan, 'extra_');
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
