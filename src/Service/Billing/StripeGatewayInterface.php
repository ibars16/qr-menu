<?php

namespace App\Service\Billing;

/**
 * The few Stripe API calls StripeBilling needs, returning plain arrays (the
 * Stripe object's toArray()) so the billing logic can be tested with a fake
 * and never talks to Stripe in tests.
 */
interface StripeGatewayInterface
{
    /** @return string The new customer's id (cus_…). */
    public function createCustomer(string $email, string $name, int $restaurantId): string;

    /**
     * @param array<string, mixed> $params Stripe Checkout Session params
     * @return string The Checkout page URL
     */
    public function createCheckoutSession(array $params): string;

    /** @return array<string, mixed> */
    public function retrieveCheckoutSession(string $id): array;

    /** @return array<string, mixed> */
    public function retrieveInvoice(string $id): array;

    /**
     * @param list<string> $expand e.g. default_payment_method
     * @return array<string, mixed>
     */
    public function retrieveSubscription(string $id, array $expand = []): array;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function updateSubscription(string $id, array $params): array;

    /** @return array<string, mixed> With invoice_settings.default_payment_method expanded. */
    public function retrieveCustomer(string $id): array;

    /** @return array<string, mixed> */
    public function retrievePrice(string $id): array;

    /** @return list<array<string, mixed>> Newest first. */
    public function listInvoices(string $customerId, int $limit): array;

    /** @return array<string, mixed> */
    public function retrieveSubscriptionSchedule(string $id): array;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createSubscriptionSchedule(array $params): array;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function updateSubscriptionSchedule(string $id, array $params): array;

    /** Detaches the schedule; the subscription carries on as it is now. */
    public function releaseSubscriptionSchedule(string $id): void;

    /**
     * @param array<string, mixed>|null $flowData Stripe's flow_data (deep link), null = the portal's home
     * @return string The Customer Portal URL
     */
    public function createPortalSession(string $customerId, string $returnUrl, ?array $flowData = null): string;
}
