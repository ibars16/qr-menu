<?php

namespace App\Tests\Support;

use App\Service\Billing\StripeGatewayInterface;

/**
 * Test-env StripeGatewayInterface (config/services.yaml, when@test): never
 * talks to Stripe. State is static so it survives the test client's kernel
 * reboots between requests — call reset() in setUp().
 */
class FakeStripeGateway implements StripeGatewayInterface
{
    /** @var array<string, array<string, mixed>> Objects "stored in Stripe", by id. */
    public static array $objects = [];

    /** @var list<array<string, mixed>> */
    public static array $checkoutParams = [];

    /** @var list<string> */
    public static array $createdCustomers = [];

    /** @var list<string> */
    public static array $portalCustomers = [];

    /** @var list<string> */
    public static array $portalReturnUrls = [];

    /** @var list<array<string, mixed>|null> */
    public static array $portalFlows = [];

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> Writes: [method, id, params]. */
    public static array $calls = [];

    /** @var list<array<string, mixed>> What listInvoices() returns, newest first. */
    public static array $invoices = [];

    public static bool $fail = false;

    public static bool $failInvoices = false;

    public static function reset(): void
    {
        self::$objects = [];
        self::$checkoutParams = [];
        self::$createdCustomers = [];
        self::$portalCustomers = [];
        self::$portalReturnUrls = [];
        self::$portalFlows = [];
        self::$calls = [];
        self::$invoices = [];
        self::$fail = false;
        self::$failInvoices = false;
    }

    public function createCustomer(string $email, string $name, array $metadata): string
    {
        $this->maybeFail();
        $id = 'cus_fake_' . (count(self::$createdCustomers) + 1) . '_' . implode('_', $metadata);
        self::$createdCustomers[] = $id;

        return $id;
    }

    public function createCheckoutSession(array $params): string
    {
        $this->maybeFail();
        self::$checkoutParams[] = $params;

        return 'https://checkout.stripe.test/session/' . count(self::$checkoutParams);
    }

    public function retrieveCheckoutSession(string $id): array
    {
        return self::$objects[$id] ?? throw new \RuntimeException("No fake object $id");
    }

    public function retrieveInvoice(string $id): array
    {
        return self::$objects[$id] ?? throw new \RuntimeException("No fake object $id");
    }

    public function retrieveSubscription(string $id, array $expand = []): array
    {
        $this->maybeFail();

        return self::$objects[$id] ?? throw new \RuntimeException("No fake object $id");
    }

    /**
     * Applies the few params StripeBilling sends, the way Stripe would: an
     * item with an id is changed (price, quantity) or deleted, one without
     * is added.
     */
    public function updateSubscription(string $id, array $params): array
    {
        $this->maybeFail();
        self::$calls[] = ['updateSubscription', $id, $params];
        $subscription = &self::$objects[$id];
        foreach ($params['items'] ?? [] as $item) {
            if (!isset($item['id'])) {
                $subscription['items']['data'][] = ['id' => 'si_added_' . count(self::$calls), 'price' => self::$objects[$item['price']] ?? ['id' => $item['price']], 'quantity' => $item['quantity'] ?? 1];
                continue;
            }
            foreach ($subscription['items']['data'] as $i => &$existing) {
                if ($existing['id'] !== $item['id']) {
                    continue;
                }
                if ($item['deleted'] ?? false) {
                    unset($subscription['items']['data'][$i]);
                } else {
                    if (isset($item['price'])) {
                        $existing['price'] = self::$objects[$item['price']] ?? ['id' => $item['price']];
                    }
                    if (isset($item['quantity'])) {
                        $existing['quantity'] = $item['quantity'];
                    }
                }
            }
            unset($existing);
            $subscription['items']['data'] = array_values($subscription['items']['data']);
        }
        if (array_key_exists('cancel_at_period_end', $params)) {
            $subscription['cancel_at_period_end'] = $params['cancel_at_period_end'];
            $subscription['cancel_at'] = $params['cancel_at_period_end'] ? $subscription['items']['data'][0]['current_period_end'] ?? 1 : null;
        }
        if (array_key_exists('cancel_at', $params)) {
            $subscription['cancel_at'] = $params['cancel_at'] === '' ? null : $params['cancel_at'];
        }
        if (isset($params['default_payment_method'])) {
            $subscription['default_payment_method'] = self::$objects[$params['default_payment_method']] ?? $params['default_payment_method'];
        }

        return $subscription;
    }

    public function retrieveCustomer(string $id): array
    {
        $this->maybeFail();

        return self::$objects[$id] ?? ['id' => $id, 'invoice_settings' => ['default_payment_method' => null]];
    }

    public function retrievePrice(string $id): array
    {
        $this->maybeFail();

        return self::$objects[$id] ?? throw new \RuntimeException("No fake object $id");
    }

    public function listInvoices(string $customerId, int $limit): array
    {
        if (self::$failInvoices) {
            throw new \RuntimeException('Fake Stripe invoices failure');
        }

        return array_slice(self::$invoices, 0, $limit);
    }

