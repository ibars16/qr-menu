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

    /** @return array<string, mixed> */
    public function retrieveSubscription(string $id): array;

    /** @return string The Customer Portal URL */
    public function createPortalSession(string $customerId, string $returnUrl): string;
}
