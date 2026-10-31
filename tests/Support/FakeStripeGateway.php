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

    public static bool $fail = false;

    public static function reset(): void
    {
        self::$objects = [];
        self::$checkoutParams = [];
        self::$createdCustomers = [];
        self::$portalCustomers = [];
        self::$portalReturnUrls = [];
        self::$fail = false;
    }

    public function createCustomer(string $email, string $name, int $restaurantId): string
    {
        $this->maybeFail();
        $id = 'cus_fake_' . (count(self::$createdCustomers) + 1) . '_' . $restaurantId;
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

    public function retrieveSubscription(string $id): array
    {
        return self::$objects[$id] ?? throw new \RuntimeException("No fake object $id");
    }

    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        $this->maybeFail();
        self::$portalCustomers[] = $customerId;
        self::$portalReturnUrls[] = $returnUrl;

        return 'https://billing.stripe.test/portal/' . $customerId;
    }

    /** A subscription as Stripe would return it (only the fields StripeBilling reads). */
    public static function subscription(string $id, int $restaurantId, string $priceId, string $customer = 'cus_x'): array
    {
        return self::$objects[$id] = [
            'id' => $id,
            'customer' => $customer,
            'metadata' => ['restaurant_id' => (string) $restaurantId],
            'items' => ['data' => [['price' => ['id' => $priceId]]]],
        ];
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