    public function retrieveSubscriptionSchedule(string $id): array
    {
        $this->maybeFail();

        return self::$objects[$id] ?? throw new \RuntimeException("No fake object $id");
    }

    /** Only from_subscription: one phase, the current period. */
    public function createSubscriptionSchedule(array $params): array
    {
        $this->maybeFail();
        self::$calls[] = ['createSubscriptionSchedule', '', $params];
        $subscription = &self::$objects[$params['from_subscription']];
        $item = $subscription['items']['data'][0];
        $id = 'sub_sched_' . count(self::$calls);
        $subscription['schedule'] = $id;

        return self::$objects[$id] = ['id' => $id, 'subscription' => $subscription['id'], 'phases' => [[
            'start_date' => $item['current_period_start'] ?? 0,
            'end_date'   => $item['current_period_end'] ?? 0,
            'items'      => [['price' => $item['price']['id'], 'quantity' => 1]],
        ]]];
    }

    public function updateSubscriptionSchedule(string $id, array $params): array
    {
        $this->maybeFail();
        self::$calls[] = ['updateSubscriptionSchedule', $id, $params];
        $phases = $params['phases'];
        // Stripe fills in a phase's start_date from the previous end_date.
        foreach ($phases as $i => &$phase) {
            $phase['start_date'] ??= $phases[$i - 1]['end_date'];
        }
        self::$objects[$id]['phases'] = $phases;

        return self::$objects[$id];
    }

    public function releaseSubscriptionSchedule(string $id): void
    {
        $this->maybeFail();
        self::$calls[] = ['releaseSubscriptionSchedule', $id, []];
        self::$objects[self::$objects[$id]['subscription']]['schedule'] = null;
    }

    public function createPortalSession(string $customerId, string $returnUrl, ?array $flowData = null): string
    {
        $this->maybeFail();
        self::$portalCustomers[] = $customerId;
        self::$portalReturnUrls[] = $returnUrl;
        self::$portalFlows[] = $flowData;

        return 'https://billing.stripe.test/portal/' . $customerId;
    }

    /** A subscription as Stripe would return it (only the fields StripeBilling reads). */
    public static function subscription(string $id, int $restaurantId, string $priceId, string $customer = 'cus_x'): array
    {
        return self::$objects[$id] = [
            'id' => $id,
            'customer' => $customer,
            'status' => 'active',
            'metadata' => ['restaurant_id' => (string) $restaurantId],
            'items' => ['data' => [['id' => 'si_' . $id, 'price' => self::$objects[$priceId] ?? ['id' => $priceId], 'quantity' => 1]]],
            'cancel_at_period_end' => false,
            'cancel_at' => null,
            'trial_end' => null,
            'schedule' => null,
            'default_payment_method' => null,
        ];
    }

    /** A reseller's subscription: the base plan, plus the extra-restaurants line when $extra > 0. */
    public static function resellerSubscription(string $id, int $resellerId, string $basePriceId, string $extraPriceId, int $extra, string $customer = 'cus_x'): array
    {
        self::subscription($id, 0, $basePriceId, $customer);
        self::$objects[$id]['metadata'] = ['reseller_id' => (string) $resellerId];
        if ($extra > 0) {
            self::$objects[$id]['items']['data'][] = ['id' => 'si_extra_' . $id, 'price' => self::$objects[$extraPriceId] ?? ['id' => $extraPriceId], 'quantity' => $extra];
        }

        return self::$objects[$id];
    }

    /** A recurring EUR price; store it before subscription() so the subscription embeds it. */
    public static function price(string $id, int $unitAmount, string $interval): array
    {
        return self::$objects[$id] = ['id' => $id, 'unit_amount' => $unitAmount, 'currency' => 'eur', 'recurring' => ['interval' => $interval]];
    }

    /** A card payment method; $created tells which one is newer. */
    public static function card(string $id, string $last4, int $created, string $brand = 'visa'): array
    {
        return self::$objects[$id] = ['id' => $id, 'created' => $created, 'card' => ['brand' => $brand, 'last4' => $last4, 'exp_month' => 4, 'exp_year' => 2030]];
    }

    /** A paid invoice for $subscriptionId covering up to $periodEnd. */
    public static function invoice(string $id, string $subscriptionId, int $periodEnd, int $amountPaid, int $paidAt, string $status = 'paid'): array
    {
        return self::$objects[$id] = [
            'id' => $id,
            'status' => $status,
            'amount_paid' => $amountPaid,
            'status_transitions' => ['paid_at' => $paidAt],
            'parent' => ['subscription_details' => ['subscription' => $subscriptionId]],
            'lines' => ['data' => [['period' => ['start' => $paidAt, 'end' => $periodEnd]]]],
        ];
    }

    private function maybeFail(): void
    {
        if (self::$fail) {
            throw new \RuntimeException('Fake Stripe failure');
        }
    }
}
